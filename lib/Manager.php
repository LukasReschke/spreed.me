<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2016 Joas Schilling <coding@schilljs.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Talk;


use OCA\Talk\Chat\SpecialRoom;
use OCA\Talk\Chat\CommentsManager;
use OCA\Talk\Events\CreateRoomTokenEvent;
use OCA\Talk\Events\RoomEvent;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\Comments\NotFoundException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;

class Manager {

	public const EVENT_TOKEN_GENERATE = self::class . '::generateNewToken';

	/** @var IDBConnection */
	private $db;
	/** @var IConfig */
	private $config;
	/** @var ISecureRandom */
	private $secureRandom;
	/** @var IUserManager */
	private $userManager;
	/** @var CommentsManager */
	private $commentsManager;
	/** @var TalkSession */
	private $talkSession;
	/** @var IEventDispatcher */
	private $dispatcher;
	/** @var ITimeFactory */
	protected $timeFactory;
	/** @var IHasher */
	private $hasher;
	/** @var IL10N */
	private $l;

	public function __construct(IDBConnection $db,
								IConfig $config,
								ISecureRandom $secureRandom,
								IUserManager $userManager,
								CommentsManager $commentsManager,
								TalkSession $talkSession,
								IEventDispatcher $dispatcher,
								ITimeFactory $timeFactory,
								IHasher $hasher,
								IL10N $l) {
		$this->db = $db;
		$this->config = $config;
		$this->secureRandom = $secureRandom;
		$this->userManager = $userManager;
		$this->commentsManager = $commentsManager;
		$this->talkSession = $talkSession;
		$this->dispatcher = $dispatcher;
		$this->timeFactory = $timeFactory;
		$this->hasher = $hasher;
		$this->l = $l;
	}

	public function forAllRooms(callable $callback): void {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('talk_rooms');

		$result = $query->execute();
		while ($row = $result->fetch()) {
			if ($row['token'] === null) {
				// FIXME Temporary solution for the Talk6 release
				continue;
			}

			$room = $this->createRoomObject($row);
			$callback($room);
		}
		$result->closeCursor();
	}

	/**
	 * @param array $row
	 * @return Room
	 */
	public function createRoomObject(array $row): Room {
		$activeSince = null;
		if (!empty($row['active_since'])) {
			$activeSince = $this->timeFactory->getDateTime($row['active_since']);
		}

		$lastActivity = null;
		if (!empty($row['last_activity'])) {
			$lastActivity = $this->timeFactory->getDateTime($row['last_activity']);
		}

		$lobbyTimer = null;
		if (!empty($row['lobby_timer'])) {
			$lobbyTimer = $this->timeFactory->getDateTime($row['lobby_timer']);
		}

		$lastMessage = null;
		if (!empty($row['comment_id'])) {
			$lastMessage = $this->commentsManager->getCommentFromData(array_merge($row, [
				'id' => $row['comment_id'],
				'object_type' => $row['comment_object_type'],
				'object_id' => $row['comment_object_id'],
				'parent_id' => '',
				'topmost_parent_id' => '',
				'latest_child_timestamp' => null,
				'children_count' => 0,
			]));
		}

		return new Room(
			$this,
			$this->db,
			$this->secureRandom,
			$this->dispatcher,
			$this->timeFactory,
			$this->hasher,
			(int) $row['id'],
			(int) $row['type'],
			(int) $row['read_only'],
			(int) $row['lobby_state'],
			(string) $row['token'],
			(string) $row['name'],
			(string) $row['password'],
			(int) $row['active_guests'],
			$activeSince,
			$lastActivity,
			(int) $row['last_message'],
			$lastMessage,
			$lobbyTimer,
			(string) $row['object_type'],
			(string) $row['object_id']
		);
	}

	/**
	 * @param Room $room
	 * @param array $row
	 * @return Participant
	 */
	public function createParticipantObject(Room $room, array $row): Participant {
		$lastJoinedCall = null;
		if (!empty($row['last_joined_call'])) {
			$lastJoinedCall = $this->timeFactory->getDateTime($row['last_joined_call']);
		}

		return new Participant(
			$this->db,
			$this->config,
			$room,
			(string) $row['user_id'],
			(int) $row['participant_type'],
			(int) $row['last_ping'],
			(string) $row['session_id'],
			(int) $row['in_call'],
			(int) $row['notification_level'],
			(bool) $row['favorite'],
			(int) $row['last_read_message'],
			(int) $row['last_mention_message'],
			$lastJoinedCall
		);
	}

	public function loadLastCommentInfo(int $id): ?IComment {
		try {
			return $this->commentsManager->get((string)$id);
		} catch (NotFoundException $e) {
			return null;
		}
	}

	/**
	 * @param string $participant
	 * @param bool $includeLastMessage
	 * @return Room[]
	 */
	public function getRoomsForParticipant(string $participant, bool $includeLastMessage = false): array {
		$query = $this->db->getQueryBuilder();
		$query->select('r.*')->addSelect('p.*')
			->from('talk_rooms', 'r')
			->leftJoin('r', 'talk_participants', 'p', $query->expr()->andX(
				$query->expr()->eq('p.user_id', $query->createNamedParameter($participant)),
				$query->expr()->eq('p.room_id', 'r.id')
			))
			->where($query->expr()->isNotNull('p.user_id'));

		if ($includeLastMessage) {
			$this->loadLastMessageInfo($query);
		}

		$result = $query->execute();
		$rooms = [];
		while ($row = $result->fetch()) {
			if ($row['token'] === null) {
				// FIXME Temporary solution for the Talk6 release
				continue;
			}

			$room = $this->createRoomObject($row);
			if ($participant !== null && isset($row['user_id'])) {
				$room->setParticipant($row['user_id'], $this->createParticipantObject($room, $row));
			}
			$rooms[] = $room;
		}
		$result->closeCursor();

		return $rooms;
	}

	/**
	 * Does *not* return public rooms for participants that have not been invited
	 *
	 * @param int $roomId
	 * @param string $participant
	 * @return Room
	 * @throws RoomNotFoundException
	 */
	public function getRoomForParticipant(int $roomId, ?string $participant): Room {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('talk_rooms', 'r')
			->where($query->expr()->eq('r.id', $query->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));

		if ($participant !== null) {
			// Non guest user
			$query->leftJoin('r', 'talk_participants', 'p', $query->expr()->andX(
					$query->expr()->eq('p.user_id', $query->createNamedParameter($participant)),
					$query->expr()->eq('p.room_id', 'r.id')
				))
				->andWhere($query->expr()->isNotNull('p.user_id'));
		}

		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			throw new RoomNotFoundException();
		}

		if ($row['token'] === null) {
			// FIXME Temporary solution for the Talk6 release
			throw new RoomNotFoundException();
		}

		$room = $this->createRoomObject($row);
		if ($participant !== null && isset($row['user_id'])) {
			$room->setParticipant($row['user_id'], $this->createParticipantObject($room, $row));
		}

		if ($participant === null && $room->getType() !== Room::PUBLIC_CALL) {
			throw new RoomNotFoundException();
		}

		return $room;
	}

	/**
	 * Also returns public rooms for participants that have not been invited,
	 * so they can join.
	 *
	 * @param string $token
	 * @param string $participant
	 * @param bool $includeLastMessage
	 * @return Room
	 * @throws RoomNotFoundException
	 */
	public function getRoomForParticipantByToken(string $token, ?string $participant, bool $includeLastMessage = false): Room {
		$query = $this->db->getQueryBuilder();
		$query->select('r.*')
			->from('talk_rooms', 'r')
			->where($query->expr()->eq('r.token', $query->createNamedParameter($token)))
			->setMaxResults(1);

		if ($participant !== null) {
			// Non guest user
			$query->addSelect('p.*')
				->leftJoin('r', 'talk_participants', 'p', $query->expr()->andX(
					$query->expr()->eq('p.user_id', $query->createNamedParameter($participant)),
					$query->expr()->eq('p.room_id', 'r.id')
				));
		}

		if ($includeLastMessage) {
			$this->loadLastMessageInfo($query);
		}

		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			throw new RoomNotFoundException();
		}

		if ($row['token'] === null) {
			// FIXME Temporary solution for the Talk6 release
			throw new RoomNotFoundException();
		}

		$room = $this->createRoomObject($row);
		if ($participant !== null && isset($row['user_id'])) {
			$room->setParticipant($row['user_id'], $this->createParticipantObject($room, $row));
		}

		if ($room->getType() === Room::PUBLIC_CALL) {
			return $room;
		}

		if ($participant !== null && $row['user_id'] === $participant) {
			return $room;
		}

		throw new RoomNotFoundException();
	}

	/**
	 * @param int $roomId
	 * @return Room
	 * @throws RoomNotFoundException
	 */
	public function getRoomById(int $roomId): Room {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('talk_rooms')
			->where($query->expr()->eq('id', $query->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));

		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			throw new RoomNotFoundException();
		}

		if ($row['token'] === null) {
			// FIXME Temporary solution for the Talk6 release
			throw new RoomNotFoundException();
		}

		return $this->createRoomObject($row);
	}

	/**
	 * @param string $token
	 * @return Room
	 * @throws RoomNotFoundException
	 */
	public function getRoomByToken(string $token): Room {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('talk_rooms')
			->where($query->expr()->eq('token', $query->createNamedParameter($token)));

		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			throw new RoomNotFoundException();
		}

		if ($row['token'] === null) {
			// FIXME Temporary solution for the Talk6 release
			throw new RoomNotFoundException();
		}

		return $this->createRoomObject($row);
	}

	/**
	 * @param string $objectType
	 * @param string $objectId
	 * @return Room
	 * @throws RoomNotFoundException
	 */
	public function getRoomByObject(string $objectType, string $objectId): Room {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('talk_rooms')
			->where($query->expr()->eq('object_type', $query->createNamedParameter($objectType)))
			->andWhere($query->expr()->eq('object_id', $query->createNamedParameter($objectId)));

		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			throw new RoomNotFoundException();
		}

		if ($row['token'] === null) {
			// FIXME Temporary solution for the Talk6 release
			throw new RoomNotFoundException();
		}

		return $this->createRoomObject($row);
	}

	/**
	 * @param string|null $userId
	 * @param string|null $sessionId
	 * @return Room
	 * @throws RoomNotFoundException
	 */
	public function getRoomForSession(?string $userId, ?string $sessionId): Room {
		if ($sessionId === '' || $sessionId === '0') {
			throw new RoomNotFoundException();
		}

		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('talk_participants', 'p')
			->leftJoin('p', 'talk_rooms', 'r', $query->expr()->eq('p.room_id', 'r.id'))
			->where($query->expr()->eq('p.session_id', $query->createNamedParameter($sessionId)))
			->setMaxResults(1);

		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false || !$row['id']) {
			throw new RoomNotFoundException();
		}

		if ((string) $userId !== $row['user_id']) {
			throw new RoomNotFoundException();
		}

		if ($row['token'] === null) {
			// FIXME Temporary solution for the Talk6 release
			throw new RoomNotFoundException();
		}

		$room = $this->createRoomObject($row);
		$participant = $this->createParticipantObject($room, $row);
		$room->setParticipant($row['user_id'], $participant);

		if ($room->getType() === Room::PUBLIC_CALL || !in_array($participant->getParticipantType(), [Participant::GUEST, Participant::USER_SELF_JOINED], true)) {
			return $room;
		}

		throw new RoomNotFoundException();
	}

	/**
	 * @param string $participant1
	 * @param string $participant2
	 * @return Room
	 * @throws RoomNotFoundException
	 */
	public function getOne2OneRoom(string $participant1, string $participant2): Room {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('talk_rooms', 'r')
			->leftJoin('r', 'talk_participants', 'p1', $query->expr()->andX(
				$query->expr()->eq('p1.user_id', $query->createNamedParameter($participant1)),
				$query->expr()->eq('p1.room_id', 'r.id')
			))
			->leftJoin('r', 'talk_participants', 'p2', $query->expr()->andX(
				$query->expr()->eq('p2.user_id', $query->createNamedParameter($participant2)),
				$query->expr()->eq('p2.room_id', 'r.id')
			))
			->where($query->expr()->eq('r.type', $query->createNamedParameter(Room::ONE_TO_ONE_CALL, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->orX(
				$query->expr()->andX(
					$query->expr()->isNotNull('p1.user_id'),
					$query->expr()->isNotNull('p2.user_id')
				),
				$query->expr()->andX(
					$query->expr()->eq('r.name', $query->createNamedParameter($participant1)),
					$query->expr()->isNotNull('p2.user_id')
				),
				$query->expr()->andX(
					$query->expr()->isNotNull('p1.user_id'),
					$query->expr()->eq('r.name', $query->createNamedParameter($participant2))
				)
			));

		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			throw new RoomNotFoundException();
		}

		if ($row['token'] === null) {
			// FIXME Temporary solution for the Talk6 release
			throw new RoomNotFoundException();
		}

		return $this->createRoomObject($row);
	}

	/**
	 * @param string $objectType
	 * @param string $objectId
	 * @return Room
	 */
	public function createOne2OneRoom(string $objectType = '', string $objectId = ''): Room {
		return $this->createRoom(Room::ONE_TO_ONE_CALL, '', $objectType, $objectId);
	}

	/**
	 * @param string $name
	 * @param string $objectType
	 * @param string $objectId
	 * @return Room
	 */
	public function createGroupRoom(string $name = '', string $objectType = '', string $objectId = ''): Room {
		return $this->createRoom(Room::GROUP_CALL, $name, $objectType, $objectId);
	}

	/**
	 * @param string $name
	 * @param string $objectType
	 * @param string $objectId
	 * @return Room
	 */
	public function createPublicRoom(string $name = '', string $objectType = '', string $objectId = ''): Room {
		return $this->createRoom(Room::PUBLIC_CALL, $name, $objectType, $objectId);
	}

	/**
	 *
	 * @param string $userId
	 * @param int type
	 * @param bool $createIfMissing
	 * @return Room
	 */
	public function getSpecialRoom(string $userId, int $type, bool $createIfMissing = true): Room {
		if ($type !== Room::CHANGELOG_CONVERSATION && $type !== Room::NOTES_CONVERSATION) {
			throw new \OutOfBoundsException('Unsupported type');
		}

		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('talk_rooms')
			->where($query->expr()->eq('type', $query->createNamedParameter($type, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('name', $query->createNamedParameter($userId)));

		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			if (!$createIfMissing) {
				throw new RoomNotFoundException('Conversation does not exist');
			}
			$room = $this->createRoom($type, $userId);
			if ($type === ROOM::NOTES_CONVERSATION) {
				$room->addUsers([
					'userId' => $userId,
					'participantType' => Participant::OWNER,
				]);
				$room->setReadOnly(Room::READ_ONLY);
			} else {
				$room->addUsers(['userId' => $userId]);
				if ($type === ROOM::CHANGELOG_CONVERSATION) {
					$room->setReadOnly(Room::READ_ONLY);
				}
			}
			return $room;
		}

		$room = $this->createRoomObject($row);

		try {
			$room->getParticipant($userId);
		} catch (ParticipantNotFoundException $e) {
			$room->addUsers(['userId' => $userId]);
		}

		return $room;
	}

	/**
	 * @param int $type
	 * @param string $name
	 * @param string $objectType
	 * @param string $objectId
	 * @return Room
	 */
	private function createRoom(int $type, string $name = '', string $objectType = '', string $objectId = ''): Room {
		$token = $this->getNewToken();

		$query = $this->db->getQueryBuilder();
		$query->insert('talk_rooms')
			->values(
				[
					'name' => $query->createNamedParameter($name),
					'type' => $query->createNamedParameter($type, IQueryBuilder::PARAM_INT),
					'token' => $query->createNamedParameter($token),
				]
			);

		if (!empty($objectType) && !empty($objectId)) {
			$query->setValue('object_type', $query->createNamedParameter($objectType))
				->setValue('object_id', $query->createNamedParameter($objectId));
		}

		$query->execute();
		$roomId = $query->getLastInsertId();

		$room = $this->getRoomById($roomId);

		$event = new RoomEvent($room);
		$this->dispatcher->dispatch(Room::EVENT_AFTER_ROOM_CREATE, $event);

		return $room;
	}

	/**
	 * @param string|null $userId
	 * @return string|null
	 */
	public function getCurrentSessionId(?string $userId): ?string {
		if (empty($userId)) {
			return null;
		}

		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('talk_participants')
			->where($query->expr()->eq('user_id', $query->createNamedParameter($userId)))
			->andWhere($query->expr()->neq('session_id', $query->createNamedParameter('0')))
			->orderBy('last_ping', 'DESC')
			->setMaxResults(1);
		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			return null;
		}

		return $row['session_id'];
	}

	/**
	 * @param string $userId
	 * @return string[]
	 */
	public function getSessionIdsForUser(?string $userId): array {
		if (!is_string($userId) || $userId === '') {
			// No deleting messages for guests
			return [];
		}

		// Delete all messages from or to the current user
		$query = $this->db->getQueryBuilder();
		$query->select('session_id')
			->from('talk_participants')
			->where($query->expr()->eq('user_id', $query->createNamedParameter($userId)));
		$result = $query->execute();

		$sessionIds = [];
		while ($row = $result->fetch()) {
			if ($row['session_id'] !== '0') {
				$sessionIds[] = $row['session_id'];
			}
		}
		$result->closeCursor();

		return $sessionIds;
	}

	public function resolveRoomDisplayName(Room $room, string $userId): string {
		if ($room->getObjectType() === 'share:password') {
			return $this->l->t('Password request: %s', [$room->getName()]);
		}
		if ($room->getType() === Room::CHANGELOG_CONVERSATION) {
			return $this->l->t('Talk updates ✅');
		}

		if ($room->getType() === Room::NOTES_CONVERSATION) {
			return $this->l->t('My notes');
		}

		if ($userId === '' && $room->getType() !== Room::PUBLIC_CALL) {
			return $this->l->t('Private conversation');
		}

		if ($room->getType() !== Room::ONE_TO_ONE_CALL && $room->getName() === '') {
			$room->setName($this->getRoomNameByParticipants($room));
		}

		// Set the room name to the other participant for one-to-one rooms
		if ($room->getType() === Room::ONE_TO_ONE_CALL) {
			if ($userId === '') {
				return $this->l->t('Private conversation');
			}

			$users = $room->getParticipantUserIds();
			$otherParticipant = '';
			$userIsParticipant = false;

			foreach ($users as $participantId) {
				if ($participantId !== $userId) {
					$user = $this->userManager->get($participantId);
					$otherParticipant = $user instanceof IUser ? $user->getDisplayName() : $participantId;
				} else {
					$userIsParticipant = true;
				}
			}

			if (!$userIsParticipant) {
				// Do not leak the name of rooms the user is not a part of
				return $this->l->t('Private conversation');
			}

			if ($otherParticipant === '' && $room->getName() !== '') {
				$user = $this->userManager->get($room->getName());
				$otherParticipant = $user instanceof IUser ? $user->getDisplayName() : $this->l->t('Deleted user (%s)', $room->getName());
			}

			return $otherParticipant;
		}

		try {
			if ($userId === '') {
				$sessionId = $this->talkSession->getSessionForRoom($room->getToken());
				$room->getParticipantBySession($sessionId);
			} else {
				$room->getParticipant($userId);
			}
		} catch (ParticipantNotFoundException $e) {
			// Do not leak the name of rooms the user is not a part of
			return $this->l->t('Private conversation');
		}

		return $room->getName();
	}

	protected function getRoomNameByParticipants(Room $room): string {
		$users = $room->getParticipantUserIds();
		$displayNames = [];

		foreach ($users as $participantId) {
			$user = $this->userManager->get($participantId);
			$displayNames[] = $user instanceof IUser ? $user->getDisplayName() : $participantId;
		}

		$roomName = implode(', ', $displayNames);
		if (strlen($roomName) > 128) {
			$roomName = substr($roomName, 120) . '…';
		}
		return $roomName;
	}

	/**
	 * @return string
	 */
	protected function getNewToken(): string {
		$chars = str_replace(['l', '0', '1'], '', ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
		$entropy = (int) $this->config->getAppValue('spreed', 'token_entropy', 8);
		$entropy = min(8, $entropy); // For update cases

		$query = $this->db->getQueryBuilder();
		$query->select('id')
			->from('talk_rooms')
			->where($query->expr()->eq('token', $query->createParameter('token')));

		$i = 0;
		while ($i < 1000) {
			try {
				$token = $this->generateNewToken($query, $entropy, $chars);
				if (\in_array($token, ['settings', 'backend'], true)) {
					throw new \OutOfBoundsException('Reserved word');
				}
				return $token;
			} catch (\OutOfBoundsException $e) {
				$i++;
				if ($entropy >= 30 || $i >= 999) {
					// Max entropy of 30
					$i = 0;
				}
			}
		}

		$entropy++;
		$this->config->setAppValue('spreed', 'token_entropy', $entropy);
		return $this->generateNewToken($query, $entropy, $chars);
	}

	/**
	 * @param IQueryBuilder $query
	 * @param int $entropy
	 * @param string $chars
	 * @return string
	 * @throws \OutOfBoundsException
	 */
	protected function generateNewToken(IQueryBuilder $query, int $entropy, string $chars): string {

		$event = new CreateRoomTokenEvent($entropy, $chars);
		$this->dispatcher->dispatch(self::EVENT_TOKEN_GENERATE, $event);
		try {
			$token = $event->getToken();
			if ($token === '') {
				// Will generate default token below.
				throw new \InvalidArgumentException('token may not be empty');
			}
		} catch (\InvalidArgumentException $e) {
			$token = $this->secureRandom->generate($entropy, $chars);
		}

		$query->setParameter('token', $token);
		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		if (is_array($row)) {
			// Token already in use
			throw new \OutOfBoundsException();
		}
		return $token;
	}

	public function isValidParticipant(string $userId): bool {
		return $this->userManager->userExists($userId);
	}

	protected function loadLastMessageInfo(IQueryBuilder $query): void {
		$query->leftJoin('r','comments', 'c', $query->expr()->eq('r.last_message', 'c.id'));
		$query->selectAlias('c.id', 'comment_id');
		$query->addSelect('c.actor_id', 'c.actor_type', 'c.message', 'c.creation_timestamp', 'c.verb');
		$query->selectAlias('c.object_type', 'comment_object_type');
		$query->selectAlias('c.object_id', 'comment_object_id');
	}
}
