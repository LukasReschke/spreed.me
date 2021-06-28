<?php

declare(strict_types=1);
/**
 *
 * @copyright Copyright (c) 2017, Daniel Calviño Sánchez <danxuliu@gmail.com>
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

namespace OCA\Talk\Tests\php\Chat;

use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Chat\CommentsManager;
use OCA\Talk\Chat\Notifier;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * @group DB
 */
class ChatManagerTest extends TestCase {

	/** @var CommentsManager|ICommentsManager|MockObject */
	protected $commentsManager;
	/** @var IEventDispatcher|MockObject */
	protected $dispatcher;
	/** @var INotificationManager|MockObject */
	protected $notificationManager;
	/** @var ParticipantService|MockObject */
	protected $participantService;
	/** @var Notifier|MockObject */
	protected $notifier;
	/** @var ITimeFactory|MockObject */
	protected $timeFactory;
	/** @var ChatManager */
	protected $chatManager;

	public function setUp(): void {
		parent::setUp();

		$this->commentsManager = $this->createMock(CommentsManager::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->participantService = $this->createMock(ParticipantService::class);
		$this->notifier = $this->createMock(Notifier::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$cacheFactory = $this->createMock(ICacheFactory::class);

		$this->chatManager = new ChatManager(
			$this->commentsManager,
			$this->dispatcher,
			\OC::$server->getDatabaseConnection(),
			$this->notificationManager,
			$this->participantService,
			$this->notifier,
			$cacheFactory,
			$this->timeFactory
		);
	}

	private function newComment($id, string $actorType, string $actorId, \DateTime $creationDateTime, string $message): IComment {
		$comment = $this->createMock(IComment::class);

		$id = (string) $id;

		$comment->method('getId')->willReturn($id);
		$comment->method('getActorType')->willReturn($actorType);
		$comment->method('getActorId')->willReturn($actorId);
		$comment->method('getCreationDateTime')->willReturn($creationDateTime);
		$comment->method('getMessage')->willReturn($message);

		// Used for equals comparison
		$comment->id = $id;
		$comment->actorType = $actorType;
		$comment->actorId = $actorId;
		$comment->creationDateTime = $creationDateTime;
		$comment->message = $message;

		return $comment;
	}

	/**
	 * @param array $data
	 * @return IComment|MockObject
	 */
	private function newCommentFromArray(array $data): IComment {
		$comment = $this->createMock(IComment::class);

		foreach ($data as $key => $value) {
			if ($key === 'id') {
				$value = (string) $value;
			}
			$comment->method('get' . ucfirst($key))->willReturn($value);
		}

		return $comment;
	}

	protected function assertCommentEquals(array $data, IComment $comment): void {
		if (isset($data['id'])) {
			$id = $data['id'];
			unset($data['id']);
			$this->assertEquals($id, $comment->getId());
		}

		$this->assertEquals($data, [
			'actorType' => $comment->getActorType(),
			'actorId' => $comment->getActorId(),
			'creationDateTime' => $comment->getCreationDateTime(),
			'message' => $comment->getMessage(),
			'referenceId' => $comment->getReferenceId(),
			'parentId' => $comment->getParentId(),
		]);
	}

	public function dataSendMessage(): array {
		return [
			'simple message' => ['testUser1', 'testMessage1', '', '0'],
			'reference id' => ['testUser2', 'testMessage2', 'referenceId2', '0'],
			'as a reply' => ['testUser3', 'testMessage3', '', '23'],
			'reply w/ ref' => ['testUser4', 'testMessage4', 'referenceId4', '23'],
		];
	}

	/**
	 * @dataProvider dataSendMessage
	 * @param string $userId
	 * @param string $message
	 * @param string $referenceId
	 * @param string $parentId
	 */
	public function testSendMessage(string $userId, string $message, string $referenceId, string $parentId): void {
		$creationDateTime = new \DateTime();

		$commentExpected = [
			'actorType' => 'users',
			'actorId' => $userId,
			'creationDateTime' => $creationDateTime,
			'message' => $message,
			'referenceId' => $referenceId,
			'parentId' => $parentId,
		];

		$comment = $this->newCommentFromArray($commentExpected);

		if ($parentId !== '0') {
			$replyTo = $this->newCommentFromArray([
				'id' => $parentId,
			]);

			$comment->expects($this->once())
				->method('setParentId')
				->with($parentId);
		} else {
			$replyTo = null;
		}

		$this->commentsManager->expects($this->once())
			->method('create')
			->with('users', $userId, 'chat', 1234)
			->willReturn($comment);

		$comment->expects($this->once())
			->method('setMessage')
			->with($message);

		$comment->expects($this->once())
			->method('setCreationDateTime')
			->with($creationDateTime);

		$comment->expects($referenceId === '' ? $this->never() : $this->once())
			->method('setReferenceId')
			->with($referenceId);

		$comment->expects($this->once())
			->method('setVerb')
			->with('comment');

		$this->commentsManager->expects($this->once())
			->method('save')
			->with($comment);

		$chat = $this->createMock(Room::class);
		$chat->expects($this->any())
			->method('getId')
			->willReturn(1234);

		$this->notifier->expects($this->once())
			->method('notifyMentionedUsers')
			->with($chat, $comment);

		$participant = $this->createMock(Participant::class);

		$return = $this->chatManager->sendMessage($chat, $participant, 'users', $userId, $message, $creationDateTime, $replyTo, $referenceId);

		$this->assertCommentEquals($commentExpected, $return);
	}

	public function testGetHistory() {
		$offset = 1;
		$limit = 42;
		$expected = [
			$this->newComment(110, 'users', 'testUnknownUser', new \DateTime('@' . 1000000042), 'testMessage3'),
			$this->newComment(109, 'guests', 'testSpreedSession', new \DateTime('@' . 1000000023), 'testMessage2'),
			$this->newComment(108, 'users', 'testUser', new \DateTime('@' . 1000000016), 'testMessage1')
		];

		$chat = $this->createMock(Room::class);
		$chat->expects($this->any())
			->method('getId')
			->willReturn(1234);

		$this->commentsManager->expects($this->once())
			->method('getForObjectSince')
			->with('chat', 1234, $offset, 'desc', $limit)
			->willReturn($expected);

		$comments = $this->chatManager->getHistory($chat, $offset, $limit, false);

		$this->assertEquals($expected, $comments);
	}

	public function testWaitForNewMessages() {
		$offset = 1;
		$limit = 42;
		$timeout = 23;
		$expected = [
			$this->newComment(108, 'users', 'testUser', new \DateTime('@' . 1000000016), 'testMessage1'),
			$this->newComment(109, 'guests', 'testSpreedSession', new \DateTime('@' . 1000000023), 'testMessage2'),
			$this->newComment(110, 'users', 'testUnknownUser', new \DateTime('@' . 1000000042), 'testMessage3'),
		];

		$chat = $this->createMock(Room::class);
		$chat->expects($this->any())
			->method('getId')
			->willReturn(1234);

		$this->commentsManager->expects($this->once())
			->method('getForObjectSince')
			->with('chat', 1234, $offset, 'asc', $limit)
			->willReturn($expected);

		/** @var IUser|\PHPUnit_Framework_MockObject_MockObject $user */
		$user = $this->createMock(IUser::class);
		$user->expects($this->any())
			->method('getUID')
			->willReturn('userId');

		$comments = $this->chatManager->waitForNewMessages($chat, $offset, $limit, $timeout, $user, false);

		$this->assertEquals($expected, $comments);
	}

	public function testWaitForNewMessagesWithWaiting() {
		$offset = 1;
		$limit = 42;
		$timeout = 23;
		$expected = [
			$this->newComment(108, 'users', 'testUser', new \DateTime('@' . 1000000016), 'testMessage1'),
			$this->newComment(109, 'guests', 'testSpreedSession', new \DateTime('@' . 1000000023), 'testMessage2'),
			$this->newComment(110, 'users', 'testUnknownUser', new \DateTime('@' . 1000000042), 'testMessage3'),
		];

		$chat = $this->createMock(Room::class);
		$chat->expects($this->any())
			->method('getId')
			->willReturn(1234);

		$this->commentsManager->expects($this->exactly(2))
			->method('getForObjectSince')
			->with('chat', 1234, $offset, 'asc', $limit)
			->willReturnOnConsecutiveCalls(
				[],
				$expected
			);

		/** @var IUser|\PHPUnit_Framework_MockObject_MockObject $user */
		$user = $this->createMock(IUser::class);
		$user->expects($this->any())
			->method('getUID')
			->willReturn('userId');

		$comments = $this->chatManager->waitForNewMessages($chat, $offset, $limit, $timeout, $user, false);

		$this->assertEquals($expected, $comments);
	}

	public function testGetUnreadCount() {
		/** @var Room|MockObject $chat */
		$chat = $this->createMock(Room::class);
		$chat->expects($this->once())
			->method('getId')
			->willReturn(23);

		$this->commentsManager->expects($this->once())
			->method('getNumberOfCommentsForObjectSinceComment')
			->with('chat', 23, 42, 'comment');

		$this->chatManager->getUnreadCount($chat, 42);
	}

	public function testDeleteMessages() {
		$chat = $this->createMock(Room::class);
		$chat->expects($this->any())
			->method('getId')
			->willReturn(1234);

		$this->commentsManager->expects($this->once())
			->method('deleteCommentsAtObject')
			->with('chat', 1234);

		$this->notifier->expects($this->once())
			->method('removePendingNotificationsForRoom')
			->with($chat);

		$this->chatManager->deleteMessages($chat);
	}
}
