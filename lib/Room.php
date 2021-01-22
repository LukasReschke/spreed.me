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

use OCA\Talk\Events\ModifyAvatarEvent;
use OCA\Talk\Events\ModifyLobbyEvent;
use OCA\Talk\Events\ModifyRoomEvent;
use OCA\Talk\Events\RoomEvent;
use OCA\Talk\Events\SignalingRoomPropertiesEvent;
use OCA\Talk\Events\VerifyRoomPasswordEvent;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\SelectHelper;
use OCA\Talk\Service\ParticipantService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;

class Room {

	/**
	 * Regex that matches SIP incompatible rooms:
	 * 1. duplicate digit: …11…
	 * 2. leading zero: 0…
	 * 3. non-digit: …a…
	 */
	public const SIP_INCOMPATIBLE_REGEX = '/((\d)(?=\2+)|^0|\D)/';

	public const UNKNOWN_CALL = -1;
	public const ONE_TO_ONE_CALL = 1;
	public const GROUP_CALL = 2;
	public const PUBLIC_CALL = 3;
	public const CHANGELOG_CONVERSATION = 4;

	public const READ_WRITE = 0;
	public const READ_ONLY = 1;

	/**
	 * Only visible when joined
	 */
	public const LISTABLE_NONE = 0;

	/**
	 * Searchable by all regular users and moderators, even when not joined, excluding users from the guest app
	 */
	public const LISTABLE_USERS = 1;

	/**
	 * Searchable by everyone, which includes guest users (from guest app), even when not joined
	 */
	public const LISTABLE_ALL = 2;

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
	public const EVENT_BEFORE_DESCRIPTION_SET = self::class . '::preSetDescription';
	public const EVENT_AFTER_DESCRIPTION_SET = self::class . '::postSetDescription';
	public const EVENT_BEFORE_AVATAR_SET = self::class . '::preSetAvatar';
	public const EVENT_AFTER_AVATAR_SET = self::class . '::postSetAvatar';
	public const EVENT_BEFORE_PASSWORD_SET = self::class . '::preSetPassword';
	public const EVENT_AFTER_PASSWORD_SET = self::class . '::postSetPassword';
	public const EVENT_BEFORE_TYPE_SET = self::class . '::preSetType';
	public const EVENT_AFTER_TYPE_SET = self::class . '::postSetType';
	public const EVENT_BEFORE_READONLY_SET = self::class . '::preSetReadOnly';
	public const EVENT_AFTER_READONLY_SET = self::class . '::postSetReadOnly';
	public const EVENT_BEFORE_LISTABLE_SET = self::class . '::preSetListable';
	public const EVENT_AFTER_LISTABLE_SET = self::class . '::postSetListable';
	public const EVENT_BEFORE_LOBBY_STATE_SET = self::class . '::preSetLobbyState';
	public const EVENT_AFTER_LOBBY_STATE_SET = self::class . '::postSetLobbyState';
	public const EVENT_BEFORE_SIP_ENABLED_SET = self::class . '::preSetSIPEnabled';
	public const EVENT_AFTER_SIP_ENABLED_SET = self::class . '::postSetSIPEnabled';
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

	public const DESCRIPTION_MAXIMUM_LENGTH = 500;

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
	private $listable;
	/** @var int */
	private $lobbyState;
	/** @var int */
	private $sipEnabled;
	/** @var int|null */
	private $assignedSignalingServer;
	/** @var \DateTime|null */
	private $lobbyTimer;
	/** @var string */
	private $token;
	/** @var string */
	private $name;
	/** @var string */
	private $description;
	/** @var string */
	private $avatarId;
	/** @var int */
	private $avatarVersion;
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
	/** @var Participant|null */
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
								int $listable,
								int $lobbyState,
								int $sipEnabled,
								?int $assignedSignalingServer,
								string $token,
								string $name,
								string $description,
								string $avatarId,
								int $avatarVersion,
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
		$this->listable = $listable;
		$this->lobbyState = $lobbyState;
		$this->sipEnabled = $sipEnabled;
		$this->assignedSignalingServer = $assignedSignalingServer;
		$this->token = $token;
		$this->name = $name;
		$this->description = $description;
		$this->avatarId = $avatarId;
		$this->avatarVersion = $avatarVersion;
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

	public function getListable(): int {
		return $this->listable;
	}

	public function getLobbyState(): int {
		$this->validateTimer();
		return $this->lobbyState;
	}

	public function getSIPEnabled(): int {
		return $this->sipEnabled;
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

	public function getAssignedSignalingServer(): ?int {
		return $this->assignedSignalingServer;
	}

	public function getToken(): string {
		return $this->token;
	}

	public function getName(): string {
		if ($this->type === self::ONE_TO_ONE_CALL) {
			if ($this->name === '') {
				// TODO use DI
				$participantService = \OC::$server->get(ParticipantService::class);
				// Fill the room name with the participants for 1-to-1 conversations
				$users = $participantService->getParticipantUserIds($this);
				sort($users);
				$this->setName(json_encode($users), '');
			} elseif (strpos($this->name, '["') !== 0) {
				// TODO use DI
				$participantService = \OC::$server->get(ParticipantService::class);
				// Not the json array, but the old fallback when someone left
				$users = $participantService->getParticipantUserIds($this);
				if (count($users) !== 2) {
					$users[] = $this->name;
				}
				sort($users);
				$this->setName(json_encode($users), '');
			}
		}
		return $this->name;
	}

	public function getDisplayName(string $userId): string {
		return $this->manager->resolveRoomDisplayName($this, $userId);
	}

	public function getDescription(): string {
		return $this->description;
	}

	public function getAvatarId(): string {
		return $this->avatarId;
	}

	public function getAvatarVersion(): int {
		return $this->avatarVersion;
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
	 * @param bool $roomModified
	 * @return array
	 */
	public function getPropertiesForSignaling(string $userId, bool $roomModified = true): array {
		$properties = [
			'name' => $this->getDisplayName($userId),
			'type' => $this->getType(),
			'lobby-state' => $this->getLobbyState(),
			'lobby-timer' => $this->getLobbyTimer(),
			'read-only' => $this->getReadOnly(),
			'listable' => $this->getListable(),
			'active-since' => $this->getActiveSince(),
			'sip-enabled' => $this->getSIPEnabled(),
		];

		if ($roomModified) {
			$properties = array_merge($properties, [
				'description' => $this->getDescription(),
				'avatarId' => $this->getAvatarId(),
				'avatarVersion' => $this->getAvatarVersion(),
			]);
		}

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
		$helper = new SelectHelper();
		$helper->selectAttendeesTable($query);
		$helper->selectSessionsTable($query);
		$query->from('talk_attendees', 'a')
			->leftJoin('a', 'talk_sessions', 's', $query->expr()->eq('a.id', 's.attendee_id'))
			->where($query->expr()->eq('a.actor_type', $query->createNamedParameter(Attendee::ACTOR_USERS)))
			->andWhere($query->expr()->eq('a.actor_id', $query->createNamedParameter($userId)))
			->andWhere($query->expr()->eq('a.room_id', $query->createNamedParameter($this->getId())))
			->setMaxResults(1);
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
		$helper = new SelectHelper();
		$helper->selectAttendeesTable($query);
		$helper->selectSessionsTable($query);
		$query->from('talk_sessions', 's')
			->leftJoin('s', 'talk_attendees', 'a', $query->expr()->eq('a.id', 's.attendee_id'))
			->where($query->expr()->eq('s.session_id', $query->createNamedParameter($sessionId)))
			->andWhere($query->expr()->eq('a.room_id', $query->createNamedParameter($this->getId())))
			->setMaxResults(1);
		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			throw new ParticipantNotFoundException('User is not a participant');
		}

		return $this->manager->createParticipantObject($this, $row);
	}

	/**
	 * @param string $pin
	 * @return Participant
	 * @throws ParticipantNotFoundException When the pin is not valid (has no participant assigned)
	 */
	public function getParticipantByPin(string $pin): Participant {
		$query = $this->db->getQueryBuilder();
		$helper = new SelectHelper();
		$helper->selectAttendeesTable($query);
		$helper->selectSessionsTable($query);
		$query->from('talk_attendees', 'a')
			->leftJoin('a', 'talk_sessions', 's', $query->expr()->eq('a.id', 's.attendee_id'))
			->andWhere($query->expr()->eq('a.pin', $query->createNamedParameter($pin)))
			->andWhere($query->expr()->eq('a.room_id', $query->createNamedParameter($this->getId())))
			->setMaxResults(1);
		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			throw new ParticipantNotFoundException('User is not a participant');
		}

		return $this->manager->createParticipantObject($this, $row);
	}

	/**
	 * @param int $attendeeId
	 * @return Participant
	 * @throws ParticipantNotFoundException When the pin is not valid (has no participant assigned)
	 */
	public function getParticipantByAttendeeId(int $attendeeId): Participant {
		$query = $this->db->getQueryBuilder();
		$helper = new SelectHelper();
		$helper->selectAttendeesTable($query);
		$helper->selectSessionsTable($query);
		$query->from('talk_attendees', 'a')
			->leftJoin('a', 'talk_sessions', 's', $query->expr()->eq('a.id', 's.attendee_id'))
			->andWhere($query->expr()->eq('a.id', $query->createNamedParameter($attendeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('a.room_id', $query->createNamedParameter($this->getId())))
			->setMaxResults(1);
		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			throw new ParticipantNotFoundException('User is not a participant');
		}

		return $this->manager->createParticipantObject($this, $row);
	}

	/**
	 * @param string $actorType
	 * @param string $actorId
	 * @return Participant
	 * @throws ParticipantNotFoundException When the pin is not valid (has no participant assigned)
	 */
	public function getParticipantByActor(string $actorType, string $actorId): Participant {
		if ($actorType === Attendee::ACTOR_USERS) {
			return $this->getParticipant($actorId);
		}

		$query = $this->db->getQueryBuilder();
		$helper = new SelectHelper();
		$helper->selectAttendeesTable($query);
		$helper->selectSessionsTable($query);
		$query->from('talk_attendees', 'a')
			->leftJoin('a', 'talk_sessions', 's', $query->expr()->eq('a.id', 's.attendee_id'))
			->andWhere($query->expr()->eq('a.actor_type', $query->createNamedParameter($actorType)))
			->andWhere($query->expr()->eq('a.actor_id', $query->createNamedParameter($actorId)))
			->andWhere($query->expr()->eq('a.room_id', $query->createNamedParameter($this->getId())))
			->setMaxResults(1);
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

		// Delete attendees
		$query->delete('talk_attendees')
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
	 * @param string|null $oldName
	 * @return bool True when the change was valid, false otherwise
	 */
	public function setName(string $newName, ?string $oldName = null): bool {
		$oldName = $oldName !== null ? $oldName : $this->getName();
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
	 * @param string $description
	 * @return bool True when the change was valid, false otherwise
	 * @throws \LengthException when the given description is too long
	 */
	public function setDescription(string $description): bool {
		$description = trim($description);

		if (mb_strlen($description) > self::DESCRIPTION_MAXIMUM_LENGTH) {
			throw new \LengthException('Conversation description is limited to ' . self::DESCRIPTION_MAXIMUM_LENGTH . ' characters');
		}

		$oldDescription = $this->getDescription();
		if ($description === $oldDescription) {
			return false;
		}

		$event = new ModifyRoomEvent($this, 'description', $description, $oldDescription);
		$this->dispatcher->dispatch(self::EVENT_BEFORE_DESCRIPTION_SET, $event);

		$query = $this->db->getQueryBuilder();
		$query->update('talk_rooms')
			->set('description', $query->createNamedParameter($description))
			->where($query->expr()->eq('id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));
		$query->execute();
		$this->description = $description;

		$this->dispatcher->dispatch(self::EVENT_AFTER_DESCRIPTION_SET, $event);

		return true;
	}

	/**
	 * Sets the avatar id and version.
	 *
	 * @param string $avatarId
	 * @param int $avatarVersion
	 * @return bool True when the change was valid, false otherwise
	 */
	public function setAvatar(string $avatarId, int $avatarVersion): bool {
		$oldAvatarId = $this->getAvatarId();
		$oldAvatarVersion = $this->getAvatarVersion();
		if ($avatarId === $oldAvatarId && $avatarVersion === $oldAvatarVersion) {
			return false;
		}

		if ($avatarVersion <= $oldAvatarVersion) {
			return false;
		}

		$event = new ModifyAvatarEvent($this, 'avatarId', $avatarId, $oldAvatarId, $avatarVersion);
		$this->dispatcher->dispatch(self::EVENT_BEFORE_AVATAR_SET, $event);

		$query = $this->db->getQueryBuilder();
		$query->update('talk_rooms')
			->set('avatar_id', $query->createNamedParameter($avatarId))
			->set('avatar_version', $query->createNamedParameter($avatarVersion, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));
		$query->execute();
		$this->avatarId = $avatarId;
		$this->avatarVersion = $avatarVersion;

		$this->dispatcher->dispatch(self::EVENT_AFTER_AVATAR_SET, $event);

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

	public function setAssignedSignalingServer(?int $signalingServer): bool {
		$query = $this->db->getQueryBuilder();
		$query->update('talk_rooms')
			->set('assigned_hpb', $query->createNamedParameter($signalingServer))
			->where($query->expr()->eq('id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));

		if ($signalingServer !== null) {
			$query->andWhere($query->expr()->isNull('assigned_hpb'));
		}

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
			$query->delete('talk_attendees')
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
	 * @param int $newState New listable scope from self::LISTABLE_*
	 * 						Also it's only allowed on rooms of type
	 * 						`self::GROUP_CALL` and `self::PUBLIC_CALL`
	 * @return bool True when the change was valid, false otherwise
	 */
	public function setListable(int $newState): bool {
		$oldState = $this->getListable();
		if ($newState === $oldState) {
			return true;
		}

		if (!in_array($this->getType(), [self::GROUP_CALL, self::PUBLIC_CALL], true)) {
			return false;
		}

		if (!in_array($newState, [
			Room::LISTABLE_NONE,
			Room::LISTABLE_USERS,
			Room::LISTABLE_ALL,
		], true)) {
			return false;
		}

		$event = new ModifyRoomEvent($this, 'listable', $newState, $oldState);
		$this->dispatcher->dispatch(self::EVENT_BEFORE_LISTABLE_SET, $event);

		$query = $this->db->getQueryBuilder();
		$query->update('talk_rooms')
			->set('listable', $query->createNamedParameter($newState, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));
		$query->execute();

		$this->listable = $newState;

		$this->dispatcher->dispatch(self::EVENT_AFTER_LISTABLE_SET, $event);

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

		return true;
	}

	public function setSIPEnabled(int $newSipEnabled): bool {
		$oldSipEnabled = $this->sipEnabled;

		if ($newSipEnabled === $oldSipEnabled) {
			return false;
		}

		if (!in_array($this->getType(), [self::GROUP_CALL, self::PUBLIC_CALL], true)) {
			return false;
		}

		if (!in_array($newSipEnabled, [Webinary::SIP_ENABLED, Webinary::SIP_DISABLED], true)) {
			return false;
		}

		if (preg_match(self::SIP_INCOMPATIBLE_REGEX, $this->token)) {
			return false;
		}

		$event = new ModifyRoomEvent($this, 'sipEnabled', $newSipEnabled, $oldSipEnabled);
		$this->dispatcher->dispatch(self::EVENT_BEFORE_SIP_ENABLED_SET, $event);

		$query = $this->db->getQueryBuilder();
		$query->update('talk_rooms')
			->set('sip_enabled', $query->createNamedParameter($newSipEnabled, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));
		$query->execute();

		$this->sipEnabled = $newSipEnabled;

		$this->dispatcher->dispatch(self::EVENT_AFTER_SIP_ENABLED_SET, $event);


		return true;
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
}
