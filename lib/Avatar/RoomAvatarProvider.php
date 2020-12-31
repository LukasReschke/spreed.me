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

use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Manager;
use OCA\Talk\Room;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\IAvatar;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

class RoomAvatarProvider {

	/** @var IAppData */
	private $appData;

	/** @var Manager */
	private $manager;

	/** @var IL10N */
	private $l;

	/** @var LoggerInterface */
	private $logger;

	/** @var Util */
	private $util;

	public function __construct(
			IAppData $appData,
			Manager $manager,
			IL10N $l,
			LoggerInterface $logger,
			Util $util) {
		$this->appData = $appData;
		$this->manager = $manager;
		$this->l = $l;
		$this->logger = $logger;
		$this->util = $util;
	}

	/**
	 * Returns a RoomAvatar instance for the given room token
	 *
	 * @param string $id the identifier of the avatar
	 * @returns IAvatar the RoomAvatar
	 * @throws RoomNotFoundException if there is no room with the given token
	 */
	public function getAvatar(string $id): IAvatar {
		$room = $this->manager->getRoomByToken($id);

		try {
			$folder = $this->appData->getFolder('avatar/' . $id);
		} catch (NotFoundException $e) {
			$folder = $this->appData->newFolder('avatar/' . $id);
		}

		return new RoomAvatar($folder, $room, $this->l, $this->logger, $this->util);
	}

	/**
	 * Returns whether the current user can access the given avatar or not
	 *
	 * @param IAvatar $avatar the avatar to check
	 * @return bool true if the room is public, the current user is a
	 *         participant of the room or can list it, false otherwise
	 * @throws \InvalidArgumentException if the given avatar is not a RoomAvatar
	 */
	public function canBeAccessedByCurrentUser(IAvatar $avatar): bool {
		if (!($avatar instanceof RoomAvatar)) {
			throw new \InvalidArgumentException();
		}

		$room = $avatar->getRoom();

		if ($room->getType() === Room::PUBLIC_CALL) {
			return true;
		}

		try {
			$this->util->getCurrentParticipant($room);
		} catch (ParticipantNotFoundException $e) {
			return $this->util->isRoomListableByUser($room);
		}

		return true;
	}

	/**
	 * Returns whether the current user can modify the given avatar or not
	 *
	 * @param IAvatar $avatar the avatar to check
	 * @return bool true if the current user is a moderator of the room and the
	 *         room is not a one-to-one, password request or file room, false
	 *         otherwise
	 * @throws \InvalidArgumentException if the given avatar is not a RoomAvatar
	 */
	public function canBeModifiedByCurrentUser(IAvatar $avatar): bool {
		if (!($avatar instanceof RoomAvatar)) {
			throw new \InvalidArgumentException();
		}

		$room = $avatar->getRoom();

		if ($room->getType() === Room::ONE_TO_ONE_CALL) {
			return false;
		}

		if ($room->getObjectType() === 'share:password') {
			return false;
		}

		if ($room->getObjectType() === 'file') {
			return false;
		}

		try {
			$currentParticipant = $this->util->getCurrentParticipant($room);
		} catch (ParticipantNotFoundException $e) {
			return false;
		}

		return $currentParticipant->hasModeratorPermissions();
	}

	/**
	 * Returns the latest value of the avatar version
	 *
	 * @param IAvatar $avatar
	 * @return int
	 * @throws \InvalidArgumentException if the given avatar is not a RoomAvatar
	 */
	public function getVersion(IAvatar $avatar): int {
		if (!($avatar instanceof RoomAvatar)) {
			throw new \InvalidArgumentException();
		}

		$room = $avatar->getRoom();

		return $room->getAvatarVersion();
	}

	/**
	 * Returns the cache duration for room avatars in seconds
	 *
	 * @param IAvatar $avatar ignored, same duration for all room avatars
	 * @return int|null the cache duration
	 */
	public function getCacheTimeToLive(IAvatar $avatar): ?int {
		// Cache for 1 day.
		return 60 * 60 * 24;
	}
}
