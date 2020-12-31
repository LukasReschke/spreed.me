<?php
/**
 *
 * @copyright Copyright (c) 2018, Joachim Bauch (bauch@struktur.de)
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

namespace OCA\Talk\Tests\php\Signaling;

use OCA\Talk\AppInfo\Application;
use OCA\Talk\Chat\CommentsManager;
use OCA\Talk\Config;
use OCA\Talk\Events\SignalingRoomPropertiesEvent;
use OCA\Talk\Manager;
use OCA\Talk\Model\AttendeeMapper;
use OCA\Talk\Model\SessionMapper;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Signaling\BackendNotifier;
use OCA\Talk\TalkSession;
use OCA\Talk\Webinary;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClientService;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class CustomBackendNotifier extends BackendNotifier {
	private $requests = [];

	public function getRequests(): array {
		return $this->requests;
	}

	public function clearRequests() {
		$this->requests = [];
	}

	protected function doRequest(string $url, array $params): void {
		$this->requests[] = [
			'url' => $url,
			'params' => $params,
		];
	}
}

/**
 * @group DB
 */
class BackendNotifierTest extends \Test\TestCase {

	/** @var Config */
	private $config;
	/** @var ISecureRandom */
	private $secureRandom;
	/** @var ITimeFactory|MockObject */
	private $timeFactory;
	/** @var ParticipantService|MockObject */
	private $participantService;
	/** @var \OCA\Talk\Signaling\Manager|MockObject */
	private $signalingManager;
	/** @var IURLGenerator|MockObject */
	private $urlGenerator;
	/** @var CustomBackendNotifier */
	private $controller;

	/** @var Manager */
	private $manager;

	/** @var string */
	private $userId;
	/** @var string */
	private $signalingSecret;
	/** @var string */
	private $baseUrl;

	/** @var Application */
	protected $app;
	/** @var BackendNotifier */
	protected $originalBackendNotifier;
	/** @var IEventDispatcher */
	private $dispatcher;

	public function setUp(): void {
		parent::setUp();

		$this->userId = 'testUser';
		$this->secureRandom = \OC::$server->getSecureRandom();
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$groupManager = $this->createMock(IGroupManager::class);
		$config = \OC::$server->getConfig();
		$this->signalingSecret = 'the-signaling-secret';
		$this->baseUrl = 'https://localhost/signaling';
		$config->setAppValue('spreed', 'signaling_servers', json_encode([
			'secret' => $this->signalingSecret,
			'servers' => [
				[
					'server' => $this->baseUrl,
				],
			],
		]));

		$this->participantService = \OC::$server->get(ParticipantService::class);
		$this->signalingManager = $this->createMock(\OCA\Talk\Signaling\Manager::class);
		$this->signalingManager->expects($this->any())
			->method('getSignalingServerForConversation')
			->willReturn(['server' => $this->baseUrl]);

		$this->config = new Config($config, $this->secureRandom, $groupManager, $this->timeFactory);
		$this->recreateBackendNotifier();

		$this->overwriteService(BackendNotifier::class, $this->controller);

		$dbConnection = \OC::$server->getDatabaseConnection();
		$this->dispatcher = \OC::$server->query(IEventDispatcher::class);
		$this->manager = new Manager(
			$dbConnection,
			$config,
			$this->config,
			\OC::$server->get(IAppManager::class),
			\OC::$server->get(AttendeeMapper::class),
			\OC::$server->get(SessionMapper::class),
			$this->participantService,
			$this->secureRandom,
			$this->createMock(IUserManager::class),
			$groupManager,
			$this->createMock(CommentsManager::class),
			$this->createMock(TalkSession::class),
			$this->dispatcher,
			$this->timeFactory,
			$this->createMock(IHasher::class),
			$this->createMock(IL10N::class)
		);
	}

	public function tearDown(): void {
		$config = \OC::$server->getConfig();
		$config->deleteAppValue('spreed', 'signaling_servers');
		$this->restoreService(BackendNotifier::class);
		parent::tearDown();
	}

	private function recreateBackendNotifier() {
		$this->controller = new CustomBackendNotifier(
			$this->config,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IClientService::class),
			$this->secureRandom,
			$this->signalingManager,
			$this->participantService,
			$this->urlGenerator
		);
	}

	private function calculateBackendChecksum($data, $random) {
		if (empty($random) || strlen($random) < 32) {
			return false;
		}
		return hash_hmac('sha256', $random . $data, $this->signalingSecret);
	}

	private function validateBackendRequest($expectedUrl, $request) {
		$this->assertTrue(isset($request));
		$this->assertEquals($expectedUrl, $request['url']);
		$headers = $request['params']['headers'];
		$this->assertEquals('application/json', $headers['Content-Type']);
		$random = $headers['Spreed-Signaling-Random'];
		$checksum = $headers['Spreed-Signaling-Checksum'];
		$body = $request['params']['body'];
		$this->assertEquals($this->calculateBackendChecksum($body, $random), $checksum);
		return $body;
	}

	private function assertMessageWasSent(Room $room, array $message): void {
		$requests = $this->controller->getRequests();
		$bodies = array_map(function ($request) use ($room) {
			return json_decode($this->validateBackendRequest($this->baseUrl . '/api/v1/room/' . $room->getToken(), $request), true);
		}, $requests);

		$bodies = array_filter($bodies, function (array $body) use ($message) {
			return $body['type'] === $message['type'];
		});

		$bodies = array_map([$this, 'sortParticipantUsers'], $bodies);
		$message = $this->sortParticipantUsers($message);
		$this->assertContains($message, $bodies, json_encode($bodies, JSON_PRETTY_PRINT));
	}

	private function sortParticipantUsers(array $message): array {
		if ($message['type'] === 'participants') {
			usort($message['participants']['users'], static function ($a, $b) {
				return
					[$a['userId'] ?? '', $a['participantType'], $a['sessionId'], $a['lastPing']]
					<=>
					[$b['userId'] ?? '', $b['participantType'], $b['sessionId'], $b['lastPing']]
					;
			});
		}
		if ($message['type'] === 'incall') {
			usort($message['incall']['users'], static function ($a, $b) {
				return
					[$a['userId'] ?? '', $a['participantType'], $a['sessionId'], $a['lastPing']]
					<=>
					[$b['userId'] ?? '', $b['participantType'], $b['sessionId'], $b['lastPing']]
					;
			});
		}
		return $message;
	}

	public function testRoomInvite() {
		$room = $this->manager->createRoom(Room::PUBLIC_CALL);
		$this->participantService->addUsers($room, [[
			'actorType' => 'users',
			'actorId' => $this->userId,
		]]);

		$this->assertMessageWasSent($room, [
			'type' => 'invite',
			'invite' => [
				'userids' => [
					$this->userId,
				],
				'alluserids' => [
					$this->userId,
				],
				'properties' => [
					'name' => $room->getDisplayName(''),
					'type' => $room->getType(),
					'lobby-state' => Webinary::LOBBY_NONE,
					'lobby-timer' => null,
					'read-only' => Room::READ_WRITE,
					'listable' => Room::LISTABLE_NONE,
					'active-since' => null,
					'sip-enabled' => 0,
				],
			],
		]);
	}

	public function testRoomDisinvite() {
		$room = $this->manager->createRoom(Room::PUBLIC_CALL);
		$this->participantService->addUsers($room, [[
			'actorType' => 'users',
			'actorId' => $this->userId,
		]]);
		$this->controller->clearRequests();
		/** @var IUser|MockObject $testUser */
		$testUser = $this->createMock(IUser::class);
		$testUser->expects($this->any())
			->method('getUID')
			->willReturn($this->userId);
		$this->participantService->removeUser($room, $testUser, Room::PARTICIPANT_REMOVED);

		$this->assertMessageWasSent($room, [
			'type' => 'disinvite',
			'disinvite' => [
				'userids' => [
					$this->userId,
				],
				'alluserids' => [
				],
				'properties' => [
					'name' => $room->getDisplayName(''),
					'type' => $room->getType(),
					'lobby-state' => Webinary::LOBBY_NONE,
					'lobby-timer' => null,
					'read-only' => Room::READ_WRITE,
					'listable' => Room::LISTABLE_NONE,
					'active-since' => null,
					'sip-enabled' => 0,
				],
			],
		]);
	}

	public function testRoomNameChanged() {
		$room = $this->manager->createRoom(Room::PUBLIC_CALL);
		$room->setName('Test room');

		$this->assertMessageWasSent($room, [
			'type' => 'update',
			'update' => [
				'userids' => [
				],
				'properties' => [
					'name' => $room->getDisplayName(''),
					'description' => '',
					'avatarId' => 'icon-public',
					'avatarVersion' => 1,
					'type' => $room->getType(),
					'lobby-state' => Webinary::LOBBY_NONE,
					'lobby-timer' => null,
					'read-only' => Room::READ_WRITE,
					'listable' => Room::LISTABLE_NONE,
					'active-since' => null,
					'sip-enabled' => 0,
				],
			],
		]);
	}

	public function testRoomDescriptionChanged() {
		$room = $this->manager->createRoom(Room::PUBLIC_CALL);
		$room->setDescription('The description');

		$this->assertMessageWasSent($room, [
			'type' => 'update',
			'update' => [
				'userids' => [
				],
				'properties' => [
					'name' => $room->getDisplayName(''),
					'description' => 'The description',
					'avatarId' => 'icon-public',
					'avatarVersion' => 1,
					'type' => $room->getType(),
					'lobby-state' => Webinary::LOBBY_NONE,
					'lobby-timer' => null,
					'read-only' => Room::READ_WRITE,
					'listable' => Room::LISTABLE_NONE,
					'active-since' => null,
					'sip-enabled' => 0,
				],
			],
		]);
	}

	public function testRoomAvatarChanged() {
		$room = $this->manager->createRoom(Room::PUBLIC_CALL);
		$room->setAvatar('avatar-id', 42);

		$this->assertMessageWasSent($room, [
			'type' => 'update',
			'update' => [
				'userids' => [
				],
				'properties' => [
					'name' => $room->getDisplayName(''),
					'description' => '',
					'avatarId' => 'avatar-id',
					'avatarVersion' => 42,
					'type' => $room->getType(),
					'lobby-state' => Webinary::LOBBY_NONE,
					'lobby-timer' => null,
					'read-only' => Room::READ_WRITE,
					'listable' => Room::LISTABLE_NONE,
					'active-since' => null,
					'sip-enabled' => 0,
				],
			],
		]);
	}

	public function testRoomPasswordChanged() {
		$room = $this->manager->createRoom(Room::PUBLIC_CALL);
		$room->setPassword('password');

		$this->assertMessageWasSent($room, [
			'type' => 'update',
			'update' => [
				'userids' => [
				],
				'properties' => [
					'name' => $room->getDisplayName(''),
					'description' => '',
					'avatarId' => 'icon-public',
					'avatarVersion' => 1,
					'type' => $room->getType(),
					'lobby-state' => Webinary::LOBBY_NONE,
					'lobby-timer' => null,
					'read-only' => Room::READ_WRITE,
					'listable' => Room::LISTABLE_NONE,
					'active-since' => null,
					'sip-enabled' => 0,
				],
			],
		]);
	}

	public function testRoomTypeChanged() {
		$room = $this->manager->createRoom(Room::PUBLIC_CALL);
		$room->setType(Room::GROUP_CALL);

		$this->assertMessageWasSent($room, [
			'type' => 'update',
			'update' => [
				'userids' => [
				],
				'properties' => [
					'name' => $room->getDisplayName(''),
					'description' => '',
					'avatarId' => 'icon-public',
					'avatarVersion' => 1,
					'type' => $room->getType(),
					'lobby-state' => Webinary::LOBBY_NONE,
					'lobby-timer' => null,
					'read-only' => Room::READ_WRITE,
					'listable' => Room::LISTABLE_NONE,
					'active-since' => null,
					'sip-enabled' => 0,
				],
			],
		]);
	}

	public function testRoomReadOnlyChanged() {
		$room = $this->manager->createRoom(Room::PUBLIC_CALL);
		$room->setReadOnly(Room::READ_ONLY);

		$this->assertMessageWasSent($room, [
			'type' => 'update',
			'update' => [
				'userids' => [
				],
				'properties' => [
					'name' => $room->getDisplayName(''),
					'description' => '',
					'avatarId' => 'icon-public',
					'avatarVersion' => 1,
					'type' => $room->getType(),
					'lobby-state' => Webinary::LOBBY_NONE,
					'lobby-timer' => null,
					'read-only' => Room::READ_ONLY,
					'listable' => Room::LISTABLE_NONE,
					'active-since' => null,
					'sip-enabled' => 0,
				],
			],
		]);
	}

	public function testRoomListableChanged() {
		$room = $this->manager->createRoom(Room::PUBLIC_CALL);
		$room->setListable(Room::LISTABLE_ALL);

		$this->assertMessageWasSent($room, [
			'type' => 'update',
			'update' => [
				'userids' => [
				],
				'properties' => [
					'name' => $room->getDisplayName(''),
					'type' => $room->getType(),
					'lobby-state' => Webinary::LOBBY_NONE,
					'lobby-timer' => null,
					'read-only' => Room::READ_WRITE,
					'listable' => Room::LISTABLE_ALL,
					'active-since' => null,
					'sip-enabled' => 0,
					'description' => '',
					'avatarId' => 'icon-public',
					'avatarVersion' => 1,
				],
			],
		]);
	}

	public function testRoomLobbyStateChanged() {
		$room = $this->manager->createRoom(Room::PUBLIC_CALL);
		$room->setLobby(Webinary::LOBBY_NON_MODERATORS, null);

		$this->assertMessageWasSent($room, [
			'type' => 'update',
			'update' => [
				'userids' => [
				],
				'properties' => [
					'name' => $room->getDisplayName(''),
					'description' => '',
					'avatarId' => 'icon-public',
					'avatarVersion' => 1,
					'type' => $room->getType(),
					'lobby-state' => Webinary::LOBBY_NON_MODERATORS,
					'lobby-timer' => null,
					'read-only' => Room::READ_WRITE,
					'listable' => Room::LISTABLE_NONE,
					'active-since' => null,
					'sip-enabled' => 0,
				],
			],
		]);
	}

	public function testRoomDelete() {
		$room = $this->manager->createRoom(Room::PUBLIC_CALL);
		$this->participantService->addUsers($room, [[
			'actorType' => 'users',
			'actorId' => $this->userId,
		]]);
		$room->deleteRoom();

		$this->assertMessageWasSent($room, [
			'type' => 'delete',
			'delete' => [
				'userids' => [
					$this->userId,
				],
			],
		]);
	}

	public function testRoomInCallChanged() {
		$room = $this->manager->createRoom(Room::PUBLIC_CALL);
		$this->participantService->addUsers($room, [[
			'actorType' => 'users',
			'actorId' => $this->userId,
		]]);

		/** @var IUser|MockObject $testUser */
		$testUser = $this->createMock(IUser::class);
		$testUser->expects($this->any())
			->method('getUID')
			->willReturn($this->userId);

		$participant = $this->participantService->joinRoom($room, $testUser, '');
		$userSession = $participant->getSession()->getSessionId();
		$participant = $room->getParticipantBySession($userSession);

		$this->participantService->changeInCall($room, $participant, Participant::FLAG_IN_CALL | Participant::FLAG_WITH_AUDIO | Participant::FLAG_WITH_VIDEO);

		$this->assertMessageWasSent($room, [
			'type' => 'incall',
			'incall' => [
				'incall' => 7,
				'changed' => [
					[
						'inCall' => 7,
						'lastPing' => 0,
						'sessionId' => $userSession,
						'participantType' => Participant::USER,
						'userId' => $this->userId,
					],
				],
				'users' => [
					[
						'inCall' => 7,
						'lastPing' => 0,
						'sessionId' => $userSession,
						'participantType' => Participant::USER,
						'userId' => $this->userId,
					],
				],
			],
		]);

		$this->controller->clearRequests();

		$guestParticipant = $this->participantService->joinRoomAsNewGuest($room, '');
		$guestSession = $guestParticipant->getSession()->getSessionId();
		$guestParticipant = $room->getParticipantBySession($guestSession);
		$this->participantService->changeInCall($room, $guestParticipant, Participant::FLAG_IN_CALL);

		$this->assertMessageWasSent($room, [
			'type' => 'incall',
			'incall' => [
				'incall' => 1,
				'changed' => [
					[
						'inCall' => 1,
						'lastPing' => 0,
						'sessionId' => $guestSession,
						'participantType' => Participant::GUEST,
					],
				],
				'users' => [
					[
						'inCall' => 7,
						'lastPing' => 0,
						'sessionId' => $userSession,
						'participantType' => Participant::USER,
						'userId' => $this->userId,
					],
					[
						'inCall' => 1,
						'lastPing' => 0,
						'sessionId' => $guestSession,
						'participantType' => Participant::GUEST,
					],
				],
			],
		]);

		$this->controller->clearRequests();
		$this->participantService->changeInCall($room, $participant, Participant::FLAG_DISCONNECTED);

		$this->assertMessageWasSent($room, [
			'type' => 'incall',
			'incall' => [
				'incall' => 0,
				'changed' => [
					[
						'inCall' => 0,
						'lastPing' => 0,
						'sessionId' => $userSession,
						'participantType' => Participant::USER,
						'userId' => $this->userId,
					],
				],
				'users' => [
					[
						'inCall' => 1,
						'lastPing' => 0,
						'sessionId' => $guestSession,
						'participantType' => Participant::GUEST,
					],
				],
			],
		]);
	}

	public function testRoomPropertiesEvent(): void {
		$listener = static function (SignalingRoomPropertiesEvent $event) {
			$room = $event->getRoom();
			$event->setProperty('foo', 'bar');
			$event->setProperty('room', $room->getToken());
		};

		$this->dispatcher->addListener(Room::EVENT_BEFORE_SIGNALING_PROPERTIES, $listener);

		$room = $this->manager->createRoom(Room::PUBLIC_CALL);
		$this->controller->clearRequests();
		$room->setName('Test room');

		$this->assertMessageWasSent($room, [
			'type' => 'update',
			'update' => [
				'userids' => [
				],
				'properties' => [
					'name' => $room->getDisplayName(''),
					'description' => '',
					'avatarId' => 'icon-public',
					'avatarVersion' => 1,
					'type' => $room->getType(),
					'lobby-state' => Webinary::LOBBY_NONE,
					'lobby-timer' => null,
					'read-only' => Room::READ_WRITE,
					'listable' => Room::LISTABLE_NONE,
					'active-since' => null,
					'sip-enabled' => 0,
					'foo' => 'bar',
					'room' => $room->getToken(),
				],
			],
		]);
	}

	public function testParticipantsTypeChanged() {
		$room = $this->manager->createRoom(Room::PUBLIC_CALL);
		$this->participantService->addUsers($room, [[
			'actorType' => 'users',
			'actorId' => $this->userId,
		]]);

		/** @var IUser|MockObject $testUser */
		$testUser = $this->createMock(IUser::class);
		$testUser->expects($this->any())
			->method('getUID')
			->willReturn($this->userId);

		$participant = $this->participantService->joinRoom($room, $testUser, '');
		$userSession = $participant->getSession()->getSessionId();
		$participant = $room->getParticipantBySession($userSession);

		$this->participantService->updateParticipantType($room, $participant, Participant::MODERATOR);

		$this->assertMessageWasSent($room, [
			'type' => 'participants',
			'participants' => [
				'changed' => [
					[
						'permissions' => ['publish-media', 'publish-screen', 'control'],
						'inCall' => 0,
						'lastPing' => 0,
						'sessionId' => $userSession,
						'participantType' => Participant::MODERATOR,
						'userId' => $this->userId,
					],
				],
				'users' => [
					[
						'inCall' => 0,
						'lastPing' => 0,
						'sessionId' => $userSession,
						'participantType' => Participant::MODERATOR,
						'userId' => $this->userId,
					],
				],
			],
		]);

		$this->controller->clearRequests();

		$guestParticipant = $this->participantService->joinRoomAsNewGuest($room, '');
		$guestSession = $guestParticipant->getSession()->getSessionId();
		$guestParticipant = $room->getParticipantBySession($guestSession);

		$this->participantService->updateParticipantType($room, $guestParticipant, Participant::GUEST_MODERATOR);

		$this->assertMessageWasSent($room, [
			'type' => 'participants',
			'participants' => [
				'changed' => [
					[
						'permissions' => ['publish-media', 'publish-screen'],
						'inCall' => 0,
						'lastPing' => 0,
						'sessionId' => $guestSession,
						'participantType' => Participant::GUEST_MODERATOR,
					],
				],
				'users' => [
					[
						'inCall' => 0,
						'lastPing' => 0,
						'sessionId' => $userSession,
						'participantType' => Participant::MODERATOR,
						'userId' => $this->userId,
					],
					[
						'inCall' => 0,
						'lastPing' => 0,
						'sessionId' => $guestSession,
						'participantType' => Participant::GUEST_MODERATOR,
					],
				],
			],
		]);

		$this->controller->clearRequests();
		$notJoinedUserId = 'not-joined-user-id';
		$this->participantService->addUsers($room, [[
			'actorType' => 'users',
			'actorId' => $notJoinedUserId,
		]]);

		$notJoinedParticipant = $room->getParticipant($notJoinedUserId);
		$this->participantService->updateParticipantType($room, $notJoinedParticipant, Participant::MODERATOR);

		$this->assertMessageWasSent($room, [
			'type' => 'participants',
			'participants' => [
				'changed' => [
				],
				'users' => [
					[
						'inCall' => 0,
						'lastPing' => 0,
						'sessionId' => $userSession,
						'participantType' => Participant::MODERATOR,
						'userId' => $this->userId,
					],
					[
						'inCall' => 0,
						'lastPing' => 0,
						'sessionId' => 0,
						'participantType' => Participant::MODERATOR,
						'userId' => $notJoinedUserId,
					],
					[
						'inCall' => 0,
						'lastPing' => 0,
						'sessionId' => $guestSession,
						'participantType' => Participant::GUEST_MODERATOR,
					],
				],
			],
		]);

		$this->controller->clearRequests();
		$this->participantService->updateParticipantType($room, $participant, Participant::USER);

		$this->assertMessageWasSent($room, [
			'type' => 'participants',
			'participants' => [
				'changed' => [
					[
						'permissions' => ['publish-media', 'publish-screen'],
						'inCall' => 0,
						'lastPing' => 0,
						'sessionId' => $userSession,
						'participantType' => Participant::USER,
						'userId' => $this->userId,
					],
				],
				'users' => [
					[
						'inCall' => 0,
						'lastPing' => 0,
						'sessionId' => $userSession,
						'participantType' => Participant::USER,
						'userId' => $this->userId,
					],
					[
						'inCall' => 0,
						'lastPing' => 0,
						'sessionId' => 0,
						'participantType' => Participant::MODERATOR,
						'userId' => $notJoinedUserId,
					],
					[
						'inCall' => 0,
						'lastPing' => 0,
						'sessionId' => $guestSession,
						'participantType' => Participant::GUEST_MODERATOR,
					],
				],
			],
		]);

		$this->controller->clearRequests();
		$this->participantService->updateParticipantType($room, $guestParticipant, Participant::GUEST);

		$this->assertMessageWasSent($room, [
			'type' => 'participants',
			'participants' => [
				'changed' => [
					[
						'permissions' => ['publish-media', 'publish-screen'],
						'inCall' => 0,
						'lastPing' => 0,
						'sessionId' => $guestSession,
						'participantType' => Participant::GUEST,
					],
				],
				'users' => [
					[
						'inCall' => 0,
						'lastPing' => 0,
						'sessionId' => $userSession,
						'participantType' => Participant::USER,
						'userId' => $this->userId,
					],
					[
						'inCall' => 0,
						'lastPing' => 0,
						'sessionId' => 0,
						'participantType' => Participant::MODERATOR,
						'userId' => $notJoinedUserId,
					],
					[
						'inCall' => 0,
						'lastPing' => 0,
						'sessionId' => $guestSession,
						'participantType' => Participant::GUEST,
					],
				],
			],
		]);
	}
}
