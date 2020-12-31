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
use OCA\Talk\Manager;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\TalkSession;
use OCP\IAvatar;
use OCP\IAvatarManager;

class Util {

	/** @var string|null */
	protected $userId;

	/** @var TalkSession */
	protected $session;

	/** @var IAvatarManager */
	private $avatarManager;

	/** @var Manager */
	private $manager;

	/** @var ParticipantService */
	private $participantService;

	/**
	 * @param string|null $userId
	 * @param TalkSession $session
	 * @param IAvatarManager $avatarManager
	 * @param Manager $manager
	 * @param ParticipantService $participantService
	 */
	public function __construct(
			?string $userId,
			TalkSession $session,
			IAvatarManager $avatarManager,
			Manager $manager,
			ParticipantService $participantService) {
		$this->userId = $userId;
		$this->session = $session;
		$this->avatarManager = $avatarManager;
		$this->manager = $manager;
		$this->participantService = $participantService;
	}

	/**
	 * @param Room $room
	 * @return Participant
	 * @throws ParticipantNotFoundException
	 */
	public function getCurrentParticipant(Room $room): Participant {
		$participant = null;
		try {
			$participant = $room->getParticipant($this->userId);
		} catch (ParticipantNotFoundException $e) {
			$participant = $room->getParticipantBySession($this->session->getSessionForRoom($room->getToken()));
		}

		return $participant;
	}

	/**
	 * @param Room $room
	 * @return bool
	 */
	public function isRoomListableByUser(Room $room): bool {
		return $this->manager->isRoomListableByUser($room, $this->userId);
	}

	/**
	 * @param Room $room
	 * @return IAvatar
	 * @throws \InvalidArgumentException if the given room is not a one-to-one
	 *         room, the current participant is not a member of the room or
	 *         there is no other participant in the room
	 */
	public function getUserAvatarForOtherParticipant(Room $room): IAvatar {
		if ($room->getType() !== Room::ONE_TO_ONE_CALL) {
			throw new \InvalidArgumentException('Not a one-to-one room');
		}

		$userIds = $this->participantService->getParticipantUserIds($room);
		if (array_search($this->userId, $userIds) === false) {
			throw new \InvalidArgumentException('Current participant is not a member of the room');
		}
		if (count($userIds) < 2) {
			throw new \InvalidArgumentException('No other participant in the room');
		}

		$otherParticipantUserId = $userIds[0];
		if ($otherParticipantUserId === $this->userId) {
			$otherParticipantUserId = $userIds[1];
		}

		return $this->avatarManager->getAvatar($otherParticipantUserId);
	}
}
