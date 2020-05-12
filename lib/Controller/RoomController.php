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

namespace OCA\Talk\Controller;

use OCA\Circles\Api\v1\Circles;
use OCA\Circles\Model\Member;
use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Chat\MessageParser;
use OCA\Talk\Config;
use OCA\Talk\Events\UserEvent;
use OCA\Talk\Exceptions\InvalidPasswordException;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Exceptions\UnauthorizedException;
use OCA\Talk\GuestManager;
use OCA\Talk\Manager;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\TalkSession;
use OCA\Talk\Webinary;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IConfig;

class RoomController extends AEnvironmentAwareController {

	public const EVENT_BEFORE_ROOMS_GET = self::class . '::preGetRooms';

	/** @var string|null */
	protected $userId;
	/** @var IAppManager */
	protected $appManager;
	/** @var TalkSession */
	protected $session;
	/** @var IUserManager */
	protected $userManager;
	/** @var IGroupManager */
	protected $groupManager;
	/** @var Manager */
	protected $manager;
	/** @var GuestManager */
	protected $guestManager;
	/** @var ChatManager */
	protected $chatManager;
	/** @var IEventDispatcher */
	protected $dispatcher;
	/** @var MessageParser */
	protected $messageParser;
	/** @var ITimeFactory */
	protected $timeFactory;
	/** @var IL10N */
	protected $l10n;
	/** @var IConfig */
	protected $config;
	/** @var Config */
	protected $talkConfig;

	public function __construct(string $appName,
								?string $UserId,
								IRequest $request,
								IAppManager $appManager,
								TalkSession $session,
								IUserManager $userManager,
								IGroupManager $groupManager,
								Manager $manager,
								GuestManager $guestManager,
								ChatManager $chatManager,
								IEventDispatcher $dispatcher,
								MessageParser $messageParser,
								ITimeFactory $timeFactory,
								IL10N $l10n,
								IConfig $config,
								Config $talkConfig) {
		parent::__construct($appName, $request);
		$this->session = $session;
		$this->appManager = $appManager;
		$this->userId = $UserId;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->manager = $manager;
		$this->guestManager = $guestManager;
		$this->chatManager = $chatManager;
		$this->dispatcher = $dispatcher;
		$this->messageParser = $messageParser;
		$this->timeFactory = $timeFactory;
		$this->l10n = $l10n;
		$this->config = $config;
		$this->talkConfig = $talkConfig;
	}

	protected function getTalkHashHeader(): array {
		return [
			'X-Nextcloud-Talk-Hash' => sha1(
				$this->config->getSystemValueString('version') . '#' .
				$this->config->getAppValue('spreed', 'installed_version', '') . '#' .
				$this->config->getAppValue('theming', 'cachebuster', '1')
		)];
	}

	/**
	 * Get all currently existent rooms which the user has joined
	 *
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function getRooms(): DataResponse {
		$event = new UserEvent($this->userId);
		$this->dispatcher->dispatch(self::EVENT_BEFORE_ROOMS_GET, $event);

		$rooms = $this->manager->getRoomsForParticipant($this->userId, true);

		$return = [];
		foreach ($rooms as $room) {
			try {
				$return[] = $this->formatRoom($room, $room->getParticipant($this->userId));
			} catch (RoomNotFoundException $e) {
			} catch (\RuntimeException $e) {
			}
		}

		return new DataResponse($return, Http::STATUS_OK, $this->getTalkHashHeader());
	}

	/**
	 * @PublicPage
	 *
	 * @param string $token
	 * @return DataResponse
	 */
	public function getSingleRoom(string $token): DataResponse {
		try {
			$room = $this->manager->getRoomForParticipantByToken($token, $this->userId, true);

			$participant = null;
			try {
				$participant = $room->getParticipant($this->userId);
			} catch (ParticipantNotFoundException $e) {
				try {
					$participant = $room->getParticipantBySession($this->session->getSessionForRoom($token));
				} catch (ParticipantNotFoundException $e) {
				}
			}

			return new DataResponse($this->formatRoom($room, $participant), Http::STATUS_OK, $this->getTalkHashHeader());
		} catch (RoomNotFoundException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * @param string $apiVersion
	 * @param Room $room
	 * @param Participant $currentParticipant
	 * @return array
	 * @throws RoomNotFoundException
	 */
	protected function formatRoom(Room $room, ?Participant $currentParticipant): array {
		if ($this->getAPIVersion() === 2) {
			return $this->formatRoomV2($room, $currentParticipant);
		}

		return $this->formatRoomV1($room, $currentParticipant);
	}

	/**
	 * @param Room $room
	 * @param Participant $currentParticipant
	 * @return array
	 * @throws RoomNotFoundException
	 */
	protected function formatRoomV1(Room $room, ?Participant $currentParticipant): array {
		$roomData = [
			'id' => $room->getId(),
			'token' => $room->getToken(),
			'type' => $room->getType(),
			'name' => '',
			'displayName' => '',
			'objectType' => '',
			'objectId' => '',
			'participantType' => Participant::GUEST,
			// Deprecated, use participantFlags instead.
			'participantInCall' => false,
			'participantFlags' => Participant::FLAG_DISCONNECTED,
			'readOnly' => Room::READ_WRITE,
			'count' => 0,
			'hasPassword' => $room->hasPassword(),
			'hasCall' => false,
			'canStartCall' => false,
			'lastActivity' => 0,
			'lastReadMessage' => 0,
			'unreadMessages' => 0,
			'unreadMention' => false,
			'isFavorite' => false,
			'notificationLevel' => Participant::NOTIFY_NEVER,
			'lastPing' => 0,
			'sessionId' => '0',
			'participants' => [],
			'numGuests' => 0,
			'guestList' => '',
			'lastMessage' => [],
		];

		if (!$currentParticipant instanceof Participant) {
			return $roomData;
		}

		$lastActivity = $room->getLastActivity();
		if ($lastActivity instanceof \DateTimeInterface) {
			$lastActivity = $lastActivity->getTimestamp();
		} else {
			$lastActivity = 0;
		}

		$lobbyTimer = $room->getLobbyTimer();
		if ($lobbyTimer instanceof \DateTimeInterface) {
			$lobbyTimer = $lobbyTimer->getTimestamp();
		} else {
			$lobbyTimer = 0;
		}

		$roomData = array_merge($roomData, [
			'name' => $room->getName(),
			'displayName' => $room->getDisplayName($currentParticipant->getUser()),
			'objectType' => $room->getObjectType(),
			'objectId' => $room->getObjectId(),
			'participantType' => $currentParticipant->getParticipantType(),
			// Deprecated, use participantFlags instead.
			'participantInCall' => ($currentParticipant->getInCallFlags() & Participant::FLAG_IN_CALL) !== 0,
			'participantFlags' => $currentParticipant->getInCallFlags(),
			'readOnly' => $room->getReadOnly(),
			'count' => 0, // Deprecated, remove in future API version
			'hasCall' => $room->getActiveSince() instanceof \DateTimeInterface,
			'lastActivity' => $lastActivity,
			'isFavorite' => $currentParticipant->isFavorite(),
			'notificationLevel' => $currentParticipant->getNotificationLevel(),
			'lobbyState' => $room->getLobbyState(),
			'lobbyTimer' => $lobbyTimer,
			'lastPing' => $currentParticipant->getLastPing(),
			'sessionId' => $currentParticipant->getSessionId(),
		]);

		if ($roomData['notificationLevel'] === Participant::NOTIFY_DEFAULT) {
			if ($currentParticipant->isGuest()) {
				$roomData['notificationLevel'] = Participant::NOTIFY_NEVER;
			} else if ($room->getType() === Room::ONE_TO_ONE_CALL) {
				$roomData['notificationLevel'] = Participant::NOTIFY_ALWAYS;
			} else {
				$adminSetting = (int) $this->config->getAppValue('spreed', 'default_group_notification', Participant::NOTIFY_DEFAULT);
				if ($adminSetting === Participant::NOTIFY_DEFAULT) {
					$roomData['notificationLevel'] = Participant::NOTIFY_MENTION;
				} else {
					$roomData['notificationLevel'] = $adminSetting;
				}
			}
		}

		if ($room->getLobbyState() === Webinary::LOBBY_NON_MODERATORS &&
			!$currentParticipant->hasModeratorPermissions()) {
			// No participants and chat messages for users in the lobby.
			return $roomData;
		}

		$roomData['canStartCall'] = $currentParticipant->canStartCall();

		$currentUser = $this->userManager->get($currentParticipant->getUser());
		if ($currentUser instanceof IUser) {
			$lastReadMessage = $currentParticipant->getLastReadMessage();
			if ($lastReadMessage === -1) {
				/*
				 * Because the migration from the old comment_read_markers was
				 * not possible in a programmatic way with a reasonable O(1) or O(n)
				 * but only with O(user×chat), we do the conversion here.
				 */
				$lastReadMessage = $this->chatManager->getLastReadMessageFromLegacy($room, $currentUser);
				$currentParticipant->setLastReadMessage($lastReadMessage);
			}
			$roomData['unreadMessages'] = $this->chatManager->getUnreadCount($room, $lastReadMessage);

			$lastMention = $currentParticipant->getLastMentionMessage();
			$roomData['unreadMention'] = $lastMention !== 0 && $lastReadMessage < $lastMention;
			$roomData['lastReadMessage'] = $lastReadMessage;
		}

		$numActiveGuests = 0;
		$cleanGuests = false;
		$participantList = [];
		$participants = $room->getParticipants();
		uasort($participants, function(Participant $participant1, Participant $participant2) {
			return $participant2->getLastPing() - $participant1->getLastPing();
		});

		foreach ($participants as $participant) {
			if ($participant->isGuest()) {
				if ($participant->getLastPing() <= $this->timeFactory->getTime() - 100) {
					$cleanGuests = true;
				} else {
					$numActiveGuests++;
				}
			} else {
				$user = $this->userManager->get($participant->getUser());
				if ($user instanceof IUser) {
					$participantList[(string)$user->getUID()] = [
						'name' => $user->getDisplayName(),
						'type' => $participant->getParticipantType(),
						'call' => $participant->getInCallFlags(),
						'sessionId' => $participant->getSessionId(),
					];

					if ($room->getType() === Room::ONE_TO_ONE_CALL &&
						  $user->getUID() !== $currentParticipant->getUser()) {
						// FIXME This should not be done, but currently all the clients use it to get the avatar of the user …
						$roomData['name'] = $user->getUID();
					}
				}

				if ($participant->getSessionId() !== '0' && $participant->getLastPing() <= $this->timeFactory->getTime() - 100) {
					$room->leaveRoom($participant->getUser());
				}
			}
		}

		if ($cleanGuests) {
			$room->cleanGuestParticipants();
		}

		$lastMessage = $room->getLastMessage();
		if ($lastMessage instanceof IComment) {
			$lastMessage = $this->formatLastMessage($room, $currentParticipant, $lastMessage);
		} else {
			$lastMessage = [];
		}

		$roomData = array_merge($roomData, [
			'participants' => $participantList,
			'numGuests' => $numActiveGuests,
			'lastMessage' => $lastMessage,
		]);

		return $roomData;
	}

	/**
	 * @param Room $room
	 * @param Participant $currentParticipant
	 * @return array
	 * @throws RoomNotFoundException
	 */
	protected function formatRoomV2(Room $room, ?Participant $currentParticipant): array {
		$roomData = [
			'id' => $room->getId(),
			'token' => $room->getToken(),
			'type' => $room->getType(),
			'name' => '',
			'displayName' => '',
			'objectType' => '',
			'objectId' => '',
			'participantType' => Participant::GUEST,
			'participantFlags' => Participant::FLAG_DISCONNECTED,
			'readOnly' => Room::READ_WRITE,
			'hasPassword' => $room->hasPassword(),
			'hasCall' => false,
			'canStartCall' => false,
			'lastActivity' => 0,
			'lastReadMessage' => 0,
			'unreadMessages' => 0,
			'unreadMention' => false,
			'isFavorite' => false,
			'canLeaveConversation' => false,
			'canDeleteConversation' => false,
			'notificationLevel' => Participant::NOTIFY_NEVER,
			'lastPing' => 0,
			'sessionId' => '0',
			'guestList' => '',
			'lastMessage' => [],
		];

		if (!$currentParticipant instanceof Participant) {
			return $roomData;
		}

		$lastActivity = $room->getLastActivity();
		if ($lastActivity instanceof \DateTimeInterface) {
			$lastActivity = $lastActivity->getTimestamp();
		} else {
			$lastActivity = 0;
		}

		$lobbyTimer = $room->getLobbyTimer();
		if ($lobbyTimer instanceof \DateTimeInterface) {
			$lobbyTimer = $lobbyTimer->getTimestamp();
		} else {
			$lobbyTimer = 0;
		}

		$roomData = array_merge($roomData, [
			'name' => $room->getName(),
			'displayName' => $room->getDisplayName($currentParticipant->getUser()),
			'objectType' => $room->getObjectType(),
			'objectId' => $room->getObjectId(),
			'participantType' => $currentParticipant->getParticipantType(),
			'participantFlags' => $currentParticipant->getInCallFlags(),
			'readOnly' => $room->getReadOnly(),
			'hasCall' => $room->getActiveSince() instanceof \DateTimeInterface,
			'lastActivity' => $lastActivity,
			'isFavorite' => $currentParticipant->isFavorite(),
			'notificationLevel' => $currentParticipant->getNotificationLevel(),
			'lobbyState' => $room->getLobbyState(),
			'lobbyTimer' => $lobbyTimer,
			'lastPing' => $currentParticipant->getLastPing(),
			'sessionId' => $currentParticipant->getSessionId(),
		]);

		if ($roomData['notificationLevel'] === Participant::NOTIFY_DEFAULT) {
			if ($currentParticipant->isGuest()) {
				$roomData['notificationLevel'] = Participant::NOTIFY_NEVER;
			} else if ($room->getType() === Room::ONE_TO_ONE_CALL) {
				$roomData['notificationLevel'] = Participant::NOTIFY_ALWAYS;
			} else {
				$adminSetting = (int) $this->config->getAppValue('spreed', 'default_group_notification', Participant::NOTIFY_DEFAULT);
				if ($adminSetting === Participant::NOTIFY_DEFAULT) {
					$roomData['notificationLevel'] = Participant::NOTIFY_MENTION;
				} else {
					$roomData['notificationLevel'] = $adminSetting;
				}
			}
		}

		if ($room->getLobbyState() === Webinary::LOBBY_NON_MODERATORS &&
			!$currentParticipant->hasModeratorPermissions()) {
			// No participants and chat messages for users in the lobby.
			return $roomData;
		}

		$roomData['canStartCall'] = $currentParticipant->canStartCall();

		$currentUser = $this->userManager->get($currentParticipant->getUser());
		if ($currentUser instanceof IUser) {
			$lastReadMessage = $currentParticipant->getLastReadMessage();
			if ($lastReadMessage === -1) {
				/*
				 * Because the migration from the old comment_read_markers was
				 * not possible in a programmatic way with a reasonable O(1) or O(n)
				 * but only with O(user×chat), we do the conversion here.
				 */
				$lastReadMessage = $this->chatManager->getLastReadMessageFromLegacy($room, $currentUser);
				$currentParticipant->setLastReadMessage($lastReadMessage);
			}
			$roomData['unreadMessages'] = $this->chatManager->getUnreadCount($room, $lastReadMessage);

			$lastMention = $currentParticipant->getLastMentionMessage();
			$roomData['unreadMention'] = $lastMention !== 0 && $lastReadMessage < $lastMention;
			$roomData['lastReadMessage'] = $lastReadMessage;

			$roomData['canDeleteConversation'] = $room->getType() !== Room::ONE_TO_ONE_CALL
				&& $currentParticipant->hasModeratorPermissions(false);
			$roomData['canLeaveConversation'] = !$roomData['canDeleteConversation']
				|| ($room->getType() !== Room::ONE_TO_ONE_CALL && $room->getNumberOfParticipants() > 1);

			if ($room->getType() === Room::NOTES_CONVERSATION) {
				$roomData['canDeleteConversation'] = true;
				$roomData['canLeaveConversation'] = false;
			}
		}

		// FIXME This should not be done, but currently all the clients use it to get the avatar of the user …
		if ($room->getType() === Room::ONE_TO_ONE_CALL) {
			$participants = $room->getParticipants();
			foreach ($participants as $participant) {
				$user = $this->userManager->get($participant->getUser());
				if ($user instanceof IUser && $user->getUID() !== $currentParticipant->getUser()) {
					$roomData['name'] = $user->getUID();
				}
			}
		}

		$lastMessage = $room->getLastMessage();
		if ($lastMessage instanceof IComment) {
			$roomData['lastMessage'] = $this->formatLastMessage($room, $currentParticipant, $lastMessage);
		} else {
			$roomData['lastMessage'] = [];
		}

		return $roomData;
	}

	/**
	 * @param Room $room
	 * @param Participant $participant
	 * @param IComment $lastMessage
	 * @return array
	 */
	protected function formatLastMessage(Room $room, Participant $participant, IComment $lastMessage): array {
		$message = $this->messageParser->createMessage($room, $participant, $lastMessage, $this->l10n);
		$this->messageParser->parseMessage($message);

		if (!$message->getVisibility()) {
			return [];
		}

		return $message->toArray();
	}

	/**
	 * Initiates a one-to-one video call from the current user to the recipient
	 *
	 * @NoAdminRequired
	 *
	 * @param int $roomType
	 * @param string $invite
	 * @param string $roomName
	 * @param string $source
	 * @return DataResponse
	 */
	public function createRoom(int $roomType, string $invite = '', string $roomName = '', string $source = ''): DataResponse {

		if ($roomType !== Room::ONE_TO_ONE_CALL) {
			/** @var IUser $user */
			$user = $this->userManager->get($this->userId);

			if ($this->talkConfig->isNotAllowedToCreateConversations($user)) {
				return new DataResponse([], Http::STATUS_FORBIDDEN);
			}
		}

		switch ($roomType) {
			case Room::ONE_TO_ONE_CALL:
				return $this->createOneToOneRoom($invite);
			case Room::GROUP_CALL:
				if ($invite === '') {
					return $this->createEmptyRoom($roomName, false);
				}
				if ($source === 'circles') {
					return $this->createCircleRoom($invite);
				}
				return $this->createGroupRoom($invite);
			case Room::PUBLIC_CALL:
				return $this->createEmptyRoom($roomName);
		}

		return new DataResponse([], Http::STATUS_BAD_REQUEST);
	}

	/**
	 * Initiates a one-to-one video call from the current user to the recipient
	 *
	 * @NoAdminRequired
	 *
	 * @param string $targetUserName
	 * @return DataResponse
	 */
	protected function createOneToOneRoom(string $targetUserName): DataResponse {
		$currentUser = $this->userManager->get($this->userId);
		if (!$currentUser instanceof IUser) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$targetUser = $this->userManager->get($targetUserName);
		if (!$targetUser instanceof IUser) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		if ($this->userId === $targetUserName) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}

		// If room exists: Reuse that one, otherwise create a new one.
		try {
			$room = $this->manager->getOne2OneRoom($this->userId, $targetUser->getUID());
			$room->ensureOneToOneRoomIsFilled();
			return new DataResponse($this->formatRoom($room, $room->getParticipant($currentUser->getUID())), Http::STATUS_OK);
		} catch (RoomNotFoundException $e) {
			$room = $this->manager->createOne2OneRoom();
			$room->addUsers([
				'userId' => $currentUser->getUID(),
				'participantType' => Participant::OWNER,
			], [
				'userId' => $targetUser->getUID(),
				'participantType' => Participant::OWNER,
			]);

			return new DataResponse($this->formatRoom($room, $room->getParticipant($currentUser->getUID())), Http::STATUS_CREATED);
		}
	}

	/**
	 * Initiates a group video call from the selected group
	 *
	 * @NoAdminRequired
	 *
	 * @param string $targetGroupName
	 * @return DataResponse
	 */
	protected function createGroupRoom(string $targetGroupName): DataResponse {
		$targetGroup = $this->groupManager->get($targetGroupName);
		$currentUser = $this->userManager->get($this->userId);

		if (!$targetGroup instanceof IGroup) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		if (!$currentUser instanceof IUser) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		// Create the room
		$room = $this->manager->createGroupRoom($targetGroup->getGID());
		$room->addUsers([
			'userId' => $currentUser->getUID(),
			'participantType' => Participant::OWNER,
		]);

		$usersInGroup = $targetGroup->getUsers();
		$participants = [];
		foreach ($usersInGroup as $user) {
			if ($currentUser->getUID() === $user->getUID()) {
				// Owner is already added.
				continue;
			}

			$participants[] = [
				'userId' => $user->getUID(),
			];
		}

		\call_user_func_array([$room, 'addUsers'], $participants);

		return new DataResponse($this->formatRoom($room, $room->getParticipant($currentUser->getUID())), Http::STATUS_CREATED);
	}

	/**
	 * Initiates a group video call from the selected circle
	 *
	 * @NoAdminRequired
	 *
	 * @param string $targetCircleId
	 * @return DataResponse
	 */
	protected function createCircleRoom(string $targetCircleId): DataResponse {
		if (!$this->appManager->isEnabledForUser('circles')) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		/** @var Circles $circlesApi */
		try {
			$circle = Circles::detailsCircle($targetCircleId);
		} catch (\Exception $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		$currentUser = $this->userManager->get($this->userId);

		if (!$currentUser instanceof IUser) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		// Create the room
		$room = $this->manager->createGroupRoom($circle->getName());
		$room->addUsers([
			'userId' => $currentUser->getUID(),
			'participantType' => Participant::OWNER,
		]);

		$participants = [];
		foreach ($circle->getMembers() as $member) {
			/** @var Member $member */
			if ($member->getType() !== Member::TYPE_USER || $member->getUserId() === '') {
				// Not a user?
				continue;
			}

			if ($currentUser->getUID() === $member->getUserId()) {
				// Current user is already added
				continue;
			}

			if ($member->getStatus() !== Member::STATUS_INVITED && $member->getStatus() !== Member::STATUS_MEMBER) {
				// Only allow invited and regular members
				continue;
			}

			$participants[] = [
				'userId' => $member->getUserId(),
			];
		}

		\call_user_func_array([$room, 'addUsers'], $participants);

		return new DataResponse($this->formatRoom($room, $room->getParticipant($currentUser->getUID())), Http::STATUS_CREATED);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $roomName
	 * @param bool $public
	 * @return DataResponse
	 */
	protected function createEmptyRoom(string $roomName, bool $public = true): DataResponse {
		$roomName = trim($roomName);
		if ($roomName === '') {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$currentUser = $this->userManager->get($this->userId);

		if (!$currentUser instanceof IUser) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		// Create the room
		if ($public) {
			$room = $this->manager->createPublicRoom($roomName);
		} else {
			$room = $this->manager->createGroupRoom($roomName);
		}
		$room->addUsers([
			'userId' => $currentUser->getUID(),
			'participantType' => Participant::OWNER,
		]);

		return new DataResponse($this->formatRoom($room, $room->getParticipant($currentUser->getUID())), Http::STATUS_CREATED);
	}

	/**
	 * @NoAdminRequired
	 * @RequireLoggedInParticipant
	 *
	 * @return DataResponse
	 */
	public function addToFavorites(): DataResponse {
		$this->participant->setFavorite(true);
		return new DataResponse([]);
	}

	/**
	 * @NoAdminRequired
	 * @RequireLoggedInParticipant
	 *
	 * @return DataResponse
	 */
	public function removeFromFavorites(): DataResponse {
		$this->participant->setFavorite(false);
		return new DataResponse([]);
	}

	/**
	 * @NoAdminRequired
	 * @RequireLoggedInParticipant
	 *
	 * @param int $level
	 * @return DataResponse
	 */
	public function setNotificationLevel(int $level): DataResponse {

		if (!$this->participant->setNotificationLevel($level)) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse();
	}

	/**
	 * @PublicPage
	 * @RequireModeratorParticipant
	 *
	 * @param string $roomName
	 * @return DataResponse
	 */
	public function renameRoom(string $roomName): DataResponse {
		if ($this->room->getType() === Room::ONE_TO_ONE_CALL) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$roomName = trim($roomName);

		if ($roomName === '' || strlen($roomName) > 200) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$this->room->setName($roomName);
		return new DataResponse();
	}

	/**
	 * @PublicPage
	 * @RequireModeratorParticipant
	 *
	 * @return DataResponse
	 */
	public function deleteRoom(): DataResponse {
		if ($this->room->getType() === Room::ONE_TO_ONE_CALL) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$this->room->deleteRoom();

		return new DataResponse([]);
	}

	/**
	 * @PublicPage
	 * @RequireParticipant
	 * @RequireModeratorOrNoLobby
	 *
	 * @return DataResponse
	 */
	public function getParticipants(): DataResponse {
		if ($this->participant->getParticipantType() === Participant::GUEST) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}

		$participants = $this->room->getParticipantsLegacy();
		$results = [];

		foreach ($participants['users'] as $userId => $participant) {
			$user = $this->userManager->get((string) $userId);
			if (!$user instanceof IUser) {
				continue;
			}

			$results[] = array_merge($participant, [
				'userId' => (string) $userId,
				'displayName' => (string) $user->getDisplayName(),
			]);
		}

		$guestSessions = [];
		foreach ($participants['guests'] as $participant) {
			$guestSessions[] = sha1($participant['sessionId']);
		}
		$guestNames = $this->guestManager->getNamesBySessionHashes($guestSessions);

		foreach ($participants['guests'] as $participant) {
			$sessionHash = sha1($participant['sessionId']);
			$results[] = array_merge($participant, [
				'userId' => '',
				'displayName' => $guestNames[$sessionHash] ?? '',
			]);
		}

		return new DataResponse($results);
	}

	/**
	 * @NoAdminRequired
	 * @RequireLoggedInModeratorParticipant
	 *
	 * @param string $newParticipant
	 * @param string $source
	 * @return DataResponse
	 */
	public function addParticipantToRoom(string $newParticipant, string $source = 'users'): DataResponse {
		if ($this->room->getType() === Room::ONE_TO_ONE_CALL) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$participants = $this->room->getParticipantUserIds();

		$participantsToAdd = [];
		if ($source === 'users') {
			$newUser = $this->userManager->get($newParticipant);
			if (!$newUser instanceof IUser) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			if (\in_array($newParticipant, $participants, true)) {
				return new DataResponse([]);
			}

			$this->room->addUsers([
				'userId' => $newUser->getUID(),
			]);
		} else if ($source === 'groups') {
			$group = $this->groupManager->get($newParticipant);
			if (!$group instanceof IGroup) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			$usersInGroup = $group->getUsers();
			foreach ($usersInGroup as $user) {
				if (\in_array($user->getUID(), $participants, true)) {
					continue;
				}

				$participantsToAdd[] = [
					'userId' => $user->getUID(),
				];
			}

			if (empty($participantsToAdd)) {
				return new DataResponse([]);
			}

			\call_user_func_array([$this->room, 'addUsers'], $participantsToAdd);
		} else if ($source === 'circles') {
			if (!$this->appManager->isEnabledForUser('circles')) {
				return new DataResponse([], Http::STATUS_BAD_REQUEST);
			}

			/** @var Circles $circlesApi */
			try {
				$circle = Circles::detailsCircle($newParticipant);
			} catch (\Exception $e) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			foreach ($circle->getMembers() as $member) {
				/** @var Member $member */
				if ($member->getType() !== Member::TYPE_USER || $member->getUserId() === '') {
					// Not a user?
					continue;
				}

				if (\in_array($member->getUserId(), $participants, true)) {
					continue;
				}

				if ($member->getStatus() !== Member::STATUS_INVITED && $member->getStatus() !== Member::STATUS_MEMBER) {
					// Only allow invited and regular members
					continue;
				}

				$participantsToAdd[] = [
					'userId' => $member->getUserId(),
				];
			}

			if (empty($participantsToAdd)) {
				return new DataResponse([]);
			}

			\call_user_func_array([$this->room, 'addUsers'], $participantsToAdd);
		} else if ($source === 'emails') {
			$data = [];
			if ($this->room->setType(Room::PUBLIC_CALL)) {
				$data = ['type' => $this->room->getType()];
			}

			$this->guestManager->inviteByEmail($this->room, $newParticipant);

			return new DataResponse($data);
		} else {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse();
	}

	/**
	 * @PublicPage
	 * @RequireParticipant
	 *
	 * @param string $participant
	 * @return DataResponse
	 */
	public function removeParticipantFromRoom(string $participant): DataResponse {
		if ($this->participant->getUser() === $participant) {
			// Removing self, abusing moderator power
			return $this->removeSelfFromRoomLogic($this->room, $this->participant);
		}

		if (!$this->participant->hasModeratorPermissions()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}

		if ($this->room->getType() === Room::ONE_TO_ONE_CALL) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		try {
			$targetParticipant = $this->room->getParticipant($participant);
		} catch (ParticipantNotFoundException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		if ($targetParticipant->getParticipantType() === Participant::OWNER) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}

		$targetUser = $this->userManager->get($participant);
		if (!$targetUser instanceof IUser) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$this->room->removeUser($targetUser, Room::PARTICIPANT_REMOVED);
		return new DataResponse([]);
	}

	/**
	 * @NoAdminRequired
	 * @RequireLoggedInParticipant
	 *
	 * @return DataResponse
	 */
	public function removeSelfFromRoom(): DataResponse {
		return $this->removeSelfFromRoomLogic($this->room, $this->participant);
	}

	protected function removeSelfFromRoomLogic(Room $room, Participant $participant): DataResponse {
		if ($room->getType() !== Room::ONE_TO_ONE_CALL) {
			if ($participant->hasModeratorPermissions(false)
				&& $room->getNumberOfParticipants() > 1
				&& $room->getNumberOfModerators() === 1) {
				return new DataResponse([], Http::STATUS_BAD_REQUEST);
			}
		} else if ($room->getType() !== Room::CHANGELOG_CONVERSATION && $room->getType() !== Room::NOTES_CONVERSATION &&
			$room->getNumberOfParticipants() === 1) {
			$room->deleteRoom();
			return new DataResponse();
		}

		$currentUser = $this->userManager->get($participant->getUser());
		if (!$currentUser instanceof IUser) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$room->removeUser($currentUser, Room::PARTICIPANT_LEFT);

		return new DataResponse();
	}

	/**
	 * @PublicPage
	 * @RequireModeratorParticipant
	 *
	 * @param string $participant
	 * @return DataResponse
	 */
	public function removeGuestFromRoom(string $participant): DataResponse {
		try {
			$targetParticipant = $this->room->getParticipantBySession($participant);
		} catch (ParticipantNotFoundException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		if (!$targetParticipant->isGuest()) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		if ($targetParticipant->getSessionId() === $this->participant->getSessionId()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}

		$this->room->removeParticipantBySession($targetParticipant, Room::PARTICIPANT_REMOVED);
		return new DataResponse([]);
	}

	/**
	 * @NoAdminRequired
	 * @RequireLoggedInModeratorParticipant
	 *
	 * @return DataResponse
	 */
	public function makePublic(): DataResponse {
		if (!$this->room->setType(Room::PUBLIC_CALL)) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse();
	}

	/**
	 * @NoAdminRequired
	 * @RequireLoggedInModeratorParticipant
	 *
	 * @return DataResponse
	 */
	public function makePrivate(): DataResponse {
		if (!$this->room->setType(Room::GROUP_CALL)) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse();
	}

	/**
	 * @NoAdminRequired
	 * @RequireModeratorParticipant
	 *
	 * @param int $state
	 * @return DataResponse
	 */
	public function setReadOnly(int $state): DataResponse {
		if (!$this->room->setReadOnly($state)) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse();
	}

	/**
	 * @PublicPage
	 * @RequireModeratorParticipant
	 *
	 * @param string $password
	 * @return DataResponse
	 */
	public function setPassword(string $password): DataResponse {
		if ($this->room->getType() !== Room::PUBLIC_CALL) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}

		$this->room->setPassword($password);
		return new DataResponse();
	}

	/**
	 * @PublicPage
	 * @UseSession
	 *
	 * @param string $token
	 * @param string $password
	 * @return DataResponse
	 */
	public function joinRoom(string $token, string $password = ''): DataResponse {
		try {
			$room = $this->manager->getRoomForParticipantByToken($token, $this->userId);
		} catch (RoomNotFoundException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$user = $this->userManager->get($this->userId);
		try {
			$result = $room->verifyPassword((string) $this->session->getPasswordForRoom($token));
			if ($user instanceof IUser) {
				$newSessionId = $room->joinRoom($user, $password, $result['result']);
			} else {
				$newSessionId = $room->joinRoomGuest($password, $result['result']);
			}
		} catch (InvalidPasswordException $e) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		} catch (UnauthorizedException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$this->session->removePasswordForRoom($token);
		$this->session->setSessionForRoom($token, $newSessionId);
		$room->ping($this->userId, $newSessionId, $this->timeFactory->getTime());
		$currentParticipant = $room->getParticipantBySession($newSessionId);

		return new DataResponse($this->formatRoom($room, $currentParticipant));
	}

	/**
	 * @PublicPage
	 * @UseSession
	 *
	 * @param string $token
	 * @return DataResponse
	 */
	public function leaveRoom(string $token): DataResponse {
		$sessionId = $this->session->getSessionForRoom($token);
		$this->session->removeSessionForRoom($token);

		try {
			$room = $this->manager->getRoomForParticipantByToken($token, $this->userId);

			if ($this->userId === null) {
				$participant = $room->getParticipantBySession($sessionId);
				$room->removeParticipantBySession($participant, Room::PARTICIPANT_LEFT);
			} else {
				$participant = $room->getParticipant($this->userId);
				$room->leaveRoom($participant->getUser());
			}
		} catch (RoomNotFoundException $e) {
		} catch (ParticipantNotFoundException $e) {
		}

		return new DataResponse();
	}

	/**
	 * @PublicPage
	 * @RequireModeratorParticipant
	 *
	 * @param string|null $participant
	 * @param string|null $sessionId
	 * @return DataResponse
	 */
	public function promoteModerator(?string $participant, ?string $sessionId): DataResponse {
		if ($participant !== null) {
			return $this->promoteUserToModerator($this->room, $participant);
		}

		return $this->promoteGuestToModerator($this->room, $sessionId);
	}

	protected function promoteUserToModerator(Room $room, string $participant): DataResponse {
		try {
			$targetParticipant = $room->getParticipant($participant);
		} catch (ParticipantNotFoundException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		if ($targetParticipant->getParticipantType() !== Participant::USER) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$room->setParticipantType($targetParticipant, Participant::MODERATOR);

		return new DataResponse();
	}

	protected function promoteGuestToModerator(Room $room, string $sessionId): DataResponse {
		try {
			$targetParticipant = $room->getParticipantBySession($sessionId);
		} catch (ParticipantNotFoundException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		if ($targetParticipant->getParticipantType() !== Participant::GUEST) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$room->setParticipantType($targetParticipant, Participant::GUEST_MODERATOR);

		return new DataResponse();
	}

	/**
	 * @PublicPage
	 * @RequireModeratorParticipant
	 *
	 * @param string|null $participant
	 * @param string|null $sessionId
	 * @return DataResponse
	 */
	public function demoteModerator(?string $participant, ?string $sessionId): DataResponse {
		if ($participant !== null) {
			return $this->demoteUserFromModerator($this->room, $participant);
		}

		return $this->demoteGuestFromModerator($this->room, $sessionId);
	}

	protected function demoteUserFromModerator(Room $room, string $participant): DataResponse {
		if ($this->userId === $participant) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}

		try {
			$targetParticipant = $room->getParticipant($participant);
		} catch (ParticipantNotFoundException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		if ($targetParticipant->getParticipantType() !== Participant::MODERATOR) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$room->setParticipantType($targetParticipant, Participant::USER);

		return new DataResponse();
	}

	protected function demoteGuestFromModerator(Room $room, string $sessionId): DataResponse {
		if ($this->session->getSessionForRoom($room->getToken()) === $sessionId) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}

		try {
			$targetParticipant = $room->getParticipantBySession($sessionId);
		} catch (ParticipantNotFoundException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		if ($targetParticipant->getParticipantType() !== Participant::GUEST_MODERATOR) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$room->setParticipantType($targetParticipant, Participant::GUEST);

		return new DataResponse();
	}
}
