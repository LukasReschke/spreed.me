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

use InvalidArgumentException;
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
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Session;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\RoomService;
use OCA\Talk\Service\SessionService;
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
use OCP\User\Events\UserLiveStatusEvent;
use OCP\UserStatus\IManager as IUserStatusManager;
use OCP\UserStatus\IUserStatus;

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
	/** @var RoomService */
	protected $roomService;
	/** @var ParticipantService */
	protected $participantService;
	/** @var SessionService */
	protected $sessionService;
	/** @var GuestManager */
	protected $guestManager;
	/** @var IUserStatusManager */
	protected $statusManager;
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

	/** @var array */
	protected $commonReadMessages = [];

	public function __construct(string $appName,
								?string $UserId,
								IRequest $request,
								IAppManager $appManager,
								TalkSession $session,
								IUserManager $userManager,
								IGroupManager $groupManager,
								Manager $manager,
								RoomService $roomService,
								ParticipantService $participantService,
								SessionService $sessionService,
								GuestManager $guestManager,
								IUserStatusManager $statusManager,
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
		$this->roomService = $roomService;
		$this->participantService = $participantService;
		$this->sessionService = $sessionService;
		$this->guestManager = $guestManager;
		$this->statusManager = $statusManager;
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
				$this->config->getAppValue('spreed', 'stun_servers', '') . '#' .
				$this->config->getAppValue('spreed', 'turn_servers', '') . '#' .
				$this->config->getAppValue('spreed', 'signaling_servers', '') . '#' .
				$this->config->getAppValue('spreed', 'signaling_mode', '') . '#' .
				$this->config->getAppValue('spreed', 'allowed_groups', '') . '#' .
				$this->config->getAppValue('spreed', 'start_conversations', '') . '#' .
				$this->config->getAppValue('spreed', 'has_reference_id', '') . '#' .
				$this->config->getAppValue('spreed', 'sip_bridge_groups', '[]') . '#' .
				$this->config->getAppValue('spreed', 'sip_bridge_dialin_info') . '#' .
				$this->config->getAppValue('spreed', 'sip_bridge_shared_secret') . '#' .
				$this->config->getAppValue('theming', 'cachebuster', '1')
		)];
	}

	/**
	 * Get all currently existent rooms which the user has joined
	 *
	 * @NoAdminRequired
	 *
	 * @param int $noStatusUpdate When the user status should not be automatically set to online set to 1 (default 0)
	 * @return DataResponse
	 */
	public function getRooms(int $noStatusUpdate = 0): DataResponse {
		$event = new UserEvent($this->userId);
		$this->dispatcher->dispatch(self::EVENT_BEFORE_ROOMS_GET, $event);

		if ($noStatusUpdate === 0) {
			$isMobileApp = $this->request->isUserAgent([
				IRequest::USER_AGENT_TALK_ANDROID,
				IRequest::USER_AGENT_TALK_IOS,
			]);

			if ($isMobileApp) {
				// Bump the user status again
				$event = new UserLiveStatusEvent(
					$this->userManager->get($this->userId),
					IUserStatus::ONLINE,
					$this->timeFactory->getTime()
				);
				$this->dispatcher->dispatchTyped($event);
			}
		}

		$rooms = $this->manager->getRoomsForUser($this->userId, true);
		$readPrivacy = $this->talkConfig->getUserReadPrivacy($this->userId);
		if ($readPrivacy === Participant::PRIVACY_PUBLIC) {
			$roomIds = array_map(static function (Room $room) {
				return $room->getId();
			}, $rooms);
			$this->commonReadMessages = $this->participantService->getLastCommonReadChatMessageForMultipleRooms($roomIds);
		}

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
	 * Get listed rooms with optional search term
	 *
	 * @NoAdminRequired
	 *
	 * @param string $searchTerm search term
	 * @return DataResponse
	 */
	public function getListedRooms(string $searchTerm = ''): DataResponse {
		$event = new UserEvent($this->userId);

		$rooms = $this->manager->getListedRoomsForUser($this->userId, $searchTerm);

		$return = [];
		foreach ($rooms as $room) {
			try {
				$return[] = $this->formatRoomV2andV3($room, null);
			} catch (RoomNotFoundException $e) {
			} catch (\RuntimeException $e) {
			}
		}

		return new DataResponse($return, Http::STATUS_OK);
	}


	/**
	 * @PublicPage
	 *
	 * @param string $token
	 * @return DataResponse
	 */
	public function getSingleRoom(string $token): DataResponse {
		try {
			$isSIPBridgeRequest = $this->validateSIPBridgeRequest($token);
		} catch (UnauthorizedException $e) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if ($isSIPBridgeRequest && $this->getAPIVersion() < 3) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		// The SIP bridge only needs room details (public, sip enabled, lobby state, etc)
		$includeLastMessage = !$isSIPBridgeRequest;

		try {
			$room = $this->manager->getRoomForUserByToken($token, $this->userId, $includeLastMessage);

			$participant = null;
			try {
				$participant = $room->getParticipant($this->userId);
			} catch (ParticipantNotFoundException $e) {
				try {
					$participant = $room->getParticipantBySession($this->session->getSessionForRoom($token));
				} catch (ParticipantNotFoundException $e) {
				}
			}

			return new DataResponse($this->formatRoom($room, $participant, $isSIPBridgeRequest), Http::STATUS_OK, $this->getTalkHashHeader());
		} catch (RoomNotFoundException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Check if the current request is coming from an allowed backend.
	 *
	 * The SIP bridge is sending the custom header "Talk-SIPBridge-Random"
	 * containing at least 32 bytes random data, and the header
	 * "Talk-SIPBridge-Checksum", which is the SHA256-HMAC of the random data
	 * and the body of the request, calculated with the shared secret from the
	 * configuration.
	 *
	 * @param string $data
	 * @return bool True if the request is from the SIP bridge and valid, false if not from SIP bridge
	 * @throws UnauthorizedException when the request tried to sign as SIP bridge but is not valid
	 */
	private function validateSIPBridgeRequest(string $data): bool {
		$random = $this->request->getHeader('TALK_SIPBRIDGE_RANDOM');
		$checksum = $this->request->getHeader('TALK_SIPBRIDGE_CHECKSUM');

		if ($random === '' && $checksum === '') {
			return false;
		}

		if (strlen($random) < 32) {
			throw new UnauthorizedException('Invalid random provided');
		}

		if (empty($checksum)) {
			throw new UnauthorizedException('Invalid checksum provided');
		}

		$secret = $this->talkConfig->getSIPSharedSecret();
		if (empty($secret)) {
			throw new UnauthorizedException('No shared SIP secret provided');
		}
		$hash = hash_hmac('sha256', $random . $data, $secret);

		if (hash_equals($hash, strtolower($checksum))) {
			return true;
		}

		throw new UnauthorizedException('Invalid HMAC provided');
	}

	/**
	 * @param Room $room
	 * @param Participant|null $currentParticipant
	 * @param bool $isSIPBridgeRequest
	 * @return array
	 * @throws RoomNotFoundException
	 */
	protected function formatRoom(Room $room, ?Participant $currentParticipant, bool $isSIPBridgeRequest = false): array {
		if ($this->getAPIVersion() >= 2) {
			return $this->formatRoomV2andV3($room, $currentParticipant, $isSIPBridgeRequest);
		}

		return $this->formatRoomV1($room, $currentParticipant);
	}

	/**
	 * @param Room $room
	 * @param Participant|null $currentParticipant
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
			'lobbyState' => Webinary::LOBBY_NONE,
			'lobbyTimer' => 0,
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

		$attendee = $currentParticipant->getAttendee();
		$userId = $attendee->getActorType() === Attendee::ACTOR_USERS ? $attendee->getActorId() : '';

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
			'displayName' => $room->getDisplayName($userId),
			'objectType' => $room->getObjectType(),
			'objectId' => $room->getObjectId(),
			'participantType' => $attendee->getParticipantType(),
			'readOnly' => $room->getReadOnly(),
			'count' => 0, // Deprecated, remove in future API version
			'hasCall' => $room->getActiveSince() instanceof \DateTimeInterface,
			'lastActivity' => $lastActivity,
			'isFavorite' => $attendee->isFavorite(),
			'notificationLevel' => $attendee->getNotificationLevel(),
			'lobbyState' => $room->getLobbyState(),
			'lobbyTimer' => $lobbyTimer,
		]);

		$session = $currentParticipant->getSession();
		if ($session instanceof Session) {
			$roomData = array_merge($roomData, [
				// Deprecated, use participantFlags instead.
				'participantInCall' => ($session->getInCall() & Participant::FLAG_IN_CALL) !== 0,
				'participantFlags' => $session->getInCall(),
				'lastPing' => $session->getLastPing(),
				'sessionId' => $session->getSessionId(),
			]);
		}

		if ($roomData['notificationLevel'] === Participant::NOTIFY_DEFAULT) {
			if ($currentParticipant->isGuest()) {
				$roomData['notificationLevel'] = Participant::NOTIFY_NEVER;
			} elseif ($room->getType() === Room::ONE_TO_ONE_CALL) {
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

		$roomData['canStartCall'] = $currentParticipant->canStartCall($this->config);

		if ($userId !== '') {
			$currentUser = $this->userManager->get($userId);
			if ($currentUser instanceof IUser) {
				$lastReadMessage = $attendee->getLastReadMessage();
				if ($lastReadMessage === -1) {
					/*
					 * Because the migration from the old comment_read_markers was
					 * not possible in a programmatic way with a reasonable O(1) or O(n)
					 * but only with O(user×chat), we do the conversion here.
					 */
					$lastReadMessage = $this->chatManager->getLastReadMessageFromLegacy($room, $currentUser);
					$this->participantService->updateLastReadMessage($currentParticipant, $lastReadMessage);
				}
				$roomData['unreadMessages'] = $this->chatManager->getUnreadCount($room, $lastReadMessage);

				$lastMention = $attendee->getLastMentionMessage();
				$roomData['unreadMention'] = $lastMention !== 0 && $lastReadMessage < $lastMention;
				$roomData['lastReadMessage'] = $lastReadMessage;
			}
		}

		$numActiveGuests = 0;
		$cleanGuests = false;
		$participantList = [];
		$participants = $this->participantService->getParticipantsForRoom($room);
		uasort($participants, function (Participant $participant1, Participant $participant2) {
			$s1 = $participant1->getSession() ? $participant1->getSession()->getLastPing() : 0;
			$s2 = $participant2->getSession() ? $participant2->getSession()->getLastPing() : 0;
			return $s2 - $s1;
		});

		foreach ($participants as $participant) {
			/** @var Participant $participant */
			if ($participant->isGuest()) {
				if ($participant->getSession()) {
					if ($participant->getSession()->getLastPing() <= $this->timeFactory->getTime() - 100) {
						$cleanGuests = true;
					} else {
						$numActiveGuests++;
					}
				}
			} elseif ($participant->getAttendee()->getActorType() === Attendee::ACTOR_USERS) {
				$attendee = $participant->getAttendee();
				$session = $participant->getSession();
				$user = $this->userManager->get($attendee->getActorId());
				if ($user instanceof IUser) {
					$participantList[(string)$user->getUID()] = [
						'name' => $user->getDisplayName(),
						'type' => $attendee->getParticipantType(),
						'call' => $session ? $session->getInCall() : Participant::FLAG_DISCONNECTED,
						'sessionId' => $session ? $session->getSessionId() : '0',
					];

					if ($room->getType() === Room::ONE_TO_ONE_CALL &&
						  $user->getUID() !== $currentParticipant->getAttendee()->getActorId()) {
						// FIXME This should not be done, but currently all the clients use it to get the avatar of the user …
						$roomData['name'] = $user->getUID();
					}
				}

				if ($session && $session->getLastPing() <= $this->timeFactory->getTime() - 100) {
					$this->participantService->leaveRoomAsSession($room, $participant);
				}
			}
		}

		if ($cleanGuests) {
			$this->participantService->cleanGuestParticipants($room);
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
	 * @param Participant|null $currentParticipant
	 * @param bool $isSIPBridgeRequest
	 * @return array
	 * @throws RoomNotFoundException
	 */
	protected function formatRoomV2andV3(Room $room, ?Participant $currentParticipant, bool $isSIPBridgeRequest = false): array {
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
			'lobbyState' => Webinary::LOBBY_NONE,
			'lobbyTimer' => 0,
			'lastPing' => 0,
			'sessionId' => '0',
			'guestList' => '',
			'lastMessage' => [],
		];
		if ($this->getAPIVersion() >= 3) {
			$roomData = array_merge($roomData, [
				'sipEnabled' => Webinary::SIP_DISABLED,
				'actorType' => '',
				'actorId' => '',
				'attendeeId' => 0,
				'canEnableSIP' => false,
				'attendeePin' => '',
				'description' => '',
				'avatarId' => '',
				'avatarVersion' => 0,
				'lastCommonReadMessage' => 0,
				'listable' => Room::LISTABLE_NONE,
			]);
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

		if ($isSIPBridgeRequest
			|| ($room->getListable() !== Room::LISTABLE_NONE && !$currentParticipant instanceof Participant)
		) {
			return array_merge($roomData, [
				'name' => $room->getName(),
				'displayName' => $room->getDisplayName($isSIPBridgeRequest ? '' : $this->userId),
				'objectType' => $room->getObjectType(),
				'objectId' => $room->getObjectId(),
				'readOnly' => $room->getReadOnly(),
				'hasCall' => $room->getActiveSince() instanceof \DateTimeInterface,
				'lastActivity' => $lastActivity,
				'lobbyState' => $room->getLobbyState(),
				'lobbyTimer' => $lobbyTimer,
				'sipEnabled' => $room->getSIPEnabled(),
				'listable' => $room->getListable(),
			]);
		}

		if (!$currentParticipant instanceof Participant) {
			return $roomData;
		}

		$attendee = $currentParticipant->getAttendee();
		$userId = $attendee->getActorType() === Attendee::ACTOR_USERS ? $attendee->getActorId() : '';

		$roomData = array_merge($roomData, [
			'name' => $room->getName(),
			'displayName' => $room->getDisplayName($userId),
			'objectType' => $room->getObjectType(),
			'objectId' => $room->getObjectId(),
			'participantType' => $attendee->getParticipantType(),
			'readOnly' => $room->getReadOnly(),
			'hasCall' => $room->getActiveSince() instanceof \DateTimeInterface,
			'lastActivity' => $lastActivity,
			'isFavorite' => $attendee->isFavorite(),
			'notificationLevel' => $attendee->getNotificationLevel(),
			'lobbyState' => $room->getLobbyState(),
			'lobbyTimer' => $lobbyTimer,
		]);
		if ($this->getAPIVersion() >= 3) {
			if ($this->talkConfig->isSIPConfigured()) {
				$roomData['sipEnabled'] = $room->getSIPEnabled();
				if ($room->getSIPEnabled() === Webinary::SIP_ENABLED) {
					// Generate a PIN if the attendee is a user and doesn't have one.
					$this->participantService->generatePinForParticipant($room, $currentParticipant);

					$roomData['attendeePin'] = $attendee->getPin();
				}
			}

			$roomData = array_merge($roomData, [
				'actorType' => $attendee->getActorType(),
				'actorId' => $attendee->getActorId(),
				'attendeeId' => $attendee->getId(),
				'description' => $room->getDescription(),
				'avatarId' => $room->getAvatarId(),
				'avatarVersion' => $room->getAvatarVersion(),
				'listable' => $room->getListable(),
			]);

			if ($currentParticipant->getAttendee()->getReadPrivacy() === Participant::PRIVACY_PUBLIC) {
				if (isset($this->commonReadMessages[$room->getId()])) {
					$roomData['lastCommonReadMessage'] = $this->commonReadMessages[$room->getId()];
				} else {
					$roomData['lastCommonReadMessage'] = $this->chatManager->getLastCommonReadMessage($room);
				}
			}
		}

		$session = $currentParticipant->getSession();
		if ($session instanceof Session) {
			$roomData = array_merge($roomData, [
				'participantFlags' => $session->getInCall(),
				'lastPing' => $session->getLastPing(),
				'sessionId' => $session->getSessionId(),
			]);
		}

		if ($roomData['notificationLevel'] === Participant::NOTIFY_DEFAULT) {
			if ($currentParticipant->isGuest()) {
				$roomData['notificationLevel'] = Participant::NOTIFY_NEVER;
			} elseif ($room->getType() === Room::ONE_TO_ONE_CALL) {
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

		$roomData['canStartCall'] = $currentParticipant->canStartCall($this->config);

		if ($attendee->getActorType() === Attendee::ACTOR_USERS) {
			$currentUser = $this->userManager->get($attendee->getActorId());
			if ($currentUser instanceof IUser) {
				$lastReadMessage = $attendee->getLastReadMessage();
				if ($lastReadMessage === -1) {
					/*
					 * Because the migration from the old comment_read_markers was
					 * not possible in a programmatic way with a reasonable O(1) or O(n)
					 * but only with O(user×chat), we do the conversion here.
					 */
					$lastReadMessage = $this->chatManager->getLastReadMessageFromLegacy($room, $currentUser);
					$this->participantService->updateLastReadMessage($currentParticipant, $lastReadMessage);
				}
				if ($room->getLastMessage() && $lastReadMessage === (int) $room->getLastMessage()->getId()) {
					// When the last message is the last read message, there are no unread messages,
					// so we can save the query.
					$roomData['unreadMessages'] = 0;
				} else {
					$roomData['unreadMessages'] = $this->chatManager->getUnreadCount($room, $lastReadMessage);
				}

				$lastMention = $attendee->getLastMentionMessage();
				$roomData['unreadMention'] = $lastMention !== 0 && $lastReadMessage < $lastMention;
				$roomData['lastReadMessage'] = $lastReadMessage;

				$roomData['canDeleteConversation'] = $room->getType() !== Room::ONE_TO_ONE_CALL
					&& $currentParticipant->hasModeratorPermissions(false);
				$roomData['canLeaveConversation'] = true;
				if ($this->getAPIVersion() >= 3) {
					$roomData['canEnableSIP'] =
						$this->talkConfig->isSIPConfigured()
						&& !preg_match(Room::SIP_INCOMPATIBLE_REGEX, $room->getToken())
						&& ($room->getType() === Room::GROUP_CALL || $room->getType() === Room::PUBLIC_CALL)
						&& $currentParticipant->hasModeratorPermissions(false)
						&& $this->talkConfig->canUserEnableSIP($currentUser);
				}
			}
		}

		// FIXME This should not be done, but currently all the clients use it to get the avatar of the user …
		if ($room->getType() === Room::ONE_TO_ONE_CALL) {
			$participants = json_decode($room->getName(), true);
			foreach ($participants as $participant) {
				if ($participant !== $attendee->getActorId()) {
					$roomData['name'] = $participant;
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
	 * @param string $targetUserId
	 * @return DataResponse
	 */
	protected function createOneToOneRoom(string $targetUserId): DataResponse {
		$currentUser = $this->userManager->get($this->userId);
		if (!$currentUser instanceof IUser) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$targetUser = $this->userManager->get($targetUserId);
		if (!$targetUser instanceof IUser) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			// We are only doing this manually here to be able to return different status codes
			// Actually createOneToOneConversation also checks it.
			$room = $this->manager->getOne2OneRoom($currentUser->getUID(), $targetUser->getUID());
			$this->participantService->ensureOneToOneRoomIsFilled($room);
			return new DataResponse(
				$this->formatRoom($room, $room->getParticipant($currentUser->getUID())),
				Http::STATUS_OK
			);
		} catch (RoomNotFoundException $e) {
		}

		try {
			$room = $this->roomService->createOneToOneConversation($currentUser, $targetUser);
			return new DataResponse(
				$this->formatRoom($room, $room->getParticipant($currentUser->getUID())),
				Http::STATUS_CREATED
			);
		} catch (InvalidArgumentException $e) {
			// Same current and target user
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		} catch (RoomNotFoundException $e) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
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
		$currentUser = $this->userManager->get($this->userId);
		if (!$currentUser instanceof IUser) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$targetGroup = $this->groupManager->get($targetGroupName);
		if (!$targetGroup instanceof IGroup) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		// Create the room
		$name = $this->roomService->prepareConversationName($targetGroup->getDisplayName());
		$room = $this->roomService->createConversation(Room::GROUP_CALL, $name, $currentUser);

		$usersInGroup = $targetGroup->getUsers();
		$participants = [];
		foreach ($usersInGroup as $user) {
			if ($currentUser->getUID() === $user->getUID()) {
				// Owner is already added.
				continue;
			}

			$participants[] = [
				'actorType' => Attendee::ACTOR_USERS,
				'actorId' => $user->getUID(),
			];
		}

		$this->participantService->addUsers($room, $participants);

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

		$currentUser = $this->userManager->get($this->userId);
		if (!$currentUser instanceof IUser) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		/** @var Circles $circlesApi */
		try {
			$circle = Circles::detailsCircle($targetCircleId);
		} catch (\Exception $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		// Create the room
		$name = $this->roomService->prepareConversationName($circle->getName());
		$room = $this->roomService->createConversation(Room::GROUP_CALL, $name, $currentUser);

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
				'actorType' => Attendee::ACTOR_USERS,
				'actorId' => $member->getUserId(),
			];
		}

		$this->participantService->addUsers($room, $participants);

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
		$currentUser = $this->userManager->get($this->userId);
		if (!$currentUser instanceof IUser) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$roomType = $public ? Room::PUBLIC_CALL : Room::GROUP_CALL;

		// Create the room
		try {
			$room = $this->roomService->createConversation($roomType, $roomName, $currentUser);
		} catch (InvalidArgumentException $e) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse($this->formatRoom($room, $room->getParticipant($currentUser->getUID())), Http::STATUS_CREATED);
	}

	/**
	 * @NoAdminRequired
	 * @RequireLoggedInParticipant
	 *
	 * @return DataResponse
	 */
	public function addToFavorites(): DataResponse {
		$this->participantService->updateFavoriteStatus($this->participant, true);
		return new DataResponse([]);
	}

	/**
	 * @NoAdminRequired
	 * @RequireLoggedInParticipant
	 *
	 * @return DataResponse
	 */
	public function removeFromFavorites(): DataResponse {
		$this->participantService->updateFavoriteStatus($this->participant, false);
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
		try {
			$this->participantService->updateNotificationLevel($this->participant, $level);
		} catch (\InvalidArgumentException $e) {
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
	 * @param string $description
	 * @return DataResponse
	 */
	public function setDescription(string $description): DataResponse {
		if ($this->room->getType() === Room::ONE_TO_ONE_CALL) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		try {
			$this->room->setDescription($description);
		} catch (\LengthException $exception) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

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
	 * @param bool $includeStatus
	 * @return DataResponse
	 */
	public function getParticipants(bool $includeStatus = false): DataResponse {
		if ($this->participant->getAttendee()->getParticipantType() === Participant::GUEST) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}

		$maxPingAge = $this->timeFactory->getTime() - 100;
		$participants = $this->participantService->getParticipantsForRoom($this->room);
		$results = $headers = $statuses = [];

		if ($this->userId !== null
			&& $includeStatus
			&& count($participants) < 100
			&& $this->appManager->isEnabledForUser('user_status')) {
			$userIds = array_filter(array_map(static function (Participant $participant) {
				if ($participant->getAttendee()->getActorType() === Attendee::ACTOR_USERS) {
					return $participant->getAttendee()->getActorId();
				}
				return null;
			}, $participants));

			$statuses = $this->statusManager->getUserStatuses($userIds);

			$headers['X-Nextcloud-Has-User-Statuses'] = true;
		}

		$guestSessions = array_filter(array_map(static function (Participant $participant) {
			$session = $participant->getSession();
			if (!$session || $participant->getAttendee()->getActorType() !== Attendee::ACTOR_GUESTS) {
				return null;
			}

			return sha1($session->getSessionId());
		}, $participants));

		$cleanGuests = false;
		$guestNames = $this->guestManager->getNamesBySessionHashes($guestSessions);

		/** @var Participant[] $participants */
		foreach ($participants as $participant) {
			$result = [
				'inCall' => Participant::FLAG_DISCONNECTED,
				'lastPing' => 0,
				'sessionId' => '0', // FIXME empty string or null?
				'participantType' => $participant->getAttendee()->getParticipantType(),
			];
			if ($this->getAPIVersion() >= 3) {
				$result['attendeeId'] = $participant->getAttendee()->getId();
				$result['actorId'] = $participant->getAttendee()->getActorId();
				$result['actorType'] = $participant->getAttendee()->getActorType();
				$result['attendeePin'] = '';
				if ($this->talkConfig->isSIPConfigured()
					&& $this->room->getSIPEnabled() === Webinary::SIP_ENABLED
					&& ($this->participant->hasModeratorPermissions(false)
						|| $this->participant->getAttendee()->getId() === $participant->getAttendee()->getId())) {
					// Generate a PIN if the attendee is a user and doesn't have one.
					$this->participantService->generatePinForParticipant($this->room, $participant);

					$result['attendeePin'] = (string) $participant->getAttendee()->getPin();
				}
			}
			if ($participant->getSession() instanceof Session) {
				$result['inCall'] = $participant->getSession()->getInCall();
				$result['lastPing'] = $participant->getSession()->getLastPing();
				$result['sessionId'] = $participant->getSession()->getSessionId();
			}

			if ($participant->getAttendee()->getActorType() === Attendee::ACTOR_USERS) {
				$userId = $participant->getAttendee()->getActorId();
				$user = $this->userManager->get($userId);
				if (!$user instanceof IUser) {
					continue;
				}

				if ($result['lastPing'] > 0 && $result['lastPing'] <= $maxPingAge) {
					$this->participantService->leaveRoomAsSession($this->room, $participant);
				}

				if ($this->getAPIVersion() < 3) {
					$result['userId'] = $participant->getAttendee()->getActorId();
				}
				$result['displayName'] = (string) $user->getDisplayName();

				if (isset($statuses[$userId])) {
					$result['status'] = $statuses[$userId]->getStatus();
					$result['statusIcon'] = $statuses[$userId]->getIcon();
					$result['statusMessage'] = $statuses[$userId]->getMessage();
					$result['statusClearAt'] = $statuses[$userId]->getClearAt();
				}
			} elseif ($participant->getAttendee()->getActorType() === Attendee::ACTOR_GUESTS) {
				if ($result['lastPing'] <= $maxPingAge) {
					$cleanGuests = true;
					continue;
				}

				if ($this->getAPIVersion() < 3) {
					$result['userId'] = '';
				}
				$result['displayName'] = $guestNames[$participant->getAttendee()->getActorId()] ?? '';
			} elseif ($this->getAPIVersion() >= 3) {
				// Other types are only reported on v3 or later
				$result['displayName'] = $participant->getAttendee()->getActorId();
			} else {
				// Skip unknown actor types
				continue;
			}

			$results[] = $result;
		}

		if ($cleanGuests) {
			$this->participantService->cleanGuestParticipants($this->room);
		}

		return new DataResponse($results, Http::STATUS_OK, $headers);
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
		if ($this->room->getType() === Room::ONE_TO_ONE_CALL || $this->room->getObjectType() === 'share:password') {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$participants = $this->participantService->getParticipantUserIds($this->room);

		$participantsToAdd = [];
		if ($source === 'users') {
			$newUser = $this->userManager->get($newParticipant);
			if (!$newUser instanceof IUser) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			if (\in_array($newParticipant, $participants, true)) {
				return new DataResponse([]);
			}

			$this->participantService->addUsers($this->room, [[
				'actorType' => Attendee::ACTOR_USERS,
				'actorId' => $newUser->getUID(),
			]]);
		} elseif ($source === 'groups') {
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
					'actorType' => Attendee::ACTOR_USERS,
					'actorId' => $user->getUID(),
				];
			}

			if (empty($participantsToAdd)) {
				return new DataResponse([]);
			}

			$this->participantService->addUsers($this->room, $participantsToAdd);
		} elseif ($source === 'circles') {
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
					'actorType' => Attendee::ACTOR_USERS,
					'actorId' => $member->getUserId(),
				];
			}

			if (empty($participantsToAdd)) {
				return new DataResponse([]);
			}

			$this->participantService->addUsers($this->room, $participantsToAdd);
		} elseif ($source === 'emails') {
			$data = [];
			if ($this->room->setType(Room::PUBLIC_CALL)) {
				$data = ['type' => $this->room->getType()];
			}

			$participant = $this->participantService->inviteEmailAddress($this->room, $newParticipant);

			$this->guestManager->sendEmailInvitation($this->room, $participant);

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
		$attendee = $this->participant->getAttendee();
		if ($attendee->getActorType() === Attendee::ACTOR_USERS && $attendee->getActorId() === $participant) {
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

		if ($targetParticipant->getAttendee()->getParticipantType() === Participant::OWNER) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}

		$targetUser = $this->userManager->get($participant);
		if (!$targetUser instanceof IUser) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$this->participantService->removeUser($this->room, $targetUser, Room::PARTICIPANT_REMOVED);
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
				&& $this->participantService->getNumberOfUsers($room) > 1
				&& $this->participantService->getNumberOfModerators($room) === 1) {
				return new DataResponse([], Http::STATUS_BAD_REQUEST);
			}
		}

		if ($room->getType() !== Room::CHANGELOG_CONVERSATION &&
			$room->getObjectType() !== 'file' &&
			$this->participantService->getNumberOfUsers($room) === 1) {
			$room->deleteRoom();
			return new DataResponse();
		}

		$currentUser = $this->userManager->get($this->userId);
		if (!$currentUser instanceof IUser) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$this->participantService->removeUser($room, $currentUser, Room::PARTICIPANT_LEFT);

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

		$targetSession = $targetParticipant->getSession();
		$currentSession = $this->participant->getSession();
		if ($targetSession instanceof Session
			&& $currentSession instanceof Session
			&& $targetSession->getSessionId() === $currentSession->getSessionId()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}

		$this->participantService->removeAttendee($this->room, $targetParticipant, Room::PARTICIPANT_REMOVED);
		return new DataResponse([]);
	}

	/**
	 * @PublicPage
	 * @RequireModeratorParticipant
	 *
	 * @param int $attendeeId
	 * @return DataResponse
	 */
	public function removeAttendeeFromRoom(int $attendeeId): DataResponse {
		try {
			$targetParticipant = $this->room->getParticipantByAttendeeId($attendeeId);
		} catch (ParticipantNotFoundException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		if ($this->room->getType() === Room::ONE_TO_ONE_CALL) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		if ($this->participant->getAttendee()->getId() === $targetParticipant->getAttendee()->getId()) {
			// FIXME switch to removeSelfFromRoomLogic()
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}

		if ($targetParticipant->getAttendee()->getParticipantType() === Participant::OWNER) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}

		$this->participantService->removeAttendee($this->room, $targetParticipant, Room::PARTICIPANT_REMOVED);
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
	 * @NoAdminRequired
	 * @RequireModeratorParticipant
	 *
	 * @param int $state
	 * @return DataResponse
	 */
	public function setListable(int $scope): DataResponse {
		if (!$this->room->setListable($scope)) {
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
	 * @param bool $force
	 * @return DataResponse
	 */
	public function joinRoom(string $token, string $password = '', bool $force = true): DataResponse {
		try {
			$room = $this->manager->getRoomForUserByToken($token, $this->userId);
		} catch (RoomNotFoundException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		/** @var Participant|null $previousSession */
		$previousParticipant = null;
		/** @var Session|null $previousSession */
		$previousSession = null;
		if ($this->userId !== null) {
			try {
				$previousParticipant = $room->getParticipant($this->userId);
				$previousSession = $previousParticipant->getSession();
			} catch (ParticipantNotFoundException $e) {
			}
		} else {
			$sessionForToken = $this->session->getSessionForRoom($token);
			try {
				$previousParticipant = $room->getParticipantBySession($sessionForToken);
				$previousSession = $previousParticipant->getSession();
			} catch (ParticipantNotFoundException $e) {
			}
		}

		if ($previousSession instanceof Session && $previousSession->getSessionId() !== '0') {
			if ($force === false && $previousSession->getInCall() !== Participant::FLAG_DISCONNECTED) {
				// Previous session was active in the call, show a warning
				return new DataResponse([
					'sessionId' => $previousSession->getSessionId(),
					'inCall' => $previousSession->getInCall(),
					'lastPing' => $previousSession->getLastPing(),
				], Http::STATUS_CONFLICT);
			}

			if ($previousSession->getInCall() !== Participant::FLAG_DISCONNECTED) {
				$this->participantService->changeInCall($room, $previousParticipant, Participant::FLAG_DISCONNECTED);
			}

			$this->participantService->leaveRoomAsSession($room, $previousParticipant);
		}

		$user = $this->userManager->get($this->userId);
		try {
			$result = $room->verifyPassword((string) $this->session->getPasswordForRoom($token));
			if ($user instanceof IUser) {
				$participant = $this->participantService->joinRoom($room, $user, $password, $result['result']);
				$this->participantService->generatePinForParticipant($room, $participant);
			} else {
				$participant = $this->participantService->joinRoomAsNewGuest($room, $password, $result['result']);
			}
		} catch (InvalidPasswordException $e) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		} catch (UnauthorizedException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$this->session->removePasswordForRoom($token);
		$session = $participant->getSession();
		if ($session instanceof Session) {
			$this->session->setSessionForRoom($token, $session->getSessionId());
			$this->sessionService->updateLastPing($session, $this->timeFactory->getTime());
		}

		return new DataResponse($this->formatRoom($room, $participant));
	}

	/**
	 * @PublicPage
	 * @RequireRoom
	 *
	 * @param string $pin
	 * @return DataResponse
	 */
	public function getParticipantByDialInPin(string $pin): DataResponse {
		try {
			if (!$this->validateSIPBridgeRequest($this->room->getToken())) {
				return new DataResponse([], Http::STATUS_UNAUTHORIZED);
			}
		} catch (UnauthorizedException $e) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$participant = $this->room->getParticipantByPin($pin);
		} catch (ParticipantNotFoundException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse($this->formatRoom($this->room, $participant));
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
			$room = $this->manager->getRoomForUserByToken($token, $this->userId);
			$participant = $room->getParticipantBySession($sessionId);
			$this->participantService->leaveRoomAsSession($room, $participant);
		} catch (RoomNotFoundException $e) {
		} catch (ParticipantNotFoundException $e) {
		}

		return new DataResponse();
	}

	/**
	 * @PublicPage
	 * @RequireModeratorParticipant
	 *
	 * @param int|null $attendeeId
	 * @param string|null $participant
	 * @param string|null $sessionId
	 * @return DataResponse
	 */
	public function promoteModerator(?int $attendeeId, ?string $participant, ?string $sessionId): DataResponse {
		return $this->changeParticipantType($attendeeId, $participant, $sessionId, true);
	}

	/**
	 * @PublicPage
	 * @RequireModeratorParticipant
	 *
	 * @param int|null $attendeeId
	 * @param string|null $participant
	 * @param string|null $sessionId
	 * @return DataResponse
	 */
	public function demoteModerator(?int $attendeeId, ?string $participant, ?string $sessionId): DataResponse {
		return $this->changeParticipantType($attendeeId, $participant, $sessionId, false);
	}

	/**
	 * Toggle a user/guest to moderator/guest-moderator or vice-versa based on
	 * attendeeId (v3) or userId/sessionId (v1+v2)
	 *
	 * @param int|null $attendeeId
	 * @param string|null $userId
	 * @param string|null $sessionId
	 * @param bool $promote Shall the attendee be promoted or demoted
	 * @return DataResponse
	 */
	protected function changeParticipantType(?int $attendeeId, ?string $userId, ?string $sessionId, bool $promote): DataResponse {
		try {
			if ($attendeeId !== null) {
				$targetParticipant = $this->room->getParticipantByAttendeeId($attendeeId);
			} elseif ($userId !== null) {
				$targetParticipant = $this->room->getParticipant($userId);
			} else {
				$targetParticipant = $this->room->getParticipantBySession($sessionId);
			}
		} catch (ParticipantNotFoundException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$attendee = $targetParticipant->getAttendee();

		// Prevent users/moderators modifying themselves
		if ($attendee->getActorType() === Attendee::ACTOR_USERS) {
			if ($attendee->getActorId() === $this->userId) {
				return new DataResponse([], Http::STATUS_FORBIDDEN);
			}
		} elseif ($attendee->getActorType() === Attendee::ACTOR_GUESTS) {
			$session = $targetParticipant->getSession();
			$currentSessionId = $this->session->getSessionForRoom($this->room->getToken());

			if ($session instanceof Session && $currentSessionId === $session->getSessionId()) {
				return new DataResponse([], Http::STATUS_FORBIDDEN);
			}
		}

		if ($promote === $targetParticipant->hasModeratorPermissions()) {
			// Prevent concurrent changes
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		if ($attendee->getParticipantType() === Participant::USER) {
			$newType = Participant::MODERATOR;
		} elseif ($attendee->getParticipantType() === Participant::GUEST) {
			$newType = Participant::GUEST_MODERATOR;
		} elseif ($attendee->getParticipantType() === Participant::MODERATOR) {
			$newType = Participant::USER;
		} elseif ($attendee->getParticipantType() === Participant::GUEST_MODERATOR) {
			$newType = Participant::GUEST;
		} else {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$this->participantService->updateParticipantType($this->room, $targetParticipant, $newType);

		return new DataResponse();
	}

	/**
	 * @NoAdminRequired
	 * @RequireModeratorParticipant
	 *
	 * @param int $state
	 * @param int|null $timer
	 * @return DataResponse
	 */
	public function setLobby(int $state, ?int $timer = null): DataResponse {
		$timerDateTime = null;
		if ($timer !== null && $timer > 0) {
			try {
				$timerDateTime = $this->timeFactory->getDateTime('@' . $timer);
				$timerDateTime->setTimezone(new \DateTimeZone('UTC'));
			} catch (\Exception $e) {
				return new DataResponse([], Http::STATUS_BAD_REQUEST);
			}
		}

		if (!$this->room->setLobby($state, $timerDateTime)) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		if ($state === Webinary::LOBBY_NON_MODERATORS) {
			$participants = $this->participantService->getParticipantsInCall($this->room);
			foreach ($participants as $participant) {
				if ($participant->hasModeratorPermissions()) {
					continue;
				}

				$this->participantService->changeInCall($this->room, $participant, Participant::FLAG_DISCONNECTED);
			}
		}

		return new DataResponse($this->formatRoomV2andV3($this->room, $this->participant));
	}

	/**
	 * @NoAdminRequired
	 * @RequireModeratorParticipant
	 *
	 * @param int $state
	 * @return DataResponse
	 */
	public function setSIPEnabled(int $state): DataResponse {
		$user = $this->userManager->get($this->userId);
		if (!$user instanceof IUser) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if (!$this->talkConfig->canUserEnableSIP($user)) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}

		if (!$this->talkConfig->isSIPConfigured()) {
			return new DataResponse([], Http::STATUS_PRECONDITION_FAILED);
		}

		if (!$this->room->setSIPEnabled($state)) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse($this->formatRoomV2andV3($this->room, $this->participant));
	}
}
