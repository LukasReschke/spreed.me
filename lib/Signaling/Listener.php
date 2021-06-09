<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2019 Joas Schilling <coding@schilljs.com>
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

namespace OCA\Talk\Signaling;

use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Config;
use OCA\Talk\Events\AddParticipantsEvent;
use OCA\Talk\Events\ChatEvent;
use OCA\Talk\Events\ChatParticipantEvent;
use OCA\Talk\Events\ModifyParticipantEvent;
use OCA\Talk\Events\ParticipantEvent;
use OCA\Talk\Events\RemoveParticipantEvent;
use OCA\Talk\Events\RemoveUserEvent;
use OCA\Talk\Events\RoomEvent;
use OCA\Talk\GuestManager;
use OCA\Talk\Model\Session;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\SessionService;
use OCP\EventDispatcher\IEventDispatcher;

class Listener {
	public static function register(IEventDispatcher $dispatcher): void {
		self::registerInternalSignaling($dispatcher);
		self::registerExternalSignaling($dispatcher);
	}

	protected static function isUsingInternalSignaling(): bool {
		/** @var Config $config */
		$config = \OC::$server->query(Config::class);
		return $config->getSignalingMode() === Config::SIGNALING_INTERNAL;
	}

	protected static function registerInternalSignaling(IEventDispatcher $dispatcher): void {
		$listener = static function (RoomEvent $event) {
			if (!self::isUsingInternalSignaling()) {
				return;
			}

			/** @var Messages $messages */
			$messages = \OC::$server->query(Messages::class);
			$messages->addMessageForAllParticipants($event->getRoom(), 'refresh-participant-list');
		};
		$dispatcher->addListener(Room::EVENT_AFTER_ROOM_CONNECT, $listener);
		$dispatcher->addListener(Room::EVENT_AFTER_GUEST_CONNECT, $listener);
		$dispatcher->addListener(Room::EVENT_AFTER_SESSION_JOIN_CALL, $listener);
		$dispatcher->addListener(Room::EVENT_AFTER_SESSION_UPDATE_CALL_FLAGS, $listener);
		$dispatcher->addListener(Room::EVENT_AFTER_SESSION_LEAVE_CALL, $listener);
		$dispatcher->addListener(GuestManager::EVENT_AFTER_NAME_UPDATE, $listener);

		$listener = static function (ParticipantEvent $event) {
			if (!self::isUsingInternalSignaling()) {
				return;
			}

			$room = $event->getRoom();

			/** @var Messages $messages */
			$messages = \OC::$server->query(Messages::class);
			$messages->addMessageForAllParticipants($room, 'refresh-participant-list');
		};
		$dispatcher->addListener(Room::EVENT_BEFORE_USER_REMOVE, $listener);
		$dispatcher->addListener(Room::EVENT_BEFORE_PARTICIPANT_REMOVE, $listener);
		$dispatcher->addListener(Room::EVENT_BEFORE_ROOM_DISCONNECT, $listener);
		$dispatcher->addListener(Room::EVENT_AFTER_PARTICIPANT_PUBLISHING_PERMISSIONS_SET, $listener);

		$listener = static function (RoomEvent $event) {
			$room = $event->getRoom();
			if (!self::isUsingInternalSignaling()) {
				return;
			}

			/** @var Messages $messages */
			$messages = \OC::$server->query(Messages::class);
			$messages->addMessageForAllParticipants($room, 'refresh-participant-list');
		};
		$dispatcher->addListener(Room::EVENT_BEFORE_ROOM_DELETE, $listener);
	}

	protected static function registerExternalSignaling(IEventDispatcher $dispatcher): void {
		$dispatcher->addListener(Room::EVENT_AFTER_USERS_ADD, static function (AddParticipantsEvent $event) {
			if (self::isUsingInternalSignaling()) {
				return;
			}

			/** @var BackendNotifier $notifier */
			$notifier = \OC::$server->query(BackendNotifier::class);

			$notifier->roomInvited($event->getRoom(), $event->getParticipants());
		});
		$listener = static function (RoomEvent $event) {
			if (self::isUsingInternalSignaling()) {
				return;
			}

			/** @var BackendNotifier $notifier */
			$notifier = \OC::$server->query(BackendNotifier::class);

			$notifier->roomModified($event->getRoom());
		};
		$dispatcher->addListener(Room::EVENT_AFTER_NAME_SET, $listener);
		$dispatcher->addListener(Room::EVENT_AFTER_DESCRIPTION_SET, $listener);
		$dispatcher->addListener(Room::EVENT_AFTER_PASSWORD_SET, $listener);
		$dispatcher->addListener(Room::EVENT_AFTER_TYPE_SET, $listener);
		$dispatcher->addListener(Room::EVENT_AFTER_READONLY_SET, $listener);
		$dispatcher->addListener(Room::EVENT_AFTER_LISTABLE_SET, $listener);
		$dispatcher->addListener(Room::EVENT_AFTER_LOBBY_STATE_SET, $listener);
		$dispatcher->addListener(Room::EVENT_AFTER_SIP_ENABLED_SET, $listener);
		// TODO remove handler with "roomModified" in favour of handler with
		// "participantsModified" once the clients no longer expect a
		// "roomModified" message for participant type changes.
		$dispatcher->addListener(Room::EVENT_AFTER_PARTICIPANT_TYPE_SET, $listener);

		$listener = static function (ModifyParticipantEvent $event) {
			if (self::isUsingInternalSignaling()) {
				return;
			}

			/** @var BackendNotifier $notifier */
			$notifier = \OC::$server->query(BackendNotifier::class);

			$sessionIds = [];
			// If the participant is not active in the room the "participants"
			// request will be sent anyway, although with an empty "changed"
			// property.

			/** @var SessionService $sessionService */
			$sessionService = \OC::$server->query(SessionService::class);
			$sessions = $sessionService->getAllSessionsForAttendee($event->getParticipant()->getAttendee());
			foreach ($sessions as $session) {
				$sessionIds[] = $session->getSessionId();
			}

			$notifier->participantsModified($event->getRoom(), $sessionIds);
		};
		$dispatcher->addListener(Room::EVENT_AFTER_PARTICIPANT_TYPE_SET, $listener);
		$dispatcher->addListener(Room::EVENT_AFTER_PARTICIPANT_PUBLISHING_PERMISSIONS_SET, $listener);

		$dispatcher->addListener(Room::EVENT_BEFORE_ROOM_DELETE, static function (RoomEvent $event) {
			if (self::isUsingInternalSignaling()) {
				return;
			}

			/** @var BackendNotifier $notifier */
			$notifier = \OC::$server->query(BackendNotifier::class);
			/** @var ParticipantService $participantService */
			$participantService = \OC::$server->query(ParticipantService::class);

			$room = $event->getRoom();
			$notifier->roomDeleted($room, $participantService->getParticipantUserIds($room));
		});
		$dispatcher->addListener(Room::EVENT_AFTER_USER_REMOVE, static function (RemoveUserEvent $event) {
			if (self::isUsingInternalSignaling()) {
				return;
			}

			/** @var BackendNotifier $notifier */
			$notifier = \OC::$server->query(BackendNotifier::class);

			$notifier->roomsDisinvited($event->getRoom(), [$event->getUser()->getUID()]);
		});
		$dispatcher->addListener(Room::EVENT_AFTER_PARTICIPANT_REMOVE, static function (RemoveParticipantEvent $event) {
			if (self::isUsingInternalSignaling()) {
				return;
			}

			/** @var BackendNotifier $notifier */
			$notifier = \OC::$server->query(BackendNotifier::class);

			$sessionIds = [];

			/** @var SessionService $sessionService */
			$sessionService = \OC::$server->query(SessionService::class);
			$sessions = $sessionService->getAllSessionsForAttendee($event->getParticipant()->getAttendee());
			foreach ($sessions as $session) {
				$sessionIds[] = $session->getSessionId();
			}

			if ($event->getParticipant()->getSession()) {
				$sessionIds[] = $event->getParticipant()->getSession()->getSessionId();
				$notifier->roomSessionsRemoved($event->getRoom(), $sessionIds);
			}

			if (!empty($sessionIds)) {
				$notifier->roomSessionsRemoved($event->getRoom(), $sessionIds);
			}
		});
		$dispatcher->addListener(Room::EVENT_AFTER_ROOM_DISCONNECT, static function (ParticipantEvent $event) {
			if (self::isUsingInternalSignaling()) {
				return;
			}

			/** @var BackendNotifier $notifier */
			$notifier = \OC::$server->query(BackendNotifier::class);

			$sessionIds = [];
			if ($event->getParticipant()->getSession()) {
				$sessionIds[] = $event->getParticipant()->getSession()->getSessionId();
				$notifier->roomSessionsRemoved($event->getRoom(), $sessionIds);
			}
		});

		$listener = static function (ModifyParticipantEvent $event) {
			if (self::isUsingInternalSignaling()) {
				return;
			}

			/** @var BackendNotifier $notifier */
			$notifier = \OC::$server->query(BackendNotifier::class);

			$sessionIds = [];

			/** @var SessionService $sessionService */
			$sessionService = \OC::$server->query(SessionService::class);
			$sessions = $sessionService->getAllSessionsForAttendee($event->getParticipant()->getAttendee());
			foreach ($sessions as $session) {
				$sessionIds[] = $session->getSessionId();
			}

			if (!empty($sessionIds)) {
				$notifier->roomInCallChanged(
					$event->getRoom(),
					$event->getNewValue(),
					$sessionIds
				);
			}
		};
		$dispatcher->addListener(Room::EVENT_AFTER_SESSION_JOIN_CALL, $listener);
		$dispatcher->addListener(Room::EVENT_AFTER_SESSION_UPDATE_CALL_FLAGS, $listener);
		$dispatcher->addListener(Room::EVENT_AFTER_SESSION_LEAVE_CALL, $listener);

		$dispatcher->addListener(Room::EVENT_AFTER_GUESTS_CLEAN, static function (RoomEvent $event) {
			if (self::isUsingInternalSignaling()) {
				return;
			}

			/** @var BackendNotifier $notifier */
			$notifier = \OC::$server->query(BackendNotifier::class);

			// TODO: The list of removed session ids should be passed through the event
			// so the signaling server can optimize forwarding the message.
			$sessionIds = [];
			$notifier->participantsModified($event->getRoom(), $sessionIds);
		});
		$dispatcher->addListener(GuestManager::EVENT_AFTER_NAME_UPDATE, static function (ModifyParticipantEvent $event) {
			if (self::isUsingInternalSignaling()) {
				return;
			}

			/** @var BackendNotifier $notifier */
			$notifier = \OC::$server->query(BackendNotifier::class);

			$sessionIds = [];

			/** @var SessionService $sessionService */
			$sessionService = \OC::$server->query(SessionService::class);
			$sessions = $sessionService->getAllSessionsForAttendee($event->getParticipant()->getAttendee());
			foreach ($sessions as $session) {
				$sessionIds[] = $session->getSessionId();
			}

			if (!empty($sessionIds)) {
				$notifier->participantsModified($event->getRoom(), $sessionIds);
			}
		});
		$dispatcher->addListener(ChatManager::EVENT_AFTER_MESSAGE_SEND , static function (ChatParticipantEvent $event) {
			if (self::isUsingInternalSignaling()) {
				return;
			}

			/** @var BackendNotifier $notifier */
			$notifier = \OC::$server->query(BackendNotifier::class);

			$room = $event->getRoom();
			$message = [
				'type' => 'chat',
				'chat' => [
					'refresh' => true,
				],
			];
			$notifier->sendRoomMessage($room, $message);
		});
		$dispatcher->addListener(ChatManager::EVENT_AFTER_SYSTEM_MESSAGE_SEND, static function (ChatEvent $event) {
			if (self::isUsingInternalSignaling()) {
				return;
			}

			/** @var BackendNotifier $notifier */
			$notifier = \OC::$server->query(BackendNotifier::class);

			$room = $event->getRoom();
			$message = [
				'type' => 'chat',
				'chat' => [
					'refresh' => true,
				],
			];
			$notifier->sendRoomMessage($room, $message);
		});
	}
}
