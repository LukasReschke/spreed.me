<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2016 Lukas Reschke <lukas@statuscode.ch>
 * @copyright Copyright (c) 2016 Joas Schilling <coding@schilljs.com>
 *
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Joas Schilling <coding@schilljs.com>
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

use OCA\Talk\Events\AddParticipantsEvent;
use OCA\Talk\Events\JoinRoomGuestEvent;
use OCA\Talk\Events\JoinRoomUserEvent;
use OCA\Talk\Events\ModifyLobbyEvent;
use OCA\Talk\Events\ModifyParticipantEvent;
use OCA\Talk\Events\ModifyRoomEvent;
use OCA\Talk\Events\ParticipantEvent;
use OCA\Talk\Events\RemoveParticipantEvent;
use OCA\Talk\Events\RemoveUserEvent;
use OCA\Talk\Events\RoomEvent;
use OCA\Talk\Events\SignalingRoomPropertiesEvent;
use OCA\Talk\Events\VerifyRoomPasswordEvent;
use OCA\Talk\Exceptions\InvalidPasswordException;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Exceptions\UnauthorizedException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;

class Room {
	public const UNKNOWN_CALL = -1;
	public const ONE_TO_ONE_CALL = 1;
	public const GROUP_CALL = 2;
	public const PUBLIC_CALL = 3;
	public const CHANGELOG_CONVERSATION = 4;
	public const NOTES_CONVERSATION = 5;

	public const READ_WRITE = 0;
	public const READ_ONLY = 1;

	public const START_CALL_EVERYONE = 0;
	public const START_CALL_USERS = 1;
	public const START_CALL_MODERATORS = 2;

	public const PARTICIPANT_REMOVED = 'remove';
	public const PARTICIPANT_LEFT = 'leave';

	public const EVENT_AFTER_ROOM_CREATE = self::class . '::createdRoom';
	public const EVENT_BEFORE_ROOM_DELETE = self::class . '::preDeleteRoom';
	public const EVENT_AFTER_ROOM_DELETE = self::class . '::postDeleteRoom';
	public const EVENT_BEFORE_NAME_SET = self::class . '::preSetName';
	public const EVENT_AFTER_NAME_SET = self::class . '::postSetName';
	public const EVENT_BEFORE_PASSWORD_SET = self::class . '::preSetPassword';
	public const EVENT_AFTER_PASSWORD_SET = self::class . '::postSetPassword';
	public const EVENT_BEFORE_TYPE_SET = self::class . '::preSetType';
	public const EVENT_AFTER_TYPE_SET = self::class . '::postSetType';
	public const EVENT_BEFORE_READONLY_SET = self::class . '::preSetReadOnly';
	public const EVENT_AFTER_READONLY_SET = self::class . '::postSetReadOnly';
	public const EVENT_BEFORE_LOBBY_STATE_SET = self::class . '::preSetLobbyState';
	public const EVENT_AFTER_LOBBY_STATE_SET = self::class . '::postSetLobbyState';
	public const EVENT_BEFORE_USERS_ADD = self::class . '::preAddUsers';
	public const EVENT_AFTER_USERS_ADD = self::class . '::postAddUsers';
	public const EVENT_BEFORE_PARTICIPANT_TYPE_SET = self::class . '::preSetParticipantType';
	public const EVENT_AFTER_PARTICIPANT_TYPE_SET = self::class . '::postSetParticipantType';
	public const EVENT_BEFORE_USER_REMOVE = self::class . '::preRemoveUser';
	public const EVENT_AFTER_USER_REMOVE = self::class . '::postRemoveUser';
	public const EVENT_BEFORE_PARTICIPANT_REMOVE = self::class . '::preRemoveBySession';
	public const EVENT_AFTER_PARTICIPANT_REMOVE = self::class . '::postRemoveBySession';
	public const EVENT_BEFORE_ROOM_CONNECT = self::class . '::preJoinRoom';
	public const EVENT_AFTER_ROOM_CONNECT = self::class . '::postJoinRoom';
	public const EVENT_BEFORE_ROOM_DISCONNECT = self::class . '::preUserDisconnectRoom';
	public const EVENT_AFTER_ROOM_DISCONNECT = self::class . '::postUserDisconnectRoom';
	public const EVENT_BEFORE_GUEST_CONNECT = self::class . '::preJoinRoomGuest';
	public const EVENT_AFTER_GUEST_CONNECT = self::class . '::postJoinRoomGuest';
	public const EVENT_PASSWORD_VERIFY = self::class . '::verifyPassword';
	public const EVENT_BEFORE_GUESTS_CLEAN = self::class . '::preCleanGuests';
	public const EVENT_AFTER_GUESTS_CLEAN = self::class . '::postCleanGuests';
	public const EVENT_BEFORE_SESSION_JOIN_CALL = self::class . '::preSessionJoinCall';
	public const EVENT_AFTER_SESSION_JOIN_CALL = self::class . '::postSessionJoinCall';
	public const EVENT_BEFORE_SESSION_LEAVE_CALL = self::class . '::preSessionLeaveCall';
	public const EVENT_AFTER_SESSION_LEAVE_CALL = self::class . '::postSessionLeaveCall';
	public const EVENT_BEFORE_SIGNALING_PROPERTIES = self::class . '::beforeSignalingProperties';

	/** @var Manager */
	private $manager;
	/** @var IDBConnection */
	private $db;
	/** @var ISecureRandom */
	private $secureRandom;
	/** @var IEventDispatcher */
	private $dispatcher;
	/** @var ITimeFactory */
	private $timeFactory;
	/** @var IHasher */
	private $hasher;

	/** @var int */
	private $id;
	/** @var int */
	private $type;
	/** @var int */
	private $readOnly;
	/** @var int */
	private $lobbyState;
	/** @var \DateTime|null */
	private $lobbyTimer;
	/** @var string */
	private $token;
	/** @var string */
	private $name;
	/** @var string */
	private $password;
	/** @var int */
	private $activeGuests;
	/** @var \DateTime|null */
	private $activeSince;
	/** @var \DateTime|null */
	private $lastActivity;
	/** @var int */
	private $lastMessageId;
	/** @var IComment|null */
	private $lastMessage;
	/** @var string */
	private $objectType;
	/** @var string */
	private $objectId;

	/** @var string */
	protected $currentUser;
	/** @var Participant */
	protected $participant;

	public function __construct(Manager $manager,
								IDBConnection $db,
								ISecureRandom $secureRandom,
								IEventDispatcher $dispatcher,
								ITimeFactory $timeFactory,
								IHasher $hasher,
								int $id,
								int $type,
								int $readOnly,
								int $lobbyState,
								string $token,
								string $name,
								string $password,
								int $activeGuests,
								\DateTime $activeSince = null,
								\DateTime $lastActivity = null,
								int $lastMessageId,
								IComment $lastMessage = null,
								\DateTime $lobbyTimer = null,
								string $objectType = '',
								string $objectId = '') {
		$this->manager = $manager;
		$this->db = $db;
		$this->secureRandom = $secureRandom;
		$this->dispatcher = $dispatcher;
		$this->timeFactory = $timeFactory;
		$this->hasher = $hasher;
		$this->id = $id;
		$this->type = $type;
		$this->readOnly = $readOnly;
		$this->lobbyState = $lobbyState;
		$this->token = $token;
		$this->name = $name;
		$this->password = $password;
		$this->activeGuests = $activeGuests;
		$this->activeSince = $activeSince;
		$this->lastActivity = $lastActivity;
		$this->lastMessageId = $lastMessageId;
		$this->lastMessage = $lastMessage;
		$this->lobbyTimer = $lobbyTimer;
		$this->objectType = $objectType;
		$this->objectId = $objectId;
	}

	public function getId(): int {
		return $this->id;
	}

	public function getType(): int {
		return $this->type;
	}

	public function getReadOnly(): int {
		return $this->readOnly;
	}

	public function getLobbyState(): int {
		$this->validateTimer();
		return $this->lobbyState;
	}

	public function getLobbyTimer(): ?\DateTime {
		$this->validateTimer();
		return $this->lobbyTimer;
	}

	protected function validateTimer(): void {
		if ($this->lobbyTimer !== null && $this->lobbyTimer < $this->timeFactory->getDateTime()) {
			$this->setLobby(Webinary::LOBBY_NONE, null, true);
		}
	}

	public function getToken(): string {
		return $this->token;
	}

	public function getName(): string {
		return $this->name;
	}

	public function getDisplayName(string $userId): string {
		return $this->manager->resolveRoomDisplayName($this, $userId);
	}

	public function getActiveGuests(): int {
		return $this->activeGuests;
	}

	public function getActiveSince(): ?\DateTime {
		return $this->activeSince;
	}

	public function getLastActivity(): ?\DateTime {
		return $this->lastActivity;
	}

	public function getLastMessage(): ?IComment {
		if ($this->lastMessageId && $this->lastMessage === null) {
			$this->lastMessage = $this->manager->loadLastCommentInfo($this->lastMessageId);
			if ($this->lastMessage === null) {
				$this->lastMessageId = 0;
			}
		}

		return $this->lastMessage;
	}

	public function getObjectType(): string {
		return $this->objectType;
	}

	public function getObjectId(): string {
		return $this->objectId;
	}

	public function hasPassword(): bool {
		return $this->password !== '';
	}

	public function getPassword(): string {
		return $this->password;
	}

	public function setParticipant(?string $userId, Participant $participant): void {
		$this->currentUser = $userId;
		$this->participant = $participant;
	}

	/**
	 * Return the room properties to send to the signaling server.
	 *
	 * @param string $userId
	 * @return array
	 */
	public function getPropertiesForSignaling(string $userId): array {
		$properties = [
			'name' => $this->getDisplayName($userId),
			'type' => $this->getType(),
			'lobby-state' => $this->getLobbyState(),
			'lobby-timer' => $this->getLobbyTimer(),
			'read-only' => $this->getReadOnly(),
			'active-since' => $this->getActiveSince(),
		];

		$event = new SignalingRoomPropertiesEvent($this, $userId, $properties);
		$this->dispatcher->dispatch(self::EVENT_BEFORE_SIGNALING_PROPERTIES, $event);
		return $event->getProperties();
	}

	/**
	 * @param string|null $userId
	 * @return Participant
	 * @throws ParticipantNotFoundException When the user is not a participant
	 */
	public function getParticipant(?string $userId): Participant {
		if (!is_string($userId) || $userId === '') {
			throw new ParticipantNotFoundException('Not a user');
		}

		if ($this->currentUser === $userId && $this->participant instanceof Participant) {
			return $this->participant;
		}

		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('talk_participants')
			->where($query->expr()->eq('user_id', $query->createNamedParameter($userId)))
			->andWhere($query->expr()->eq('room_id', $query->createNamedParameter($this->getId())));
		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			throw new ParticipantNotFoundException('User is not a participant');
		}

		if ($this->currentUser === $userId) {
			$this->participant = $this->manager->createParticipantObject($this, $row);
			return $this->participant;
		}

		return $this->manager->createParticipantObject($this, $row);
	}

	/**
	 * @param string|null $sessionId
	 * @return Participant
	 * @throws ParticipantNotFoundException When the user is not a participant
	 */
	public function getParticipantBySession(?string $sessionId): Participant {
		if (!is_string($sessionId) || $sessionId === '' || $sessionId === '0') {
			throw new ParticipantNotFoundException('Not a user');
		}

		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('talk_participants')
			->where($query->expr()->eq('session_id', $query->createNamedParameter($sessionId)))
			->andWhere($query->expr()->eq('room_id', $query->createNamedParameter($this->getId())));
		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			throw new ParticipantNotFoundException('User is not a participant');
		}

		return $this->manager->createParticipantObject($this, $row);
	}

	public function deleteRoom(): void {
		$event = new RoomEvent($this);
		$this->dispatcher->dispatch(self::EVENT_BEFORE_ROOM_DELETE, $event);
		$query = $this->db->getQueryBuilder();

		// Delete all participants
		$query->delete('talk_participants')
			->where($query->expr()->eq('room_id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));
		$query->execute();

		// Delete room
		$query->delete('talk_rooms')
			->where($query->expr()->eq('id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));
		$query->execute();

		$this->dispatcher->dispatch(self::EVENT_AFTER_ROOM_DELETE, $event);
	}

	/**
	 * @param string $newName Currently it is only allowed to rename: self::GROUP_CALL, self::PUBLIC_CALL
	 * @return bool True when the change was valid, false otherwise
	 */
	public function setName(string $newName): bool {
		$oldName = $this->getName();
		if ($newName === $oldName) {
			return false;
		}

		$event = new ModifyRoomEvent($this, 'name', $newName, $oldName);
		$this->dispatcher->dispatch(self::EVENT_BEFORE_NAME_SET, $event);

		$query = $this->db->getQueryBuilder();
		$query->update('talk_rooms')
			->set('name', $query->createNamedParameter($newName))
			->where($query->expr()->eq('id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));
		$query->execute();
		$this->name = $newName;

		$this->dispatcher->dispatch(self::EVENT_AFTER_NAME_SET, $event);

		return true;
	}

	/**
	 * @param string $password Currently it is only allowed to have a password for Room::PUBLIC_CALL
	 * @return bool True when the change was valid, false otherwise
	 */
	public function setPassword(string $password): bool {
		if ($this->getType() !== self::PUBLIC_CALL) {
			return false;
		}

		$hash = $password !== '' ? $this->hasher->hash($password) : '';

		$event = new ModifyRoomEvent($this, 'password', $password);
		$this->dispatcher->dispatch(self::EVENT_BEFORE_PASSWORD_SET, $event);

		$query = $this->db->getQueryBuilder();
		$query->update('talk_rooms')
			->set('password', $query->createNamedParameter($hash))
			->where($query->expr()->eq('id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));
		$query->execute();
		$this->password = $hash;

		$this->dispatcher->dispatch(self::EVENT_AFTER_PASSWORD_SET, $event);

		return true;
	}

	/**
	 * @param \DateTime $now
	 * @return bool
	 */
	public function setLastActivity(\DateTime $now): bool {
		$query = $this->db->getQueryBuilder();
		$query->update('talk_rooms')
			->set('last_activity', $query->createNamedParameter($now, 'datetime'))
			->where($query->expr()->eq('id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));
		$query->execute();

		$this->lastActivity = $now;

		return true;
	}

	/**
	 * @param \DateTime $since
	 * @param bool $isGuest
	 * @return bool
	 */
	public function setActiveSince(\DateTime $since, bool $isGuest): bool {

		if ($isGuest && $this->getType() === self::PUBLIC_CALL) {
			$query = $this->db->getQueryBuilder();
			$query->update('talk_rooms')
				->set('active_guests', $query->createFunction($query->getColumnName('active_guests') . ' + 1'))
				->where($query->expr()->eq('id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));
			$query->execute();

			$this->activeGuests++;
		}

		if ($this->activeSince instanceof \DateTime) {
			return false;
		}

		$query = $this->db->getQueryBuilder();
		$query->update('talk_rooms')
			->set('active_since', $query->createNamedParameter($since, 'datetime'))
			->where($query->expr()->eq('id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->isNull('active_since'));
		$query->execute();

		$this->activeSince = $since;

		return true;
	}

	public function setLastMessage(IComment $message): void {
		$query = $this->db->getQueryBuilder();
		$query->update('talk_rooms')
			->set('last_message', $query->createNamedParameter((int) $message->getId()))
			->where($query->expr()->eq('id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));
		$query->execute();
	}

	public function resetActiveSince(): bool {
		$query = $this->db->getQueryBuilder();
		$query->update('talk_rooms')
			->set('active_guests', $query->createNamedParameter(0))
			->set('active_since', $query->createNamedParameter(null, 'datetime'))
			->where($query->expr()->eq('id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));

		$this->activeGuests = 0;
		$this->activeSince = null;

		return (bool) $query->execute();
	}

	/**
	 * @param int $newType Currently it is only allowed to change between `self::GROUP_CALL` and `self::PUBLIC_CALL`
	 * @return bool True when the change was valid, false otherwise
	 */
	public function setType(int $newType): bool {
		if ($newType === $this->getType()) {
			return true;
		}

		if ($this->getType() === self::ONE_TO_ONE_CALL) {
			return false;
		}

		if (!in_array($newType, [self::GROUP_CALL, self::PUBLIC_CALL], true)) {
			return false;
		}

		$oldType = $this->getType();

		$event = new ModifyRoomEvent($this, 'type', $newType, $oldType);
		$this->dispatcher->dispatch(self::EVENT_BEFORE_TYPE_SET, $event);

		$query = $this->db->getQueryBuilder();
		$query->update('talk_rooms')
			->set('type', $query->createNamedParameter($newType, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));
		$query->execute();

		$this->type = $newType;

		if ($oldType === self::PUBLIC_CALL) {
			// Kick all guests and users that were not invited
			$query = $this->db->getQueryBuilder();
			$query->delete('talk_participants')
				->where($query->expr()->eq('room_id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)))
				->andWhere($query->expr()->in('participant_type', $query->createNamedParameter([Participant::GUEST, Participant::USER_SELF_JOINED], IQueryBuilder::PARAM_INT_ARRAY)));
			$query->execute();
		}

		$this->dispatcher->dispatch(self::EVENT_AFTER_TYPE_SET, $event);

		return true;
	}

	/**
	 * @param int $newState Currently it is only allowed to change between
	 * 						`self::READ_ONLY` and `self::READ_WRITE`
	 * 						Also it's only allowed on rooms of type
	 * 						`self::GROUP_CALL` and `self::PUBLIC_CALL`
	 * @return bool True when the change was valid, false otherwise
	 */
	public function setReadOnly(int $newState): bool {
		$oldState = $this->getReadOnly();
		if ($newState === $oldState) {
			return true;
		}

		if (!in_array($this->getType(), [self::GROUP_CALL, self::PUBLIC_CALL, self::CHANGELOG_CONVERSATION], true)) {
			return false;
		}

		if (!in_array($newState, [self::READ_ONLY, self::READ_WRITE], true)) {
			return false;
		}

		$event = new ModifyRoomEvent($this, 'readOnly', $newState, $oldState);
		$this->dispatcher->dispatch(self::EVENT_BEFORE_READONLY_SET, $event);

		$query = $this->db->getQueryBuilder();
		$query->update('talk_rooms')
			->set('read_only', $query->createNamedParameter($newState, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));
		$query->execute();

		$this->readOnly = $newState;

		$this->dispatcher->dispatch(self::EVENT_AFTER_READONLY_SET, $event);

		return true;
	}

	/**
	 * @param int $newState Currently it is only allowed to change between
	 * 						`Webinary::LOBBY_NON_MODERATORS` and `Webinary::LOBBY_NONE`
	 * 						Also it's not allowed in one-to-one conversations,
	 * 						file conversations and password request conversations.
	 * @param \DateTime|null $dateTime
	 * @param bool $timerReached
	 * @return bool True when the change was valid, false otherwise
	 */
	public function setLobby(int $newState, ?\DateTime $dateTime, bool $timerReached = false): bool {
		$oldState = $this->lobbyState;

		if (!in_array($this->getType(), [self::GROUP_CALL, self::PUBLIC_CALL], true)) {
			return false;
		}

		if ($this->getObjectType() !== '') {
			return false;
		}

		if (!in_array($newState, [Webinary::LOBBY_NON_MODERATORS, Webinary::LOBBY_NONE], true)) {
			return false;
		}

		$event = new ModifyLobbyEvent($this, 'lobby', $newState, $oldState, $dateTime, $timerReached);
		$this->dispatcher->dispatch(self::EVENT_BEFORE_LOBBY_STATE_SET, $event);

		$query = $this->db->getQueryBuilder();
		$query->update('talk_rooms')
			->set('lobby_state', $query->createNamedParameter($newState, IQueryBuilder::PARAM_INT))
			->set('lobby_timer', $query->createNamedParameter($dateTime, IQueryBuilder::PARAM_DATE))
			->where($query->expr()->eq('id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));
		$query->execute();

		$this->lobbyState = $newState;

		$this->dispatcher->dispatch(self::EVENT_AFTER_LOBBY_STATE_SET, $event);

		if ($newState === Webinary::LOBBY_NON_MODERATORS) {
			$participants = $this->getParticipantsInCall();
			foreach ($participants as $participant) {
				if ($participant->hasModeratorPermissions()) {
					continue;
				}

				$this->changeInCall($participant, Participant::FLAG_DISCONNECTED);
			}
		}

		return true;
	}

	public function ensureOneToOneRoomIsFilled(): void {
		if ($this->getType() !== self::ONE_TO_ONE_CALL) {
			return;
		}

		if ($this->getName() === '') {
			return;
		}

		if ($this->manager->isValidParticipant($this->getName())) {
			$this->addUsers([
				'userId' => $this->getName(),
				'participantType' => Participant::OWNER,
			]);
		}

		$this->setName('');
	}

	/**
	 * @param array ...$participants
	 */
	public function addUsers(array ...$participants): void {
		$event = new AddParticipantsEvent($this, $participants);
		$this->dispatcher->dispatch(self::EVENT_BEFORE_USERS_ADD, $event);

		$lastMessage = 0;
		if ($this->getLastMessage() instanceof IComment) {
			$lastMessage = (int) $this->getLastMessage()->getId();
		}

		$query = $this->db->getQueryBuilder();
		$query->insert('talk_participants')
			->values(
				[
					'user_id' => $query->createParameter('user_id'),
					'session_id' => $query->createParameter('session_id'),
					'participant_type' => $query->createParameter('participant_type'),
					'room_id' => $query->createNamedParameter($this->getId()),
					'last_ping' => $query->createNamedParameter(0, IQueryBuilder::PARAM_INT),
					'last_read_message' => $query->createNamedParameter($lastMessage, IQueryBuilder::PARAM_INT),
				]
			);

		foreach ($participants as $participant) {
			$query->setParameter('user_id', $participant['userId'])
				->setParameter('session_id', $participant['sessionId'] ?? '0')
				->setParameter('participant_type', $participant['participantType'] ?? Participant::USER, IQueryBuilder::PARAM_INT);

			$query->execute();
		}

		$this->dispatcher->dispatch(self::EVENT_AFTER_USERS_ADD, $event);
	}

	/**
	 * @param Participant $participant
	 * @param int $participantType
	 */
	public function setParticipantType(Participant $participant, int $participantType): void {
		$event = new ModifyParticipantEvent($this, $participant, 'type', $participantType, $participant->getParticipantType());
		$this->dispatcher->dispatch(self::EVENT_BEFORE_PARTICIPANT_TYPE_SET, $event);

		$query = $this->db->getQueryBuilder();
		$query->update('talk_participants')
			->set('participant_type', $query->createNamedParameter($participantType, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('room_id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('user_id', $query->createNamedParameter($participant->getUser())));

		if ($participant->getUser() === '') {
			$query->andWhere($query->expr()->eq('session_id', $query->createNamedParameter($participant->getSessionId())));
		}

		$query->execute();

		$this->dispatcher->dispatch(self::EVENT_AFTER_PARTICIPANT_TYPE_SET, $event);
	}

	/**
	 * @param IUser $user
	 * @param string $reason
	 */
	public function removeUser(IUser $user, string $reason): void {
		try {
			$participant = $this->getParticipant($user->getUID());
		} catch (ParticipantNotFoundException $e) {
			return;
		}

		$event = new RemoveUserEvent($this, $participant, $user, $reason);
		$this->dispatcher->dispatch(self::EVENT_BEFORE_USER_REMOVE, $event);

		if ($this->getType() === self::ONE_TO_ONE_CALL) {
			$this->setName($user->getUID());
		}

		$query = $this->db->getQueryBuilder();
		$query->delete('talk_participants')
			->where($query->expr()->eq('room_id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('user_id', $query->createNamedParameter($user->getUID())));
		$query->execute();

		$this->dispatcher->dispatch(self::EVENT_AFTER_USER_REMOVE, $event);

	}

	/**
	 * @param Participant $participant
	 * @param string $reason
	 */
	public function removeParticipantBySession(Participant $participant, string $reason): void {
		$event = new RemoveParticipantEvent($this, $participant, $reason);
		$this->dispatcher->dispatch(self::EVENT_BEFORE_PARTICIPANT_REMOVE, $event);

		$query = $this->db->getQueryBuilder();
		$query->delete('talk_participants')
			->where($query->expr()->eq('room_id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('session_id', $query->createNamedParameter($participant->getSessionId())));
		$query->execute();

		$this->dispatcher->dispatch(self::EVENT_AFTER_PARTICIPANT_REMOVE, $event);
	}

	/**
	 * @param IUser $user
	 * @param string $password
	 * @param bool $passedPasswordProtection
	 * @return string
	 * @throws InvalidPasswordException
	 * @throws UnauthorizedException
	 */
	public function joinRoom(IUser $user, string $password, bool $passedPasswordProtection = false): string {
		$event = new JoinRoomUserEvent($this, $user, $password, $passedPasswordProtection);
		$this->dispatcher->dispatch(self::EVENT_BEFORE_ROOM_CONNECT, $event);

		if ($event->getCancelJoin() === true) {
			$this->removeUser($user, self::PARTICIPANT_LEFT);
			throw new UnauthorizedException('Participant is not allowed to join');
		}

		$query = $this->db->getQueryBuilder();
		$query->update('talk_participants')
			->set('session_id', $query->createParameter('session_id'))
			->where($query->expr()->eq('room_id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('user_id', $query->createNamedParameter($user->getUID())));

		$sessionId = $this->secureRandom->generate(255);
		$query->setParameter('session_id', $sessionId);
		$result = $query->execute();

		if ($result === 0) {
			if (!$event->getPassedPasswordProtection() && !$this->verifyPassword($password)['result']) {
				throw new InvalidPasswordException();
			}

			// User joining a public room, without being invited
			$this->addUsers([
				'userId' => $user->getUID(),
				'participantType' => Participant::USER_SELF_JOINED,
				'sessionId' => $sessionId,
			]);
		}

		while (!$this->isSessionUnique($sessionId)) {
			$sessionId = $this->secureRandom->generate(255);
			$query->setParameter('session_id', $sessionId);
			$query->execute();
		}

		$this->dispatcher->dispatch(self::EVENT_AFTER_ROOM_CONNECT, $event);

		return $sessionId;
	}

	/**
	 * @param string $userId
	 * @param string|null $sessionId
	 */
	public function leaveRoom(string $userId, ?string $sessionId = null): void {
		try {
			$participant = $this->getParticipant($userId);
		} catch (ParticipantNotFoundException $e) {
			return;
		}

		$event = new ParticipantEvent($this, $participant);
		$this->dispatcher->dispatch(self::EVENT_BEFORE_ROOM_DISCONNECT, $event);

		// Reset session when leaving a normal room
		$query = $this->db->getQueryBuilder();
		$query->update('talk_participants')
			->set('session_id', $query->createNamedParameter('0'))
			->set('in_call', $query->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('user_id', $query->createNamedParameter($userId)))
			->andWhere($query->expr()->eq('room_id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->neq('participant_type', $query->createNamedParameter(Participant::USER_SELF_JOINED, IQueryBuilder::PARAM_INT)));
		if (!empty($sessionId)) {
			$query->andWhere($query->expr()->eq('session_id', $query->createNamedParameter($sessionId)));
		}
		$query->execute();

		// And kill session when leaving a self joined room
		$query = $this->db->getQueryBuilder();
		$query->delete('talk_participants')
			->where($query->expr()->eq('user_id', $query->createNamedParameter($userId)))
			->andWhere($query->expr()->eq('room_id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('participant_type', $query->createNamedParameter(Participant::USER_SELF_JOINED, IQueryBuilder::PARAM_INT)));
		if (!empty($sessionId)) {
			$query->andWhere($query->expr()->eq('session_id', $query->createNamedParameter($sessionId)));
		}
		$query->execute();

		$this->dispatcher->dispatch(self::EVENT_AFTER_ROOM_DISCONNECT, $event);
	}

	/**
	 * @param string $password
	 * @param bool $passedPasswordProtection
	 * @return string
	 * @throws InvalidPasswordException
	 * @throws UnauthorizedException
	 */
	public function joinRoomGuest(string $password, bool $passedPasswordProtection = false): string {
		$event = new JoinRoomGuestEvent($this, $password, $passedPasswordProtection);
		$this->dispatcher->dispatch(self::EVENT_BEFORE_GUEST_CONNECT, $event);

		if ($event->getCancelJoin()) {
			throw new UnauthorizedException('Participant is not allowed to join');
		}

		if (!$event->getPassedPasswordProtection() && !$this->verifyPassword($password)['result']) {
			throw new InvalidPasswordException();
		}

		$lastMessage = 0;
		if ($this->getLastMessage() instanceof IComment) {
			$lastMessage = (int) $this->getLastMessage()->getId();
		}

		$sessionId = $this->secureRandom->generate(255);
		while (!$this->db->insertIfNotExist('*PREFIX*talk_participants', [
			'user_id' => '',
			'room_id' => $this->getId(),
			'last_ping' => 0,
			'session_id' => $sessionId,
			'participant_type' => Participant::GUEST,
			'last_read_message' => $lastMessage,
		], ['session_id'])) {
			$sessionId = $this->secureRandom->generate(255);
		}

		$this->dispatcher->dispatch(self::EVENT_AFTER_GUEST_CONNECT, $event);

		return $sessionId;
	}

	public function changeInCall(Participant $participant, int $flags): void {
		$event = new ModifyParticipantEvent($this, $participant, 'inCall', $flags, $participant->getInCallFlags());
		if ($flags !== Participant::FLAG_DISCONNECTED) {
			$this->dispatcher->dispatch(self::EVENT_BEFORE_SESSION_JOIN_CALL, $event);
		} else {
			$this->dispatcher->dispatch(self::EVENT_BEFORE_SESSION_LEAVE_CALL, $event);
		}

		$query = $this->db->getQueryBuilder();
		$query->update('talk_participants')
			->set('in_call', $query->createNamedParameter($flags, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('session_id', $query->createNamedParameter($participant->getSessionId())))
			->andWhere($query->expr()->eq('room_id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));

		if ($flags !== Participant::FLAG_DISCONNECTED) {
			$query->set('last_joined_call', $query->createNamedParameter(
				$this->timeFactory->getDateTime(), IQueryBuilder::PARAM_DATE
			));
		}

		$query->execute();

		if ($flags !== Participant::FLAG_DISCONNECTED) {
			$this->dispatcher->dispatch(self::EVENT_AFTER_SESSION_JOIN_CALL, $event);
		} else {
			$this->dispatcher->dispatch(self::EVENT_AFTER_SESSION_LEAVE_CALL, $event);
		}
	}

	/**
	 * @param string $password
	 * @return array
	 */
	public function verifyPassword(string $password): array {
		$event = new VerifyRoomPasswordEvent($this, $password);
		$this->dispatcher->dispatch(self::EVENT_PASSWORD_VERIFY, $event);

		if ($event->isPasswordValid() !== null) {
			return [
				'result' => $event->isPasswordValid(),
				'url' => $event->getRedirectUrl(),
			];
		}

		return [
			'result' => !$this->hasPassword() || $this->hasher->verify($password, $this->password),
			'url' => '',
		];
	}

	/**
	 * @param string $sessionId
	 * @return bool
	 */
	protected function isSessionUnique(string $sessionId): bool {
		$query = $this->db->getQueryBuilder();
		$query->selectAlias($query->createFunction('COUNT(*)'), 'num_sessions')
			->from('talk_participants')
			->where($query->expr()->eq('session_id', $query->createNamedParameter($sessionId)));
		$result = $query->execute();
		$numSessions = (int) $result->fetchColumn();
		$result->closeCursor();

		return $numSessions === 1;
	}

	public function cleanGuestParticipants(): void {
		$event = new RoomEvent($this);
		$this->dispatcher->dispatch(self::EVENT_BEFORE_GUESTS_CLEAN, $event);

		$query = $this->db->getQueryBuilder();
		$query->delete('talk_participants')
			->where($query->expr()->eq('room_id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->emptyString('user_id'))
			->andWhere($query->expr()->lte('last_ping', $query->createNamedParameter($this->timeFactory->getTime() - 100, IQueryBuilder::PARAM_INT)));
		$query->execute();

		$this->dispatcher->dispatch(self::EVENT_AFTER_GUESTS_CLEAN, $event);
	}

	/**
	 * @param int $lastPing When the last ping is older than the given timestamp, the user is ignored
	 * @return Participant[]
	 */
	public function getParticipants(int $lastPing = 0): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('talk_participants')
			->where($query->expr()->eq('room_id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));

		if ($lastPing > 0) {
			$query->andWhere($query->expr()->gt('last_ping', $query->createNamedParameter($lastPing, IQueryBuilder::PARAM_INT)));
		}

		$result = $query->execute();

		$participants = [];
		while ($row = $result->fetch()) {
			$participants[] = $this->manager->createParticipantObject($this, $row);
		}
		$result->closeCursor();

		return $participants;
	}

	/**
	 * @return Participant[]
	 */
	public function getParticipantsInCall(): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('talk_participants')
			->where($query->expr()->eq('room_id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->neq('in_call', $query->createNamedParameter(Participant::FLAG_DISCONNECTED)));

		$result = $query->execute();

		$participants = [];
		while ($row = $result->fetch()) {
			$participants[] = $this->manager->createParticipantObject($this, $row);
		}
		$result->closeCursor();

		return $participants;
	}

	/**
	 * @param int $lastPing When the last ping is older than the given timestamp, the user is ignored
	 * @return array[] Array of users with [users => [userId => [lastPing, sessionId]], guests => [[lastPing, sessionId]]]
	 * @deprecated Use self::getParticipants() instead
	 */
	public function getParticipantsLegacy(int $lastPing = 0): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('talk_participants')
			->where($query->expr()->eq('room_id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));

		if ($lastPing > 0) {
			$query->andWhere($query->expr()->gt('last_ping', $query->createNamedParameter($lastPing, IQueryBuilder::PARAM_INT)));
		}

		$result = $query->execute();

		$users = $guests = [];
		while ($row = $result->fetch()) {
			if ($row['user_id'] !== '' && $row['user_id'] !== null) {
				$users[$row['user_id']] = [
					'inCall' => (int) $row['in_call'],
					'lastPing' => (int) $row['last_ping'],
					'sessionId' => $row['session_id'],
					'participantType' => (int) $row['participant_type'],
				];
			} else {
				$guests[] = [
					'inCall' => (int) $row['in_call'],
					'lastPing' => (int) $row['last_ping'],
					'participantType' => (int) $row['participant_type'],
					'sessionId' => $row['session_id'],
				];
			}
		}
		$result->closeCursor();

		return [
			'users' => $users,
			'guests' => $guests,
		];
	}

	/**
	 * @param null|\DateTime $maxLastJoined When the "last joined call" is older than the given DateTime, the user is ignored
	 * @return string[]
	 */
	public function getParticipantUserIds(\DateTime $maxLastJoined = null): array {
		$query = $this->db->getQueryBuilder();
		$query->select('user_id')
			->from('talk_participants')
			->where($query->expr()->eq('room_id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->nonEmptyString('user_id'));

		if ($maxLastJoined instanceof \DateTimeInterface) {
			$query->andWhere($query->expr()->gte('last_joined_call', $query->createNamedParameter($maxLastJoined, IQueryBuilder::PARAM_DATE)));
		}

		$result = $query->execute();

		$users = [];
		while ($row = $result->fetch()) {
			$users[] = $row['user_id'];
		}
		$result->closeCursor();

		return $users;
	}

	/**
	 * @param int $notificationLevel
	 * @return Participant[] Array of participants
	 */
	public function getParticipantsByNotificationLevel(int $notificationLevel): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('talk_participants')
			->where($query->expr()->eq('room_id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('notification_level', $query->createNamedParameter($notificationLevel, IQueryBuilder::PARAM_INT)));
		$result = $query->execute();

		$participants = [];
		while ($row = $result->fetch()) {
			$participants[] = $this->manager->createParticipantObject($this, $row);
		}
		$result->closeCursor();

		return $participants;
	}

	/**
	 * @return string[]
	 */
	public function getActiveSessions(): array {
		$query = $this->db->getQueryBuilder();
		$query->select('session_id')
			->from('talk_participants')
			->where($query->expr()->eq('room_id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->neq('session_id', $query->createNamedParameter('0')));
		$result = $query->execute();

		$sessions = [];
		while ($row = $result->fetch()) {
			$sessions[] = $row['session_id'];
		}
		$result->closeCursor();

		return $sessions;
	}

	/**
	 * Get all user ids which are participants in a room but currently not in the call
	 * @return string[]
	 */
	public function getNotInCallUserIds(): array {
		$query = $this->db->getQueryBuilder();
		$query->select('user_id')
			->from('talk_participants')
			->where($query->expr()->eq('room_id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->nonEmptyString('user_id'))
			->andWhere($query->expr()->eq('in_call', $query->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$result = $query->execute();

		$userIds = [];
		while ($row = $result->fetch()) {
			$userIds[] = $row['user_id'];
		}
		$result->closeCursor();

		return $userIds;
	}

	/**
	 * @return bool
	 */
	public function hasSessionsInCall(): bool {
		$query = $this->db->getQueryBuilder();
		$query->select('session_id')
			->from('talk_participants')
			->where($query->expr()->eq('room_id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->neq('in_call', $query->createNamedParameter(Participant::FLAG_DISCONNECTED, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		return (bool) $row;
	}

	public function getNumberOfModerators(bool $ignoreGuests = true): int {
		$types = [
			Participant::OWNER,
			Participant::MODERATOR,
		];
		if (!$ignoreGuests) {
			$types[] = Participant::GUEST_MODERATOR;
		}

		$query = $this->db->getQueryBuilder();
		$query->select($query->func()->count('*', 'num_moderators'))
			->from('talk_participants')
			->where($query->expr()->eq('room_id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->in('participant_type', $query->createNamedParameter($types, IQueryBuilder::PARAM_INT_ARRAY)));

		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		return (int) ($row['num_moderators'] ?? 0);
	}

	/**
	 * @param bool $ignoreGuests
	 * @param int $lastPing When the last ping is older than the given timestamp, the user is ignored
	 * @return int
	 */
	public function getNumberOfParticipants(bool $ignoreGuests = true, int $lastPing = 0): int {
		$query = $this->db->getQueryBuilder();
		$query->select($query->func()->count('*', 'num_participants'))
			->from('talk_participants')
			->where($query->expr()->eq('room_id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));

		if ($lastPing > 0) {
			$query->andWhere($query->expr()->gt('last_ping', $query->createNamedParameter($lastPing, IQueryBuilder::PARAM_INT)));
		}

		if ($ignoreGuests) {
			$query->andWhere($query->expr()->notIn('participant_type', $query->createNamedParameter([
				Participant::GUEST,
				Participant::GUEST_MODERATOR,
				Participant::USER_SELF_JOINED,
			], IQueryBuilder::PARAM_INT_ARRAY)));
		}

		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		return (int) ($row['num_participants'] ?? 0);
	}

	public function markUsersAsMentioned(array $userIds, int $messageId): void {
		$query = $this->db->getQueryBuilder();
		$query->update('talk_participants')
			->set('last_mention_message', $query->createNamedParameter($messageId, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('room_id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->in('user_id', $query->createNamedParameter($userIds, IQueryBuilder::PARAM_STR_ARRAY)));
		$query->execute();
	}

	/**
	 * @param string|null $userId
	 * @param string $sessionId
	 * @param int $timestamp
	 */
	public function ping(?string $userId, string $sessionId, int $timestamp): void {
		$query = $this->db->getQueryBuilder();
		$query->update('talk_participants')
			->set('last_ping', $query->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('user_id', $query->createNamedParameter((string) $userId)))
			->andWhere($query->expr()->eq('session_id', $query->createNamedParameter($sessionId)))
			->andWhere($query->expr()->eq('room_id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));

		$query->execute();
	}

	/**
	 * @param string[] $sessionIds
	 * @param int $timestamp
	 */
	public function pingSessionIds(array $sessionIds, int $timestamp): void {
		$query = $this->db->getQueryBuilder();
		$query->update('talk_participants')
			->set('last_ping', $query->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('room_id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->in('session_id', $query->createNamedParameter($sessionIds, IQueryBuilder::PARAM_STR_ARRAY)));

		$query->execute();
	}
}
