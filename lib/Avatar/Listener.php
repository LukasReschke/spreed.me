<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2020, Daniel Calviño Sánchez (danxuliu@gmail.com)
 *
 * @author Daniel Calviño Sánchez <danxuliu@gmail.com>
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Talk\Avatar;

use OCA\Talk\Manager;
use OCA\Talk\Room;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use Symfony\Component\EventDispatcher\GenericEvent;

class Listener {

	/** @var Manager */
	private $manager;

	/**
	 * @param Manager $manager
	 */
	public function __construct(
			Manager $manager) {
		$this->manager = $manager;
	}

	public static function register(IEventDispatcher $dispatcher): void {
		$listener = static function (GenericEvent $event) {
			if ($event->getArgument('feature') !== 'avatar') {
				return;
			}

			/** @var self $listener */
			$listener = \OC::$server->query(self::class);
			$listener->updateRoomAvatarsFromChangedUserAvatar($event->getSubject());
		};
		$dispatcher->addListener(IUser::class . '::changeUser', $listener);
	}

	/**
	 * Updates the associated room avatars from the changed user avatar
	 *
	 * The avatar versions of all the one-to-one conversations of that user are
	 * bumped.
	 *
	 * Note that the avatar seen by the user who has changed her avatar will not
	 * change, as she will get the avatar of the other user, but even if the
	 * avatar images are independent the avatar version is a shared value and
	 * needs to be bumped for both.
	 *
	 * @param IUser $user the user whose avatar changed
	 */
	public function updateRoomAvatarsFromChangedUserAvatar(IUser $user): void {
		$rooms = $this->manager->getRoomsForUser($user->getUID());
		foreach ($rooms as $room) {
			if ($room->getType() !== Room::ONE_TO_ONE_CALL) {
				continue;
			}

			$room->setAvatar($room->getAvatarId(), $room->getAvatarVersion() + 1);
		}
	}
}
