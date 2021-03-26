<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020 Daniel Rudolf <nextcloud.com@daniel-rudolf.de>
 *
 * @author Daniel Rudolf <nextcloud.com@daniel-rudolf.de>
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

namespace OCA\Talk\Command\Room;

use InvalidArgumentException;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\RoomService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Stecman\Component\Symfony\Console\BashCompletion\CompletionContext;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputDefinition;

trait TRoomCommand {
	/** @var Manager */
	protected $manager;

	/** @var RoomService */
	protected $roomService;

	/** @var ParticipantService */
	protected $participantService;

	/** @var IUserManager */
	protected $userManager;

	/** @var IGroupManager */
	protected $groupManager;

	public function __construct(Manager $manager,
								RoomService $roomService,
								ParticipantService $participantService,
								IUserManager $userManager,
								IGroupManager $groupManager) {
		parent::__construct();

		$this->manager = $manager;
		$this->roomService = $roomService;
		$this->participantService = $participantService;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
	}

	/**
	 * @param Room   $room
	 * @param string $name
	 *
	 * @throws InvalidArgumentException
	 */
	protected function setRoomName(Room $room, string $name): void {
		$name = trim($name);
		if ($name === $room->getName()) {
			return;
		}

		if (!$this->validateRoomName($name)) {
			throw new InvalidArgumentException('Invalid room name.');
		}

		if (!$room->setName($name)) {
			throw new InvalidArgumentException('Unable to change room name.');
		}
	}

	/**
	 * @param string $name
	 *
	 * @return bool
	 *
	 * @throws InvalidArgumentException
	 */
	protected function validateRoomName(string $name): bool {
		$name = trim($name);
		return (($name !== '') && !isset($name[255]));
	}

	/**
	 * @param Room   $room
	 * @param string $description
	 *
	 * @throws InvalidArgumentException
	 */
	protected function setRoomDescription(Room $room, string $description): void {
		try {
			$room->setDescription($description);
		} catch (\LengthException $e) {
			throw new InvalidArgumentException('Invalid room description.');
		}
	}

	/**
	 * @param Room $room
	 * @param bool $public
	 *
	 * @throws InvalidArgumentException
	 */
	protected function setRoomPublic(Room $room, bool $public): void {
		if ($public === ($room->getType() === Room::PUBLIC_CALL)) {
			return;
		}

		if (!$room->setType($public ? Room::PUBLIC_CALL : Room::GROUP_CALL)) {
			throw new InvalidArgumentException('Unable to change room type.');
		}
	}

	/**
	 * @param Room $room
	 * @param int $readOnly
	 *
	 * @throws InvalidArgumentException
	 */
	protected function setRoomReadOnly(Room $room, int $readOnly): void {
		if ($readOnly === ($room->getReadOnly() === Room::READ_ONLY)) {
			return;
		}

		if (!$room->setReadOnly($readOnly ? Room::READ_ONLY : Room::READ_WRITE)) {
			throw new InvalidArgumentException('Unable to change room state.');
		}
	}

	/**
	 * @param Room $room
	 * @param int $listable
	 *
	 * @throws InvalidArgumentException
	 */
	protected function setRoomListable(Room $room, int $listable): void {
		if ($room->getListable() === $listable) {
			return;
		}

		if (!$room->setListable($listable)) {
			throw new InvalidArgumentException('Unable to change room state.');
		}
	}

	/**
	 * @param Room   $room
	 * @param string $password
	 *
	 * @throws InvalidArgumentException
	 */
	protected function setRoomPassword(Room $room, string $password): void {
		if ($room->hasPassword() ? $room->verifyPassword($password)['result'] : ($password === '')) {
			return;
		}

		if (($password !== '') && ($room->getType() !== Room::PUBLIC_CALL)) {
			throw new InvalidArgumentException('Unable to add password protection to private room.');
		}

		if (!$room->setPassword($password)) {
			throw new InvalidArgumentException('Unable to change room password.');
		}
	}

	/**
	 * @param Room   $room
	 * @param string $userId
	 *
	 * @throws InvalidArgumentException
	 */
	protected function setRoomOwner(Room $room, string $userId): void {
		try {
			$participant = $room->getParticipant($userId, false);
		} catch (ParticipantNotFoundException $e) {
			throw new InvalidArgumentException(sprintf("User '%s' is no participant.", $userId));
		}

		$this->unsetRoomOwner($room);

		$this->participantService->updateParticipantType($room, $participant, Participant::OWNER);
	}

	/**
	 * @param Room $room
	 *
	 * @throws InvalidArgumentException
	 */
	protected function unsetRoomOwner(Room $room): void {
		$participants = $this->participantService->getParticipantsForRoom($room);
		foreach ($participants as $participant) {
			if ($participant->getAttendee()->getParticipantType() === Participant::OWNER) {
				$this->participantService->updateParticipantType($room, $participant, Participant::USER);
			}
		}
	}

	/**
	 * @param Room     $room
	 * @param string[] $groupIds
	 *
	 * @throws InvalidArgumentException
	 */
	protected function addRoomParticipantsByGroup(Room $room, array $groupIds): void {
		if (!$groupIds) {
			return;
		}

		$users = [];
		foreach ($groupIds as $groupId) {
			$group = $this->groupManager->get($groupId);
			if ($group === null) {
				throw new InvalidArgumentException(sprintf("Group '%s' not found.", $groupId));
			}

			$groupUsers = array_map(function (IUser $user) {
				return $user->getUID();
			}, $group->getUsers());

			$users = array_merge($users, array_values($groupUsers));
		}

		$this->addRoomParticipants($room, array_unique($users));
	}

	/**
	 * @param Room     $room
	 * @param string[] $userIds
	 *
	 * @throws InvalidArgumentException
	 */
	protected function addRoomParticipants(Room $room, array $userIds): void {
		if (!$userIds) {
			return;
		}

		$participants = [];
		foreach ($userIds as $userId) {
			$user = $this->userManager->get($userId);
			if ($user === null) {
				throw new InvalidArgumentException(sprintf("User '%s' not found.", $userId));
			}

			if (isset($participants[$user->getUID()])) {
				// nothing to do, user is going to be a participant already
				continue;
			}

			try {
				$room->getParticipant($user->getUID(), false);

				// nothing to do, user is a participant already
				continue;
			} catch (ParticipantNotFoundException $e) {
				// we expect the user not to be a participant yet
			}

			$participants[] = [
				'actorType' => Attendee::ACTOR_USERS,
				'actorId' => $user->getUID(),
				'displayName' => $user->getDisplayName(),
			];
		}

		$this->participantService->addUsers($room, $participants);
	}

	/**
	 * @param Room     $room
	 * @param string[] $userIds
	 *
	 * @throws InvalidArgumentException
	 */
	protected function removeRoomParticipants(Room $room, array $userIds): void {
		$users = [];
		foreach ($userIds as $userId) {
			try {
				$room->getParticipant($userId, false);
			} catch (ParticipantNotFoundException $e) {
				throw new InvalidArgumentException(sprintf("User '%s' is no participant.", $userId));
			}

			$users[] = $this->userManager->get($userId);
		}

		foreach ($users as $user) {
			$this->participantService->removeUser($room, $user, Room::PARTICIPANT_REMOVED);
		}
	}

	/**
	 * @param Room     $room
	 * @param string[] $userIds
	 *
	 * @throws InvalidArgumentException
	 */
	protected function addRoomModerators(Room $room, array $userIds): void {
		$participants = [];
		foreach ($userIds as $userId) {
			try {
				$participant = $room->getParticipant($userId, false);
			} catch (ParticipantNotFoundException $e) {
				throw new InvalidArgumentException(sprintf("User '%s' is no participant.", $userId));
			}

			if ($participant->getAttendee()->getParticipantType() !== Participant::OWNER) {
				$participants[] = $participant;
			}
		}

		foreach ($participants as $participant) {
			$this->participantService->updateParticipantType($room, $participant, Participant::MODERATOR);
		}
	}

	/**
	 * @param Room     $room
	 * @param string[] $userIds
	 *
	 * @throws InvalidArgumentException
	 */
	protected function removeRoomModerators(Room $room, array $userIds): void {
		$participants = [];
		foreach ($userIds as $userId) {
			try {
				$participant = $room->getParticipant($userId, false);
			} catch (ParticipantNotFoundException $e) {
				throw new InvalidArgumentException(sprintf("User '%s' is no participant.", $userId));
			}

			if ($participant->getAttendee()->getParticipantType() === Participant::MODERATOR) {
				$participants[] = $participant;
			}
		}

		foreach ($participants as $participant) {
			$this->participantService->updateParticipantType($room, $participant, Participant::USER);
		}
	}

	protected function completeTokenValues(CompletionContext $context): array {
		return array_map(function (Room $room) {
			return $room->getToken();
		}, $this->manager->searchRoomsByToken($context->getCurrentWord()));
	}

	protected function completeUserValues(CompletionContext $context): array {
		return array_map(function (IUser $user) {
			return $user->getUID();
		}, $this->userManager->search($context->getCurrentWord()));
	}

	protected function completeGroupValues(CompletionContext $context): array {
		return array_map(function (IGroup $group) {
			return $group->getGID();
		}, $this->groupManager->search($context->getCurrentWord()));
	}

	protected function completeParticipantValues(CompletionContext $context): array {
		$definition = new InputDefinition();

		if ($this->getApplication() !== null) {
			$definition->addArguments($this->getApplication()->getDefinition()->getArguments());
			$definition->addOptions($this->getApplication()->getDefinition()->getOptions());
		}

		$definition->addArguments($this->getDefinition()->getArguments());
		$definition->addOptions($this->getDefinition()->getOptions());

		$input = new ArgvInput($context->getWords(), $definition);
		if ($input->hasArgument('token')) {
			$token = $input->getArgument('token');
		} elseif ($input->hasOption('token')) {
			$token = $input->getOption('token');
		} else {
			return [];
		}

		try {
			$room = $this->manager->getRoomByToken($token);
		} catch (RoomNotFoundException $e) {
			return [];
		}

		return array_filter($this->participantService->getParticipantUserIds($room), static function ($userId) use ($context) {
			return stripos($userId, $context->getCurrentWord()) !== false;
		});
	}
}
