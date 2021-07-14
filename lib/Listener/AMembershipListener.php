<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020 Joas Schilling <coding@schilljs.com>
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

namespace OCA\Talk\Listener;

use OCA\Circles\Api\v1\Circles;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUser;

abstract class AMembershipListener implements IEventListener {

	/** @var Manager */
	protected $manager;
	/** @var IAppManager */
	protected $appManager;
	/** @var IGroupManager */
	protected $groupManager;
	/** @var ParticipantService */
	protected $participantService;

	public function __construct(Manager $manager,
								IAppManager $appManager,
								IGroupManager $groupManager,
								ParticipantService $participantService) {
		$this->manager = $manager;
		$this->appManager = $appManager;
		$this->groupManager = $groupManager;
		$this->participantService = $participantService;
	}

	protected function removeFromRoomsUnlessStillLinked(array $rooms, IUser $user): void {
		$rooms = $this->filterRoomsWithOtherGroupMemberships($rooms, $user);
		$rooms = $this->filterRoomsWithOtherCircleMemberships($rooms, $user);

		foreach ($rooms as $room) {
			try {
				$participant = $room->getParticipant($user->getUID());
				$participantType = $participant->getAttendee()->getParticipantType();
				if ($participantType === Participant::USER) {
					$this->participantService->removeUser($room, $user, Room::PARTICIPANT_REMOVED);
				}
			} catch (ParticipantNotFoundException $e) {
			}
		}
	}

	protected function filterRoomsWithOtherGroupMemberships(array $rooms, IUser $user): array {
		$userGroupIds = $this->groupManager->getUserGroupIds($user);

		$furtherMemberships = [];
		foreach ($userGroupIds as $groupId) {
			$groupRooms = $this->manager->getRoomsForActor(Attendee::ACTOR_GROUPS, $groupId);
			foreach ($groupRooms as $room) {
				$furtherMemberships[$room->getId()] = true;
			}
		}

		return array_filter($rooms, static function (Room $room) use ($furtherMemberships) {
			// Only delete from rooms where the user is not member via another group
			return !isset($furtherMemberships[$room->getId()]);
		});
	}

	protected function filterRoomsWithOtherCircleMemberships(array $rooms, IUser $user): array {
		if (!$this->appManager->isEnabledForUser('circles', $user)) {
			\OC::$server->getLogger()->error('Circles not enabled', ['app' => 'nickv']);
			return $rooms;
		}

		try {
			/** @var Circles $circlesApi */
			$circles = Circles::joinedCircles($user->getUID());
		} catch (\Exception $e) {
			return $rooms;
		}

		$furtherMemberships = [];
		foreach ($circles as $circle) {
			$circleRooms = $this->manager->getRoomsForActor(Attendee::ACTOR_CIRCLES, $circle->getSingleId());

			foreach ($circleRooms as $room) {
				$furtherMemberships[$room->getId()] = true;
			}
		}

		return array_filter($rooms, static function (Room $room) use ($furtherMemberships) {
			// Only delete from rooms where the user is not member via another group
			return !isset($furtherMemberships[$room->getId()]);
		});
	}
}
