<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2016 Lukas Reschke <lukas@statuscode.ch>
 *
 * @author Lukas Reschke <lukas@statuscode.ch>
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

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCA\Talk\Config;
use OCA\Talk\Events\SignalingEvent;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Session;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\SessionService;
use OCA\Talk\Signaling\Messages;
use OCA\Talk\TalkSession;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;

class SignalingController extends OCSController {

	/** @var int */
	private const PULL_MESSAGES_TIMEOUT = 30;

	public const EVENT_BACKEND_SIGNALING_ROOMS = self::class . '::signalingBackendRoom';

	/** @var Config */
	private $talkConfig;
	/** @var \OCA\Talk\Signaling\Manager */
	private $signalingManager;
	/** @var TalkSession */
	private $session;
	/** @var Manager */
	private $manager;
	/** @var ParticipantService */
	private $participantService;
	/** @var SessionService */
	private $sessionService;
	/** @var IDBConnection */
	private $dbConnection;
	/** @var Messages */
	private $messages;
	/** @var IUserManager */
	private $userManager;
	/** @var IEventDispatcher */
	private $dispatcher;
	/** @var ITimeFactory */
	private $timeFactory;
	/** @var IClientService */
	private $clientService;
	/** @var string|null */
	private $userId;

	public function __construct(string $appName,
								IRequest $request,
								Config $talkConfig,
								\OCA\Talk\Signaling\Manager $signalingManager,
								TalkSession $session,
								Manager $manager,
								ParticipantService $participantService,
								SessionService $sessionService,
								IDBConnection $connection,
								Messages $messages,
								IUserManager $userManager,
								IEventDispatcher $dispatcher,
								ITimeFactory $timeFactory,
								IClientService $clientService,
								?string $UserId) {
		parent::__construct($appName, $request);
		$this->talkConfig = $talkConfig;
		$this->signalingManager = $signalingManager;
		$this->session = $session;
		$this->dbConnection = $connection;
		$this->manager = $manager;
		$this->participantService = $participantService;
		$this->sessionService = $sessionService;
		$this->messages = $messages;
		$this->userManager = $userManager;
		$this->dispatcher = $dispatcher;
		$this->timeFactory = $timeFactory;
		$this->clientService = $clientService;
		$this->userId = $UserId;
	}

	/**
	 * @PublicPage
	 *
	 * @param string $apiVersion
	 * @param string $token
	 * @return DataResponse
	 */
	public function getSettings(string $apiVersion, string $token = ''): DataResponse {
		$apiV = (int) substr($apiVersion, 1);

		try {
			if ($token !== '') {
				$room = $this->manager->getRoomForUserByToken($token, $this->userId);
			} else {
				// FIXME Soft-fail for legacy support in mobile apps
				$room = null;
			}
		} catch (RoomNotFoundException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$stun = [];
		foreach ($this->talkConfig->getStunServers() as $stunServer) {
			if (strtolower(substr($stunServer, 0, 5)) !== 'stun:' && strtolower(substr($stunServer, 0, 6)) !== 'stuns:') {
				$stunServer = 'stun:' . $stunServer;
			}
			$stun[] = [
				'url' => $stunServer,
			];
		}

		$turn = [];
		foreach ($this->talkConfig->getTurnSettings() as $turnSettings) {
			$protocols = explode(',', $turnSettings['protocols']);
			foreach ($protocols as $proto) {
				$server = $turnSettings['server'];
				if (strtolower(substr($server, 0, 5)) !== 'turn:' && strtolower(substr($server, 0, 6)) !== 'turns:') {
					$server = 'turn:' . $server;
				}
				$turn[] = [
					'url' => [$server . '?transport=' . $proto],
					'urls' => [$server . '?transport=' . $proto],
					'username' => $turnSettings['username'],
					'credential' => $turnSettings['password'],
				];
			}
		}

		$signalingMode = $this->talkConfig->getSignalingMode();
		$signaling = $this->signalingManager->getSignalingServerLinkForConversation($room);

		$data = [
			'signalingMode' => $signalingMode,
			'userId' => $this->userId,
			'hideWarning' => $signaling !== '' || $this->talkConfig->getHideSignalingWarning(),
			'server' => $signaling,
			'ticket' => $this->talkConfig->getSignalingTicket($this->userId),
			'stunservers' => $stun,
			'turnservers' => $turn,
		];

		if ($apiV >= 2) {
			$data['sipDialinInfo'] = $this->talkConfig->isSIPConfigured() ? $this->talkConfig->getDialInInfo() : '';
		}

		return new DataResponse($data);
	}

	/**
	 * Only available for logged in users because guests can not use the apps
	 * right now.
	 *
	 * @param int $serverId
	 * @return DataResponse
	 */
	public function getWelcomeMessage(int $serverId): DataResponse {
		$signalingServers = $this->talkConfig->getSignalingServers();
		if (empty($signalingServers) || !isset($signalingServers[$serverId])) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$url = rtrim($signalingServers[$serverId]['server'], '/');

		if (strpos($url, 'wss://') === 0) {
			$url = 'https://' . substr($url, 6);
		}

		if (strpos($url, 'ws://') === 0) {
			$url = 'http://' . substr($url, 5);
		}

		$client = $this->clientService->newClient();
		try {
			$response = $client->get($url . '/api/v1/welcome', [
				'verify' => (bool) $signalingServers[$serverId]['verify'],
				'nextcloud' => [
					'allow_local_address' => true,
				],
			]);

			$body = $response->getBody();

			$data = json_decode($body, true);
			if (!is_array($data) || !isset($data['version'])) {
				return new DataResponse(['error' => 'JSON_INVALID'], Http::STATUS_INTERNAL_SERVER_ERROR);
			}

			return new DataResponse($data);
		} catch (\GuzzleHttp\Exception\ConnectException $e) {
			return new DataResponse(['error' => 'CAN_NOT_CONNECT'], Http::STATUS_INTERNAL_SERVER_ERROR);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getCode()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * @PublicPage
	 *
	 * @param string $token
	 * @param string $messages
	 * @return DataResponse
	 */
	public function signaling(string $token, string $messages): DataResponse {
		if ($this->talkConfig->getSignalingMode() !== Config::SIGNALING_INTERNAL) {
			return new DataResponse('Internal signaling disabled.', Http::STATUS_BAD_REQUEST);
		}

		$response = [];
		$messages = json_decode($messages, true);
		foreach ($messages as $message) {
			$ev = $message['ev'];
			switch ($ev) {
				case 'message':
					$fn = $message['fn'];
					if (!is_string($fn)) {
						break;
					}
					$decodedMessage = json_decode($fn, true);
					if ($message['sessionId'] !== $this->session->getSessionForRoom($token)) {
						break;
					}
					$decodedMessage['from'] = $message['sessionId'];

					if ($decodedMessage['type'] === 'control') {
						$room = $this->manager->getRoomForSession($this->userId, $message['sessionId']);
						$participant = $room->getParticipantBySession($message['sessionId']);

						if (!$participant->hasModeratorPermissions(false)) {
							break;
						}
					}

					$this->messages->addMessage($message['sessionId'], $decodedMessage['to'], json_encode($decodedMessage));

					break;
			}
		}

		return new DataResponse($response);
	}

	/**
	 * @PublicPage
	 *
	 * @param string $token
	 * @return DataResponse
	 */
	public function pullMessages(string $token): DataResponse {
		if ($this->talkConfig->getSignalingMode() !== Config::SIGNALING_INTERNAL) {
			return new DataResponse('Internal signaling disabled.', Http::STATUS_BAD_REQUEST);
		}

		$data = [];
		$seconds = self::PULL_MESSAGES_TIMEOUT;

		try {
			$sessionId = $this->session->getSessionForRoom($token);
			if ($sessionId === null) {
				// User is not active in this room
				return new DataResponse([['type' => 'usersInRoom', 'data' => []]], Http::STATUS_NOT_FOUND);
			}

			$room = $this->manager->getRoomForSession($this->userId, $sessionId);
			$participant = $room->getParticipantBySession($sessionId); // FIXME this causes another query

			$pingTimestamp = $this->timeFactory->getTime();
			if ($participant->getSession() instanceof Session) {
				$this->sessionService->updateLastPing($participant->getSession(), $pingTimestamp);
			}
		} catch (RoomNotFoundException $e) {
			return new DataResponse([['type' => 'usersInRoom', 'data' => []]], Http::STATUS_NOT_FOUND);
		}

		while ($seconds > 0) {
			// Query all messages and send them to the user
			$data = $this->messages->getAndDeleteMessages($sessionId);
			$messageCount = count($data);
			$data = array_filter($data, function ($message) {
				return $message['data'] !== 'refresh-participant-list';
			});

			if ($messageCount !== count($data)) {
				// Make sure the array is a json array not a json object,
				// because the index list has a gap
				$data = array_values($data);
				// Participant list changed, bail out and deliver the info to the user
				break;
			}

			$this->dbConnection->close();
			if (empty($data)) {
				$seconds--;
			} else {
				break;
			}
			sleep(1);

			// Refresh the session and retry
			$sessionId = $this->session->getSessionForRoom($token);
			if ($sessionId === null) {
				// User is not active in this room
				return new DataResponse([['type' => 'usersInRoom', 'data' => []]], Http::STATUS_NOT_FOUND);
			}
		}

		try {
			// Add an update of the room participants at the end of the waiting
			$room = $this->manager->getRoomForSession($this->userId, $sessionId);
			$data[] = ['type' => 'usersInRoom', 'data' => $this->getUsersInRoom($room, $pingTimestamp)];
		} catch (RoomNotFoundException $e) {
			$data[] = ['type' => 'usersInRoom', 'data' => []];

			// Was the session killed or the complete conversation?
			try {
				$room = $this->manager->getRoomForUserByToken($token, $this->userId);
				if ($this->userId) {
					// For logged in users we check if they are still part of the public conversation,
					// if not they were removed instead of having a conflict.
					$room->getParticipant($this->userId);
				}

				// Session was killed, make the UI redirect to an error
				return new DataResponse($data, Http::STATUS_CONFLICT);
			} catch (ParticipantNotFoundException $e) {
				// User removed from conversation, bye!
				return new DataResponse($data, Http::STATUS_NOT_FOUND);
			} catch (RoomNotFoundException $e) {
				// Complete conversation was killed, bye!
				return new DataResponse($data, Http::STATUS_NOT_FOUND);
			}
		}

		return new DataResponse($data);
	}

	/**
	 * @param Room $room
	 * @param int pingTimestamp
	 * @return array[]
	 */
	protected function getUsersInRoom(Room $room, int $pingTimestamp): array {
		$usersInRoom = [];
		// Get participants active in the last 40 seconds (an extra time is used
		// to include other participants pinging almost at the same time as the
		// current user), or since the last signaling ping of the current user
		// if it was done more than 40 seconds ago.
		$timestamp = min($this->timeFactory->getTime() - (self::PULL_MESSAGES_TIMEOUT + 10), $pingTimestamp);
		// "- 1" is needed because only the participants whose last ping is
		// greater than the given timestamp are returned.
		$participants = $this->participantService->getParticipantsForRoom($room);
		foreach ($participants as $participant) {
			$session = $participant->getSession();
			if (!$session instanceof Session) {
				// User is not active
				continue;
			}

			$userId = '';
			if ($participant->getAttendee()->getActorType() === Attendee::ACTOR_USERS) {
				$userId = $participant->getAttendee()->getActorId();
			}

			$usersInRoom[] = [
				'userId' => $userId,
				'roomId' => $room->getId(),
				'lastPing' => $session->getLastPing(),
				'sessionId' => $session->getSessionId(),
				'inCall' => $session->getInCall(),
			];
		}

		return $usersInRoom;
	}

	/**
	 * Check if the current request is coming from an allowed backend.
	 *
	 * The backends are sending the custom header "Talk-Signaling-Random"
	 * containing at least 32 bytes random data, and the header
	 * "Talk-Signaling-Checksum", which is the SHA256-HMAC of the random data
	 * and the body of the request, calculated with the shared secret from the
	 * configuration.
	 *
	 * @param string $data
	 * @return bool
	 */
	private function validateBackendRequest(string $data): bool {
		if (!isset($_SERVER['HTTP_SPREED_SIGNALING_RANDOM'],
			  $_SERVER['HTTP_SPREED_SIGNALING_CHECKSUM'])) {
			return false;
		}
		$random = $_SERVER['HTTP_SPREED_SIGNALING_RANDOM'];
		if (empty($random) || strlen($random) < 32) {
			return false;
		}
		$checksum = $_SERVER['HTTP_SPREED_SIGNALING_CHECKSUM'];
		if (empty($checksum)) {
			return false;
		}
		$hash = hash_hmac('sha256', $random . $data, $this->talkConfig->getSignalingSecret());
		return hash_equals($hash, strtolower($checksum));
	}

	/**
	 * Return the body of the backend request. This can be overridden in
	 * tests.
	 *
	 * @return string
	 */
	protected function getInputStream(): string {
		return file_get_contents('php://input');
	}

	/**
	 * Backend API to query information required for standalone signaling
	 * servers.
	 *
	 * See sections "Backend validation" in
	 * https://nextcloud-talk.readthedocs.io/en/latest/standalone-signaling-api-v1/#backend-validation
	 *
	 * @PublicPage
	 *
	 * @return DataResponse
	 */
	public function backend(): DataResponse {
		$json = $this->getInputStream();
		if (!$this->validateBackendRequest($json)) {
			return new DataResponse([
				'type' => 'error',
				'error' => [
					'code' => 'invalid_request',
					'message' => 'The request could not be authenticated.',
				],
			]);
		}

		$message = json_decode($json, true);
		switch ($message['type'] ?? '') {
			case 'auth':
				// Query authentication information about a user.
				return $this->backendAuth($message['auth']);
			case 'room':
				// Query information about a room.
				return $this->backendRoom($message['room']);
			case 'ping':
				// Ping sessions connected to a room.
				return $this->backendPing($message['ping']);
			default:
				return new DataResponse([
					'type' => 'error',
					'error' => [
						'code' => 'unknown_type',
						'message' => 'The given type ' . json_encode($message) . ' is not supported.',
					],
				]);
		}
	}

	private function backendAuth(array $auth): DataResponse {
		$params = $auth['params'];
		$userId = $params['userid'];
		if (!$this->talkConfig->validateSignalingTicket($userId, $params['ticket'])) {
			return new DataResponse([
				'type' => 'error',
				'error' => [
					'code' => 'invalid_ticket',
					'message' => 'The given ticket is not valid for this user.',
				],
			]);
		}

		if (!empty($userId)) {
			$user = $this->userManager->get($userId);
			if (!$user instanceof IUser) {
				return new DataResponse([
					'type' => 'error',
					'error' => [
						'code' => 'no_such_user',
						'message' => 'The given user does not exist.',
					],
				]);
			}
		}

		$response = [
			'type' => 'auth',
			'auth' => [
				'version' => '1.0',
			],
		];
		if (!empty($userId)) {
			$response['auth']['userid'] = $user->getUID();
			$response['auth']['user'] = [
				'displayname' => $user->getDisplayName(),
			];
		}
		return new DataResponse($response);
	}

	private function backendRoom(array $roomRequest): DataResponse {
		$token = $roomRequest['roomid']; // It's actually the room token
		$userId = $roomRequest['userid'];
		$sessionId = $roomRequest['sessionid'];
		$action = !empty($roomRequest['action']) ? $roomRequest['action'] : 'join';
		$actorId = $roomRequest['actorid'] ?? null;
		$actorType = $roomRequest['actortype'] ?? null;
		$inCall = $roomRequest['incall'] ?? null;

		$participant = null;
		if ($actorId !== null && $actorType !== null) {
			try {
				$room = $this->manager->getRoomByActor($token, $actorType, $actorId);
			} catch (RoomNotFoundException $e) {
				return new DataResponse([
					'type' => 'error',
					'error' => [
						'code' => 'no_such_room',
						'message' => 'The user is not invited to this room.',
					],
				]);
			}

			if ($sessionId) {
				try {
					$participant = $room->getParticipantBySession($sessionId);
				} catch (ParticipantNotFoundException $e) {
					if ($action === 'join') {
						// If the user joins the session might not be known to the server yet.
						// In this case we load by actor information and use the session id as new session.
						try {
							$participant = $room->getParticipantByActor($actorType, $actorId);
						} catch (ParticipantNotFoundException $e) {
						}
					}
				}
			} else {
				try {
					$participant = $room->getParticipantByActor($actorType, $actorId);
				} catch (ParticipantNotFoundException $e) {
				}
			}
		} else {
			try {
				// FIXME Don't preload with the user as that misses the session, kinda meh.
				$room = $this->manager->getRoomByToken($token);
			} catch (RoomNotFoundException $e) {
				return new DataResponse([
					'type' => 'error',
					'error' => [
						'code' => 'no_such_room',
						'message' => 'The user is not invited to this room.',
					],
				]);
			}

			if ($sessionId) {
				try {
					$participant = $room->getParticipantBySession($sessionId);
				} catch (ParticipantNotFoundException $e) {
				}
			} elseif (!empty($userId)) {
				// User trying to join room.
				try {
					$participant = $room->getParticipant($userId);
				} catch (ParticipantNotFoundException $e) {
				}
			}
		}

		if (!$participant instanceof Participant) {
			// Return generic error to avoid leaking which rooms exist.
			return new DataResponse([
				'type' => 'error',
				'error' => [
					'code' => 'no_such_room',
					'message' => 'The user is not invited to this room.',
				],
			]);
		}

		if ($action === 'join') {
			if ($sessionId && !$participant->getSession() instanceof Session) {
				try {
					$session = $this->sessionService->createSessionForAttendee($participant->getAttendee(), $sessionId);
				} catch (UniqueConstraintViolationException $e) {
					return new DataResponse([
						'type' => 'error',
						'error' => [
							'code' => 'duplicate_session',
							'message' => 'The given session is already in use.',
						],
					]);
				}
				$participant->setSession($session);
			}

			if ($participant->getSession() instanceof Session) {
				if ($inCall !== null) {
					$this->participantService->changeInCall($room, $participant, $inCall);
				}
				$this->sessionService->updateLastPing($participant->getSession(), $this->timeFactory->getTime());
			}
		} elseif ($action === 'leave') {
			// Guests are removed completely as they don't reuse attendees,
			// but this is only true for guests that joined directly.
			// Emails are retained as their PIN needs to remain and stay
			// valid.
			if ($participant->getAttendee()->getActorType() === Attendee::ACTOR_GUESTS) {
				$this->participantService->removeAttendee($room, $participant, Room::PARTICIPANT_LEFT);
			} else {
				$this->participantService->leaveRoomAsSession($room, $participant);
			}
		}

		$permissions = ['publish-media', 'publish-screen'];
		if ($participant instanceof Participant && $participant->hasModeratorPermissions(false)) {
			$permissions[] = 'control';
		}

		$event = new SignalingEvent($room, $participant, $action);
		$this->dispatcher->dispatch(self::EVENT_BACKEND_SIGNALING_ROOMS, $event);

		$response = [
			'type' => 'room',
			'room' => [
				'version' => '1.0',
				'roomid' => $room->getToken(),
				'properties' => $room->getPropertiesForSignaling((string) $userId),
				'permissions' => $permissions,
			],
		];
		if ($event->getSession()) {
			$response['room']['session'] = $event->getSession();
		}
		return new DataResponse($response);
	}

	private function backendPing(array $request): DataResponse {
		try {
			$room = $this->manager->getRoomByToken($request['roomid']);
		} catch (RoomNotFoundException $e) {
			return new DataResponse([
				'type' => 'error',
				'error' => [
					'code' => 'no_such_room',
					'message' => 'No such room.',
				],
			]);
		}

		$pingSessionIds = [];
		$now = $this->timeFactory->getTime();
		foreach ($request['entries'] as $entry) {
			if ($entry['sessionid'] !== '0') {
				$pingSessionIds[] = $entry['sessionid'];
			}
		}

		// Ping all active sessions with one query
		$this->sessionService->updateMultipleLastPings($pingSessionIds, $now);

		$response = [
			'type' => 'room',
			'room' => [
				'version' => '1.0',
				'roomid' => $room->getToken(),
			],
		];
		return new DataResponse($response);
	}
}
