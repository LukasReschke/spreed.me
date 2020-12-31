<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2018 Joas Schilling <coding@schilljs.com>
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

namespace OCA\Talk\Chat\SystemMessage;

use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Events\AddParticipantsEvent;
use OCA\Talk\Events\ModifyLobbyEvent;
use OCA\Talk\Events\ModifyParticipantEvent;
use OCA\Talk\Events\ModifyRoomEvent;
use OCA\Talk\Events\RemoveUserEvent;
use OCA\Talk\Events\RoomEvent;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Session;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Share\RoomShareProvider;
use OCA\Talk\TalkSession;
use OCA\Talk\Webinary;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IShare;
use Symfony\Component\EventDispatcher\GenericEvent;

class Listener {

	/** @var IRequest */
	protected $request;
	/** @var ChatManager */
	protected $chatManager;
	/** @var TalkSession */
	protected $talkSession;
	/** @var IUserSession */
	protected $userSession;
	/** @var ITimeFactory */
	protected $timeFactory;

	public function __construct(IRequest $request,
								ChatManager $chatManager,
								TalkSession $talkSession,
								IUserSession $userSession,
								ITimeFactory $timeFactory) {
		$this->request = $request;
		$this->chatManager = $chatManager;
		$this->talkSession = $talkSession;
		$this->userSession = $userSession;
		$this->timeFactory = $timeFactory;
	}

	public static function register(IEventDispatcher $dispatcher): void {
		$dispatcher->addListener(Room::EVENT_BEFORE_SESSION_JOIN_CALL, static function (ModifyParticipantEvent $event) {
			$room = $event->getRoom();
			/** @var self $listener */
			$listener = \OC::$server->query(self::class);
			/** @var ParticipantService $participantService */
			$participantService = \OC::$server->query(ParticipantService::class);

			if ($participantService->hasActiveSessionsInCall($room)) {
				$listener->sendSystemMessage($room, 'call_joined', [], $event->getParticipant());
			} else {
				$listener->sendSystemMessage($room, 'call_started', [], $event->getParticipant());
			}
		});
		$dispatcher->addListener(Room::EVENT_AFTER_SESSION_LEAVE_CALL, static function (ModifyParticipantEvent $event) {
			$room = $event->getRoom();

			$session = $event->getParticipant()->getSession();
			if (!$session instanceof Session) {
				// This happens in case the user was kicked/lobbied
				return;
			}

			/** @var self $listener */
			$listener = \OC::$server->query(self::class);

			$listener->sendSystemMessage($room, 'call_left', [], $event->getParticipant());
		});

		$dispatcher->addListener(Room::EVENT_AFTER_ROOM_CREATE, static function (RoomEvent $event) {
			$room = $event->getRoom();
			/** @var self $listener */
			$listener = \OC::$server->query(self::class);

			$listener->sendSystemMessage($room, 'conversation_created');
		});
		$dispatcher->addListener(Room::EVENT_AFTER_NAME_SET, static function (ModifyRoomEvent $event) {
			if ($event->getOldValue() === '' ||
				$event->getNewValue() === '') {
				return;
			}

			$room = $event->getRoom();
			/** @var self $listener */
			$listener = \OC::$server->query(self::class);

			$listener->sendSystemMessage($room, 'conversation_renamed', [
				'newName' => $event->getNewValue(),
				'oldName' => $event->getOldValue(),
			]);
		});
		$dispatcher->addListener(Room::EVENT_AFTER_DESCRIPTION_SET, static function (ModifyRoomEvent $event) {
			$room = $event->getRoom();
			/** @var self $listener */
			$listener = \OC::$server->get(self::class);

			if ($event->getNewValue() !== '') {
				$listener->sendSystemMessage($room, 'description_set', [
					'newDescription' => $event->getNewValue(),
				]);
			} else {
				$listener->sendSystemMessage($room, 'description_removed');
			}
		});
		$dispatcher->addListener(Room::EVENT_AFTER_AVATAR_SET, static function (ModifyRoomEvent $event) {
			$room = $event->getRoom();
			/** @var self $listener */
			$listener = \OC::$server->get(self::class);

			if ($event->getNewValue() === 'custom') {
				$listener->sendSystemMessage($room, 'avatar_set');
			} elseif ($event->getNewValue() !== 'custom' && $event->getOldValue() === 'custom') {
				$listener->sendSystemMessage($room, 'avatar_removed');
			}
		});
		$dispatcher->addListener(Room::EVENT_AFTER_PASSWORD_SET, static function (ModifyRoomEvent $event) {
			$room = $event->getRoom();
			/** @var self $listener */
			$listener = \OC::$server->query(self::class);

			if ($event->getNewValue() !== '') {
				$listener->sendSystemMessage($room, 'password_set');
			} else {
				$listener->sendSystemMessage($room, 'password_removed');
			}
		});
		$dispatcher->addListener(Room::EVENT_AFTER_TYPE_SET, static function (ModifyRoomEvent $event) {
			$room = $event->getRoom();

			if ($event->getNewValue() === Room::PUBLIC_CALL) {
				/** @var self $listener */
				$listener = \OC::$server->query(self::class);
				$listener->sendSystemMessage($room, 'guests_allowed');
			} elseif ($event->getNewValue() === Room::GROUP_CALL) {
				/** @var self $listener */
				$listener = \OC::$server->query(self::class);
				$listener->sendSystemMessage($room, 'guests_disallowed');
			}
		});
		$dispatcher->addListener(Room::EVENT_AFTER_READONLY_SET, static function (ModifyRoomEvent $event) {
			$room = $event->getRoom();

			if ($room->getType() === Room::CHANGELOG_CONVERSATION) {
				return;
			}

			/** @var self $listener */
			$listener = \OC::$server->query(self::class);

			if ($event->getNewValue() === Room::READ_ONLY) {
				$listener->sendSystemMessage($room, 'read_only');
			} elseif ($event->getNewValue() === Room::READ_WRITE) {
				$listener->sendSystemMessage($room, 'read_only_off');
			}
		});
		$dispatcher->addListener(Room::EVENT_AFTER_LISTABLE_SET, static function (ModifyRoomEvent $event) {
			$room = $event->getRoom();

			/** @var self $listener */
			$listener = \OC::$server->query(self::class);

			if ($event->getNewValue() === Room::LISTABLE_NONE) {
				$listener->sendSystemMessage($room, 'listable_none');
			} elseif ($event->getNewValue() === Room::LISTABLE_USERS) {
				$listener->sendSystemMessage($room, 'listable_users');
			} elseif ($event->getNewValue() === Room::LISTABLE_ALL) {
				$listener->sendSystemMessage($room, 'listable_all');
			}
		});
		$dispatcher->addListener(Room::EVENT_AFTER_LOBBY_STATE_SET, static function (ModifyLobbyEvent $event) {
			if ($event->getNewValue() === $event->getOldValue()) {
				return;
			}

			$room = $event->getRoom();

			/** @var self $listener */
			$listener = \OC::$server->query(self::class);

			if ($event->isTimerReached()) {
				$listener->sendSystemMessage($room, 'lobby_timer_reached');
			} elseif ($event->getNewValue() === Webinary::LOBBY_NONE) {
				$listener->sendSystemMessage($room, 'lobby_none');
			} elseif ($event->getNewValue() === Webinary::LOBBY_NON_MODERATORS) {
				$listener->sendSystemMessage($room, 'lobby_non_moderators');
			}
		});

		$dispatcher->addListener(Room::EVENT_AFTER_USERS_ADD, static function (AddParticipantsEvent $event) {
			$room = $event->getRoom();
			if ($room->getType() === Room::ONE_TO_ONE_CALL) {
				return;
			}

			/** @var self $listener */
			$listener = \OC::$server->query(self::class);

			$participants = $event->getParticipants();

			foreach ($participants as $participant) {
				if ($participant['actorType'] !== 'users') {
					continue;
				}

				$participantType = null;
				if (isset($participant['participantType'])) {
					$participantType = $participant['participantType'];
				}

				$userJoinedFileRoom = $room->getObjectType() === 'file' && $participantType !== Participant::USER_SELF_JOINED;

				// add a message "X joined the conversation", whenever user $userId:
				if (
					// - has joined a file room but not through a public link
					$userJoinedFileRoom
					// - has been added by another user (and not when creating a conversation)
					|| $listener->getUserId() !== $participant['actorId']
					// - has joined a listable room on their own
					|| $participantType === Participant::USER) {
					$listener->sendSystemMessage($room, 'user_added', ['user' => $participant['actorId']]);
				}
			}
		});
		$dispatcher->addListener(Room::EVENT_AFTER_USER_REMOVE, static function (RemoveUserEvent $event) {
			$room = $event->getRoom();

			if ($room->getType() === Room::ONE_TO_ONE_CALL) {
				return;
			}

			/** @var self $listener */
			$listener = \OC::$server->query(self::class);
			$listener->sendSystemMessage($room, 'user_removed', ['user' => $event->getUser()->getUID()]);
		});
		$dispatcher->addListener(Room::EVENT_AFTER_PARTICIPANT_TYPE_SET, static function (ModifyParticipantEvent $event) {
			$room = $event->getRoom();
			$attendee = $event->getParticipant()->getAttendee();

			if ($attendee->getActorType() !== Attendee::ACTOR_USERS && $attendee->getActorType() !== Attendee::ACTOR_GUESTS) {
				return;
			}

			if ($event->getNewValue() === Participant::MODERATOR) {
				/** @var self $listener */
				$listener = \OC::$server->query(self::class);
				$listener->sendSystemMessage($room, 'moderator_promoted', ['user' => $attendee->getActorId()]);
			} elseif ($event->getNewValue() === Participant::USER) {
				/** @var self $listener */
				$listener = \OC::$server->query(self::class);
				$listener->sendSystemMessage($room, 'moderator_demoted', ['user' => $attendee->getActorId()]);
			} elseif ($event->getNewValue() === Participant::GUEST_MODERATOR) {
				/** @var self $listener */
				$listener = \OC::$server->query(self::class);
				$listener->sendSystemMessage($room, 'guest_moderator_promoted', ['session' => $attendee->getActorId()]);
			} elseif ($event->getNewValue() === Participant::GUEST) {
				/** @var self $listener */
				$listener = \OC::$server->query(self::class);
				$listener->sendSystemMessage($room, 'guest_moderator_demoted', ['session' => $attendee->getActorId()]);
			}
		});
		$listener = function (GenericEvent $event) {
			/** @var IShare $share */
			$share = $event->getSubject();

			if ($share->getShareType() !== IShare::TYPE_ROOM) {
				return;
			}

			/** @var self $listener */
			$listener = \OC::$server->query(self::class);

			/** @var Manager $manager */
			$manager = \OC::$server->query(Manager::class);

			$room = $manager->getRoomByToken($share->getSharedWith());
			$listener->sendSystemMessage($room, 'file_shared', ['share' => $share->getId()]);
		};
		/**
		 * @psalm-suppress UndefinedClass
		 */
		$dispatcher->addListener('OCP\Share::postShare', $listener);
		$dispatcher->addListener(RoomShareProvider::class . '::' . 'share_file_again', $listener);
	}

	protected function sendSystemMessage(Room $room, string $message, array $parameters = [], Participant $participant = null): void {
		if ($participant instanceof Participant) {
			$actorType = $participant->getAttendee()->getActorType();
			$actorId = $participant->getAttendee()->getActorId();
		} else {
			$user = $this->userSession->getUser();
			if ($user instanceof IUser) {
				$actorType = 'users';
				$actorId = $user->getUID();
			} elseif (\OC::$CLI) {
				$actorType = Attendee::ACTOR_GUESTS;
				$actorId = 'cli';
			} else {
				$actorType = Attendee::ACTOR_GUESTS;
				$sessionId = $this->talkSession->getSessionForRoom($room->getToken());
				$actorId = $sessionId ? sha1($sessionId) : 'failed-to-get-session';
			}
		}

		// Little hack to get the reference id from the share request into
		// the system message left for the share in the chat.
		$referenceId = $this->request->getParam('referenceId', null);
		if ($referenceId !== null) {
			$referenceId = (string) $referenceId;
		}

		$this->chatManager->addSystemMessage(
			$room, $actorType, $actorId,
			json_encode(['message' => $message, 'parameters' => $parameters]),
			$this->timeFactory->getDateTime(), $message === 'file_shared',
			$referenceId
		);
	}

	protected function getUserId() {
		$user = $this->userSession->getUser();
		return $user instanceof IUser ? $user->getUID() : null;
	}
}
