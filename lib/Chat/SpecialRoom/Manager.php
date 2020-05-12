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

namespace OCA\Talk\Chat\SpecialRoom;


use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Room;
use OCA\Talk\Manager as RoomManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;


class Manager {

	/** @var IConfig */
	protected $config;
	/** @var RoomManager */
	protected $roomManager;
	/** @var ChatManager */
	protected $chatManager;
	/** @var ITimeFactory */
	protected $timeFactory;
	/** @var IL10N */
	protected $l;

	public function __construct(IConfig $config,
								RoomManager $roomManager,
								ChatManager $chatManager,
								ITimeFactory $timeFactory,
								IL10N $l) {
		$this->config = $config;
		$this->roomManager = $roomManager;
		$this->chatManager = $chatManager;
		$this->timeFactory = $timeFactory;
		$this->l = $l;
	}

	public function getNotesForUser(string $userId): int {
		return (int) $this->config->getUserValue($userId, 'spreed', 'notes', '2');
	}

	public function getChangelogForUser(string $userId): int {
		return (int) $this->config->getUserValue($userId, 'spreed', 'changelog', '0');
	}

	public function userHasNewChangelog(string $userId): bool {
		return $this->getChangelogForUser($userId) < count($this->getChangelogs());
	}

	public function createNotesIfNeeded(string $userId): void {
		if ($this->getNotesForUser($userId) === 2) {
			$room = $this->roomManager->getSpecialRoom($userId, Room::NOTES_CONVERSATION);
			$this->setNotesConversationAsFavorite($room, $userId);
			$this->addNotesWelcomeMessages($room, $userId);
		}
	}

	public function setNotesConversationAsFavorite(Room $room, string $userId): void {
		try {
			$participant = $room->getParticipant($userId);
			$participant->setFavorite(true);
		} catch (ParticipantNotFoundException $e) {
			// do nothing
		}
	}

	public function addNotesWelcomeMessages(Room $room, string $userId): void {
		$notesWelcomeMessages = $this->getNotesWelcomeMessages();
		foreach ($notesWelcomeMessages as $key => $welcomeMessage) {
			if ($welcomeMessage === '') {
				continue;
			}
			$this->chatManager->addSpecialMessage($room, 'notes', $welcomeMessage);
		}

		$this->config->setUserValue($userId, 'spreed', 'notes', 1);
	}

	public function getNotesWelcomeMessages(): array {
		return [
			$this->l->t(
				"Welcome to your notes!"
				. "\nYou can use this conversation to share notes between your different devices."
				. " When you deleted it, you can recreate it via the settings."
			)
		];
	}

	public function updateChangelog(string $userId): void {
		$room = $this->roomManager->getSpecialRoom($userId, Room::CHANGELOG_CONVERSATION);

		$logs = $this->getChangelogs();
		$hasReceivedLog = $this->getChangelogForUser($userId);

		foreach ($logs as $key => $changelog) {
			if ($key < $hasReceivedLog || $changelog === '') {
				continue;
			}
			$this->chatManager->addSpecialMessage($room, 'changelog', $changelog);
		}

		$this->config->setUserValue($userId, 'spreed', 'changelog', count($this->getChangelogs()));
	}

	public function getChangelogs(): array {
		return [
			$this->l->t(
				"Welcome to Nextcloud Talk!\n"
				. 'In this conversation you will be informed about new features available in Nextcloud Talk.'
			),
			$this->l->t('New in Talk 6'),
			$this->l->t('- Microsoft Edge and Safari can now be used to participate in audio and video calls'),
			$this->l->t('- One-to-one conversations are now persistent and can not be turned into group conversations by accident anymore. Also when one of the participants leaves the conversation, the conversation is not automatically deleted anymore. Only if both participants leave, the conversation is deleted from the server'),
			$this->l->t('- You can now notify all participants by posting "@all" into the chat'),
			$this->l->t('- With the "arrow-up" key you can repost your last message'),
			$this->l->t('- Talk can now have commands, send "/help" as a chat message to see if your administrator configured some'),
			$this->l->t('- With projects you can create quick links between conversations, files and other items'),
			$this->l->t('New in Talk 7'),
			$this->l->t('- You can now mention guests in the chat'),
			$this->l->t('- Conversations can now have a lobby. This will allow moderators to join the chat and call already to prepare the meeting, while users and guests have to wait'),
			$this->l->t('New in Talk 8'),
			$this->l->t('- You can now directly reply to messages giving the other users more context what your message is about'),
			$this->l->t('- Searching for conversations and participants will now also filter your existing conversations, making it much easier to find previous conversations'),
			$this->l->t('- You can now add custom user groups to conversations when the circles app is installed'),
		];
	}
}
