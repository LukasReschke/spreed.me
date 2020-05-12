<?php
declare(strict_types=1);
/**
 *
 * @copyright Copyright (c) 2017, Daniel Calviño Sánchez (danxuliu@gmail.com)
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

namespace OCA\Talk\Chat;

use OCA\Talk\Events\ChatEvent;
use OCA\Talk\Events\ChatParticipantEvent;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Comments\NotFoundException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;

/**
 * Basic polling chat manager.
 *
 * sendMessage() saves a comment using the ICommentsManager, while
 * receiveMessages() tries to read comments from ICommentsManager (with a little
 * wait between reads) until comments are found or until the timeout expires.
 *
 * When a message is saved the mentioned users are notified as needed, and
 * pending notifications are removed if the messages are deleted.
 */
class ChatManager {

	public const EVENT_BEFORE_SYSTEM_MESSAGE_SEND = self::class . '::preSendSystemMessage';
	public const EVENT_AFTER_SYSTEM_MESSAGE_SEND = self::class . '::postSendSystemMessage';
	public const EVENT_BEFORE_MESSAGE_SEND = self::class . '::preSendMessage';
	public const EVENT_AFTER_MESSAGE_SEND = self::class . '::postSendMessage';

	public const MAX_CHAT_LENGTH = 32000;

	/** @var CommentsManager|ICommentsManager */
	private $commentsManager;
	/** @var IEventDispatcher */
	private $dispatcher;
	/** @var Notifier */
	private $notifier;
	/** @var ITimeFactory */
	protected $timeFactory;

	public function __construct(CommentsManager $commentsManager,
								IEventDispatcher $dispatcher,
								Notifier $notifier,
								ITimeFactory $timeFactory) {
		$this->commentsManager = $commentsManager;
		$this->dispatcher = $dispatcher;
		$this->notifier = $notifier;
		$this->timeFactory = $timeFactory;
	}

	/**
	 * Sends a new message to the given chat.
	 *
	 * @param Room $chat
	 * @param string $actorType
	 * @param string $actorId
	 * @param string $message
	 * @param \DateTime $creationDateTime
	 * @param bool $sendNotifications
	 * @return IComment
	 */
	public function addSystemMessage(Room $chat, string $actorType, string $actorId, string $message, \DateTime $creationDateTime, bool $sendNotifications): IComment {
		$comment = $this->commentsManager->create($actorType, $actorId, 'chat', (string) $chat->getId());
		$comment->setMessage($message, self::MAX_CHAT_LENGTH);
		$comment->setCreationDateTime($creationDateTime);
		$comment->setVerb('system');

		$event = new ChatEvent($chat, $comment);
		$this->dispatcher->dispatch(self::EVENT_BEFORE_SYSTEM_MESSAGE_SEND, $event);
		try {
			$this->commentsManager->save($comment);

			// Update last_message
			$chat->setLastMessage($comment);

			if ($sendNotifications) {
				$this->notifier->notifyOtherParticipant($chat, $comment, []);
			}

			$this->dispatcher->dispatch(self::EVENT_AFTER_SYSTEM_MESSAGE_SEND, $event);
		} catch (NotFoundException $e) {
		}

		return $comment;
	}

	/**
	 * Sends a new message to the given chat.
	 *
	 * @param Room $chat
	 * @param string $message
	 * @return IComment
	 */
	public function addSpecialMessage(Room $chat, string $type, string $message): IComment {
		$comment = $this->commentsManager->create('guests', $type, 'chat', (string) $chat->getId());

		$comment->setMessage($message, self::MAX_CHAT_LENGTH);
		$comment->setCreationDateTime($this->timeFactory->getDateTime());
		$comment->setVerb('comment'); // Has to be comment, so it counts as unread message

		$event = new ChatEvent($chat, $comment);
		$this->dispatcher->dispatch(self::EVENT_BEFORE_SYSTEM_MESSAGE_SEND, $event);
		try {
			$this->commentsManager->save($comment);

			// Update last_message
			$chat->setLastMessage($comment);

			$this->dispatcher->dispatch(self::EVENT_AFTER_SYSTEM_MESSAGE_SEND, $event);
		} catch (NotFoundException $e) {
		}

		return $comment;
	}

	/**
	 * Sends a new message to the given chat.
	 *
	 * @param Room $chat
	 * @param Participant $participant
	 * @param string $actorType
	 * @param string $actorId
	 * @param string $message
	 * @param \DateTime $creationDateTime
	 * @param IComment|null $replyTo
	 * @param string $referenceId
	 * @return IComment
	 */
	public function sendMessage(Room $chat, Participant $participant, string $actorType, string $actorId, string $message, \DateTime $creationDateTime, ?IComment $replyTo, string $referenceId): IComment {
		$comment = $this->commentsManager->create($actorType, $actorId, 'chat', (string) $chat->getId());
		$comment->setMessage($message, self::MAX_CHAT_LENGTH);
		$comment->setCreationDateTime($creationDateTime);
		// A verb ('comment', 'like'...) must be provided to be able to save a
		// comment
		$comment->setVerb('comment');

		if ($replyTo instanceof IComment) {
			$comment->setParentId($replyTo->getId());
		}

		$referenceId = trim(substr($referenceId, 0, 40));
		if ($referenceId !== '') {
			$comment->setReferenceId($referenceId);
		}

		$event = new ChatParticipantEvent($chat, $comment, $participant);
		$this->dispatcher->dispatch(self::EVENT_BEFORE_MESSAGE_SEND, $event);

		try {
			$this->commentsManager->save($comment);

			// Update last_message
			$chat->setLastMessage($comment);

			$alreadyNotifiedUsers = [];
			if ($replyTo instanceof IComment) {
				$alreadyNotifiedUsers = $this->notifier->notifyReplyToAuthor($chat, $comment, $replyTo);
			}

			$alreadyNotifiedUsers = $this->notifier->notifyMentionedUsers($chat, $comment, $alreadyNotifiedUsers);
			if (!empty($alreadyNotifiedUsers)) {
				$chat->markUsersAsMentioned($alreadyNotifiedUsers, (int) $comment->getId());
			}

			// User was not mentioned, send a normal notification
			$this->notifier->notifyOtherParticipant($chat, $comment, $alreadyNotifiedUsers);

			$this->dispatcher->dispatch(self::EVENT_AFTER_MESSAGE_SEND, $event);
		} catch (NotFoundException $e) {
		}

		return $comment;
	}

	/**
	 * @param Room $chat
	 * @param string $parentId
	 * @return IComment
	 * @throws NotFoundException
	 */
	public function getParentComment(Room $chat, string $parentId): IComment {
		$comment = $this->commentsManager->get($parentId);

		if ($comment->getObjectType() !== 'chat' || $comment->getObjectId() !== (string) $chat->getId()) {
			throw new NotFoundException('Parent not found in the right context');
		}

		return $comment;
	}

	public function getLastReadMessageFromLegacy(Room $chat, IUser $user): int {
		$marker = $this->commentsManager->getReadMark('chat', $chat->getId(), $user);
		if ($marker === null) {
			return 0;
		}

		return $this->commentsManager->getLastCommentBeforeDate('chat', (string) $chat->getId(), $marker, 'comment');
	}

	public function getUnreadCount(Room $chat, int $lastReadMessage): int {
		return $this->commentsManager->getNumberOfCommentsForObjectSinceComment('chat', (string) $chat->getId(), $lastReadMessage, 'comment');
	}

	/**
	 * Receive the history of a chat
	 *
	 * @param Room $chat
	 * @param int $offset Last known message id
	 * @param int $limit
	 * @param bool $includeLastKnown
	 * @return IComment[] the messages found (only the id, actor type and id,
	 *         creation date and message are relevant), or an empty array if the
	 *         timeout expired.
	 */
	public function getHistory(Room $chat, int $offset, int $limit, bool $includeLastKnown): array {
		return $this->commentsManager->getForObjectSince('chat', (string) $chat->getId(), $offset, 'desc', $limit, $includeLastKnown);
	}

	/**
	 * If there are currently no messages the response will not be sent
	 * immediately. Instead, HTTP connection will be kept open waiting for new
	 * messages to arrive and, when they do, then the response will be sent. The
	 * connection will not be kept open indefinitely, though; the number of
	 * seconds to wait for new messages to arrive can be set using the timeout
	 * parameter; the default timeout is 30 seconds, maximum timeout is 60
	 * seconds. If the timeout ends a successful but empty response will be
	 * sent.
	 *
	 * @param Room $chat
	 * @param int $offset Last known message id
	 * @param int $limit
	 * @param int $timeout
	 * @param IUser|null $user
	 * @param bool $includeLastKnown
	 * @return IComment[] the messages found (only the id, actor type and id,
	 *         creation date and message are relevant), or an empty array if the
	 *         timeout expired.
	 */
	public function waitForNewMessages(Room $chat, int $offset, int $limit, int $timeout, ?IUser $user, bool $includeLastKnown): array {
		if ($user instanceof IUser) {
			$this->notifier->markMentionNotificationsRead($chat, $user->getUID());
		}
		$elapsedTime = 0;

		$comments = $this->commentsManager->getForObjectSince('chat', (string) $chat->getId(), $offset, 'asc', $limit, $includeLastKnown);

		while (empty($comments) && $elapsedTime < $timeout) {
			sleep(1);
			$elapsedTime++;

			$comments = $this->commentsManager->getForObjectSince('chat', (string) $chat->getId(), $offset, 'asc', $limit, $includeLastKnown);
		}

		return $comments;
	}

	/**
	 * Deletes all the messages for the given chat.
	 *
	 * @param Room $chat
	 */
	public function deleteMessages(Room $chat): void {
		$this->commentsManager->deleteCommentsAtObject('chat', (string) $chat->getId());

		$this->notifier->removePendingNotificationsForRoom($chat);
	}
}
