<?php
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

namespace OCA\Spreed\Tests\php\Notifications;

use OCA\Spreed\Exceptions\RoomNotFoundException;
use OCA\Spreed\Manager;
use OCA\Spreed\Notification\Notifier;
use OCA\Spreed\Room;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\RichObjectStrings\Definitions;

class NotifierTest extends \Test\TestCase {

	/** @var IFactory|\PHPUnit_Framework_MockObject_MockObject */
	protected $lFactory;
	/** @var IURLGenerator|\PHPUnit_Framework_MockObject_MockObject */
	protected $url;
	/** @var IUserManager|\PHPUnit_Framework_MockObject_MockObject */
	protected $userManager;
	/** @var Manager|\PHPUnit_Framework_MockObject_MockObject */
	protected $manager;
	/** @var Definitions|\PHPUnit_Framework_MockObject_MockObject */
	protected $definitions;
	/** @var Notifier */
	protected $notifier;

	public function setUp() {
		parent::setUp();

		$this->lFactory = $this->createMock(IFactory::class);
		$this->url = $this->createMock(IURLGenerator::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->manager = $this->createMock(Manager::class);
		$this->definitions = $this->createMock(Definitions::class);

		$this->notifier = new Notifier(
			$this->lFactory,
			$this->url,
			$this->userManager,
			$this->manager,
			$this->definitions
		);
	}

	public function dataPrepareMention() {
		return [
			[
				Room::ONE_TO_ONE_CALL, ['userType' => 'users', 'userId' => 'testUser'],           ['ellipsisStart', 'ellipsisEnd'], 'Test user', '',
				'Test user mentioned you in a private conversation',
				['{user} mentioned you in a private conversation',
					['user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user']]
				],
				'… message …'
			],
			// If the user is deleted in a one to one conversation the conversation is also
			// deleted, and that in turn would delete the pending notification.
			[
				Room::GROUP_CALL,      ['userType' => 'users', 'userId' => 'testUser'],           [], 'Test user', '',
				'Test user mentioned you in a group conversation',
				['{user} mentioned you in a group conversation',
					['user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user']]
				],
				'message'
			],
			[
				Room::GROUP_CALL,      ['userType' => 'users', 'userId' => 'testUser'],           ['ellipsisStart'], null,        '',
				'You were mentioned in a group conversation by a deleted user',
				['You were mentioned in a group conversation by a deleted user',
					[]
				],
				'… message',
				true],
			[
				Room::GROUP_CALL,      ['userType' => 'users', 'userId' => 'testUser'],           ['ellipsisEnd'], 'Test user', 'Room name',
				'Test user mentioned you in a group conversation: Room name',
				['{user} mentioned you in a group conversation: {call}',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 'testRoomId', 'name' => 'Room name', 'call-type' => 'group']
					]
				],
				'message …'
			],
			[
				Room::GROUP_CALL,      ['userType' => 'users', 'userId' => 'testUser'],           ['ellipsisStart', 'ellipsisEnd'], null,        'Room name',
				'You were mentioned in a group conversation by a deleted user: Room name',
				['You were mentioned in a group conversation by a deleted user: {call}',
					[
						'call' => ['type' => 'call', 'id' => 'testRoomId', 'name' => 'Room name', 'call-type' => 'group']
					]
				],
				'… message …',
				true],
			[
				Room::PUBLIC_CALL,     ['userType' => 'users', 'userId' => 'testUser'],           [], 'Test user', '',
				'Test user mentioned you in a group conversation',
				['{user} mentioned you in a group conversation',
					['user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user']]
				],
				'message'
			],
			[
				Room::PUBLIC_CALL,     ['userType' => 'users', 'userId' => 'testUser'],           ['ellipsisStart'], null,        '',
				'You were mentioned in a group conversation by a deleted user',
				['You were mentioned in a group conversation by a deleted user',
					[]
				],
				'… message',
				true],
			[
				Room::PUBLIC_CALL,     ['userType' => 'guests', 'userId' => 'testSpreedSession'], ['ellipsisEnd'], null,        '',
				'A guest mentioned you in a group conversation',
				['A guest mentioned you in a group conversation',
					[]
				],
				'message …'
			],
			[
				Room::PUBLIC_CALL,     ['userType' => 'users', 'userId' => 'testUser'],           ['ellipsisStart', 'ellipsisEnd'], 'Test user', 'Room name',
				'Test user mentioned you in a group conversation: Room name',
				['{user} mentioned you in a group conversation: {call}',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 'testRoomId', 'name' => 'Room name', 'call-type' => 'public']
					]
				],
				'… message …'
			],
			[
				Room::PUBLIC_CALL,     ['userType' => 'users', 'userId' => 'testUser'],           [], null,    'Room name',
				'You were mentioned in a group conversation by a deleted user: Room name',
				['You were mentioned in a group conversation by a deleted user: {call}',
					[
						'call' => ['type' => 'call', 'id' => 'testRoomId', 'name' => 'Room name', 'call-type' => 'public']
					]
				],
				'message',
				true],
			[
				Room::PUBLIC_CALL,     ['userType' => 'guests', 'userId' => 'testSpreedSession'], ['ellipsisStart', 'ellipsisEnd'], null,    'Room name',
				'A guest mentioned you in a group conversation: Room name',
				['A guest mentioned you in a group conversation: {call}',
					['call' => ['type' => 'call', 'id' => 'testRoomId', 'name' => 'Room name', 'call-type' => 'public']]
				],
				'… message …'
			]
		];
	}

	/**
	 * @dataProvider dataPrepareMention
	 * @param int $roomType
	 * @param array $subjectParameters
	 * @param array $messageParameters
	 * @param string $displayName
	 * @param string $roomName
	 * @param string $parsedSubject
	 * @param array $richSubject
	 * @param string $parsedMessage
	 * @param bool $deletedUser
	 */
	public function testPrepareMention($roomType, $subjectParameters, $messageParameters, $displayName, $roomName, $parsedSubject, $richSubject, $parsedMessage, $deletedUser = false) {
		$notification = $this->createMock(INotification::class);
		$l = $this->createMock(IL10N::class);
		$l->expects($this->atLeast(2))
			->method('t')
			->will($this->returnCallback(function($text, $parameters = []) {
				return vsprintf($text, $parameters);
			}));

		$room = $this->createMock(Room::class);
		$room->expects($this->atLeastOnce())
			->method('getType')
			->willReturn($roomType);
		$room->expects($this->atLeastOnce())
			->method('getName')
			->willReturn($roomName);
		if ($roomName !== '') {
			$room->expects($this->atLeastOnce())
				->method('getId')
				->willReturn('testRoomId');
		}
		$this->manager->expects($this->once())
			->method('getRoomById')
			->willReturn($room);

		$this->lFactory->expects($this->once())
			->method('get')
			->with('spreed', 'de')
			->willReturn($l);

		$user = $this->createMock(IUser::class);
		if ($subjectParameters['userType'] === 'users' && !$deletedUser) {
			$user->expects($this->exactly(2))
				->method('getDisplayName')
				->willReturn($displayName);
			$this->userManager->expects($this->once())
				->method('get')
				->with($subjectParameters['userId'])
				->willReturn($user);
		} else if ($subjectParameters['userType'] === 'users' && $deletedUser) {
			$user->expects($this->never())
				->method('getDisplayName');
			$this->userManager->expects($this->once())
				->method('get')
				->with($subjectParameters['userId'])
				->willReturn(null);
		} else {
			$user->expects($this->never())
				->method('getDisplayName');
			$this->userManager->expects($this->never())
				->method('get');
		}

		$notification->expects($this->once())
			->method('setIcon')
			->willReturnSelf();
		$notification->expects($this->once())
			->method('setLink')
			->willReturnSelf();
		$notification->expects($this->once())
			->method('setParsedSubject')
			->with($parsedSubject)
			->willReturnSelf();
		$notification->expects($this->once())
			->method('setRichSubject')
			->with($richSubject[0], $richSubject[1])
			->willReturnSelf();
		$notification->expects($this->once())
			->method('setParsedMessage')
			->with($parsedMessage)
			->willReturnSelf();

		$notification->expects($this->once())
			->method('getApp')
			->willReturn('spreed');
		$notification->expects($this->once())
			->method('getSubject')
			->willReturn('mention');
		$notification->expects($this->once())
			->method('getSubjectParameters')
			->willReturn($subjectParameters);
		$notification->expects($this->once())
			->method('getObjectType')
			->willReturn('chat');
		$notification->expects($this->once())
			->method('getMessage')
			->willReturn('message');
		$notification->expects($this->once())
			->method('getMessageParameters')
			->willReturn($messageParameters);

		$this->assertEquals($notification, $this->notifier->prepare($notification, 'de'));
	}

	public function dataPrepareThrows() {
		return [
			['Incorrect app', 'invalid-app', null, null, null, null],
			['Invalid room', 'spreed', false, null, null, null],
			['Unknown subject', 'spreed', true, 'invalid-subject', null, null],
			['Unknown object type', 'spreed', true, 'mention', null, 'invalid-object-type'],
		];
	}

	/**
	 * @dataProvider dataPrepareThrows
	 *
	 * @expectedException \InvalidArgumentException
	 *
	 * @param string $message
	 * @param string $app
	 * @param bool|null $validRoom
	 * @param string|null $subject
	 * @param array|null $params
	 * @param string|null $objectType
	 */
	public function testPrepareThrows($message, $app, $validRoom, $subject, $params, $objectType) {
		$n = $this->createMock(INotification::class);
		$l = $this->createMock(IL10N::class);

		if ($validRoom === null) {
			$this->manager->expects($this->never())
				->method('getRoomById');
		} else if ($validRoom === true) {
			$room = $this->createMock(Room::class);
			$room->expects($this->never())
				->method('getType');
			$this->manager->expects($this->once())
				->method('getRoomById')
				->willReturn($room);
		} else if ($validRoom === false) {
			$this->manager->expects($this->once())
				->method('getRoomById')
				->willThrowException(new RoomNotFoundException());
		}

		$this->lFactory->expects($validRoom === null ? $this->never() : $this->once())
			->method('get')
			->with('spreed', 'de')
			->willReturn($l);

		$n->expects($validRoom !== true ? $this->never() : $this->once())
			->method('setIcon')
			->willReturnSelf();
		$n->expects($validRoom !== true ? $this->never() : $this->once())
			->method('setLink')
			->willReturnSelf();

		$n->expects($this->once())
			->method('getApp')
			->willReturn($app);
		$n->expects($subject === null ? $this->never() : $this->atLeastOnce())
			->method('getSubject')
			->willReturn($subject);
		$n->expects($params === null ? $this->never() : $this->once())
			->method('getSubjectParameters')
			->willReturn($params);
		$n->expects($objectType === null ? $this->never() : $this->once())
			->method('getObjectType')
			->willReturn($objectType);

		$this->setExpectedException(\InvalidArgumentException::class, $message);
		$this->notifier->prepare($n, 'de');
	}
}
