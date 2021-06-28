<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2017 Joas Schilling <coding@schilljs.com>
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

namespace OCA\Talk\Notification;

use OCA\Talk\Events\AddParticipantsEvent;
use OCA\Talk\Events\JoinRoomUserEvent;
use OCA\Talk\Events\RoomEvent;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Notification\IManager;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class Listener {

	/** @var IManager */
	protected $notificationManager;
	/** @var ParticipantService */
	protected $participantsService;
	/** @var IEventDispatcher */
	protected $dispatcher;
	/** @var IUserSession */
	protected $userSession;
	/** @var ITimeFactory */
	protected $timeFactory;
	/** @var LoggerInterface */
	protected $logger;

	/** @var bool */
	protected $shouldSendCallNotification = false;

	public function __construct(IManager $notificationManager,
								ParticipantService $participantsService,
								IEventDispatcher $dispatcher,
								IUserSession $userSession,
								ITimeFactory $timeFactory,
								LoggerInterface $logger) {
		$this->notificationManager = $notificationManager;
		$this->participantsService = $participantsService;
		$this->dispatcher = $dispatcher;
		$this->userSession = $userSession;
		$this->timeFactory = $timeFactory;
		$this->logger = $logger;
	}

	public static function register(IEventDispatcher $dispatcher): void {
		$listener = static function (AddParticipantsEvent $event) {
			$room = $event->getRoom();

			if ($room->getObjectType() === 'file') {
				return;
			}

			/** @var self $listener */
			$listener = \OC::$server->query(self::class);
			$listener->generateInvitation($room, $event->getParticipants());
		};
		$dispatcher->addListener(Room::EVENT_AFTER_USERS_ADD, $listener);

		$listener = static function (JoinRoomUserEvent $event) {
			/** @var self $listener */
			$listener = \OC::$server->query(self::class);
			$listener->markInvitationRead($event->getRoom());
			$listener->markChatNotificationsRead($event->getRoom());
		};
		$dispatcher->addListener(Room::EVENT_AFTER_ROOM_CONNECT, $listener);

		$listener = static function (RoomEvent $event) {
			/** @var self $listener */
			$listener = \OC::$server->query(self::class);
			$listener->checkCallNotifications($event->getRoom());
		};
		$dispatcher->addListener(Room::EVENT_BEFORE_SESSION_JOIN_CALL, $listener);

		$listener = static function (RoomEvent $event) {
			/** @var self $listener */
			$listener = \OC::$server->query(self::class);
			$listener->sendCallNotifications($event->getRoom());
		};
		$dispatcher->addListener(Room::EVENT_AFTER_SESSION_JOIN_CALL, $listener);

		$listener = static function (RoomEvent $event) {
			/** @var self $listener */
			$listener = \OC::$server->query(self::class);
			$listener->markCallNotificationsRead($event->getRoom());
		};
		$dispatcher->addListener(Room::EVENT_AFTER_SESSION_JOIN_CALL, $listener);
	}

	/**
	 * Room invitation: "{actor} invited you to {call}"
	 *
	 * @param Room $room
	 * @param array[] $participants
	 */
	public function generateInvitation(Room $room, array $participants): void {
		$actor = $this->userSession->getUser();
		if (!$actor instanceof IUser) {
			return;
		}
		$actorId = $actor->getUID();

		$notification = $this->notificationManager->createNotification();
		$shouldFlush = $this->notificationManager->defer();
		$dateTime = $this->timeFactory->getDateTime();
		try {
			$notification->setApp('spreed')
				->setDateTime($dateTime)
				->setObject('room', $room->getToken())
				->setSubject('invitation', [
					'actorId' => $actor->getUID(),
				]);
		} catch (\InvalidArgumentException $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			if ($shouldFlush) {
				$this->notificationManager->flush();
			}
			return;
		}

		foreach ($participants as $participant) {
			if ($participant['actorType'] !== Attendee::ACTOR_USERS) {
				// No user => no activity
				continue;
			}

			if ($actorId === $participant['actorId']) {
				// No activity for self-joining and the creator
				continue;
			}

			try {
				$notification->setUser($participant['actorId']);
				$this->notificationManager->notify($notification);
			} catch (\InvalidArgumentException $e) {
				$this->logger->error($e->getMessage(), ['exception' => $e]);
			}
		}

		if ($shouldFlush) {
			$this->notificationManager->flush();
		}
	}

	/**
	 * Room invitation: "{actor} invited you to {call}"
	 *
	 * @param Room $room
	 */
	public function markInvitationRead(Room $room): void {
		$currentUser = $this->userSession->getUser();
		if (!$currentUser instanceof IUser) {
			return;
		}

		$notification = $this->notificationManager->createNotification();
		try {
			$notification->setApp('spreed')
				->setUser($currentUser->getUID())
				->setObject('room', $room->getToken())
				->setSubject('invitation');
			$this->notificationManager->markProcessed($notification);
		} catch (\InvalidArgumentException $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return;
		}
	}

	/**
	 * Chat notification: "{actor} mentioned you conversation {conversation}"
	 *
	 * @param Room $room
	 */
	public function markChatNotificationsRead(Room $room): void {
		$currentUser = $this->userSession->getUser();
		if (!$currentUser instanceof IUser) {
			return;
		}

		$shouldFlush = $this->notificationManager->defer();
		$notification = $this->notificationManager->createNotification();
		try {
			$notification->setApp('spreed')
				->setObject('chat', $room->getToken())
				->setUser($currentUser->getUID());
			$this->notificationManager->markProcessed($notification);
		} catch (\InvalidArgumentException $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
		}

		if ($shouldFlush) {
			$this->notificationManager->flush();
		}
	}

	/**
	 * Call notification: "{user} wants to talk with you"
	 *
	 * @param Room $room
	 */
	public function checkCallNotifications(Room $room): void {
		if ($room->getActiveSince() instanceof \DateTime) {
			// Call already active => No new notifications
			$this->shouldSendCallNotification = false;
			return;
		}

		if ($room->getObjectType() === 'file') {
			$this->shouldSendCallNotification = false;
			return;
		}

		$this->shouldSendCallNotification = true;
	}

	/**
	 * Call notification: "{user} wants to talk with you"
	 *
	 * @param Room $room
	 */
	public function sendCallNotifications(Room $room): void {
		if (!$this->shouldSendCallNotification) {
			return;
		}

		$actor = $this->userSession->getUser();
		$actorId = $actor instanceof IUser ? $actor->getUID() :'';

		$notification = $this->notificationManager->createNotification();
		$shouldFlush = $this->notificationManager->defer();
		$dateTime = $this->timeFactory->getDateTime();
		try {
			// Remove all old notifications for this room
			$notification->setApp('spreed')
				->setObject('room', $room->getToken());
			$this->notificationManager->markProcessed($notification);

			$notification->setObject('call', $room->getToken());
			$this->notificationManager->markProcessed($notification);

			$notification->setSubject('call', [
				'callee' => $actorId,
			])
				->setDateTime($dateTime);
		} catch (\InvalidArgumentException $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			if ($shouldFlush) {
				$this->notificationManager->flush();
			}
			return;
		}

		$userIds = $this->participantsService->getParticipantUserIdsNotInCall($room);
		foreach ($userIds as $userId) {
			if ($actorId === $userId) {
				continue;
			}

			try {
				$notification->setUser($userId);
				$this->notificationManager->notify($notification);
			} catch (\InvalidArgumentException $e) {
				$this->logger->error($e->getMessage(), ['exception' => $e]);
			}
		}

		if ($shouldFlush) {
			$this->notificationManager->flush();
		}
	}

	/**
	 * Call notification: "{user} wants to talk with you"
	 *
	 * @param Room $room
	 */
	public function markCallNotificationsRead(Room $room): void {
		$currentUser = $this->userSession->getUser();
		if (!$currentUser instanceof IUser) {
			return;
		}

		$notification = $this->notificationManager->createNotification();
		try {
			$notification->setApp('spreed')
				->setUser($currentUser->getUID())
				->setObject('call', $room->getToken())
				->setSubject('call');
			$this->notificationManager->markProcessed($notification);
		} catch (\InvalidArgumentException $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return;
		}
	}
}
