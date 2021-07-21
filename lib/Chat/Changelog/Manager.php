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

namespace OCA\Talk\Chat\Changelog;

use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Manager as RoomManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;

class Manager {

	/** @var IConfig */
	protected $config;
	/** @var IDBConnection */
	protected $connection;
	/** @var RoomManager */
	protected $roomManager;
	/** @var ChatManager */
	protected $chatManager;
	/** @var ITimeFactory */
	protected $timeFactory;
	/** @var IL10N */
	protected $l;

	public function __construct(IConfig $config,
								IDBConnection $connection,
								RoomManager $roomManager,
								ChatManager $chatManager,
								ITimeFactory $timeFactory,
								IL10N $l) {
		$this->config = $config;
		$this->connection = $connection;
		$this->roomManager = $roomManager;
		$this->chatManager = $chatManager;
		$this->timeFactory = $timeFactory;
		$this->l = $l;
	}

	public function getChangelogForUser(string $userId): int {
		return (int) $this->config->getUserValue($userId, 'spreed', 'changelog', 0);
	}

	public function userHasNewChangelog(string $userId): bool {
		return $this->getChangelogForUser($userId) < count($this->getChangelogs());
	}

	public function updateChangelog(string $userId): void {
		$room = $this->roomManager->getChangelogRoom($userId);

		$logs = $this->getChangelogs();
		$hasReceivedLog = $this->getChangelogForUser($userId);

		foreach ($logs as $key => $changelog) {
			if ($key < $hasReceivedLog || $changelog === '') {
				continue;
			}
			$this->chatManager->addChangelogMessage($room, $changelog);
		}

		$this->config->setUserValue($userId, 'spreed', 'changelog', count($this->getChangelogs()));
	}

	public function getChangelogs(): array {
		return [
			$this->l->t(
				"Welcome to Nextcloud Talk!\n"
				. 'In this conversation you will be informed about new features available in Nextcloud Talk.'
			),
			$this->l->t('New in Talk %s', ['6']),
			$this->l->t('- Microsoft Edge and Safari can now be used to participate in audio and video calls'),
			$this->l->t('- One-to-one conversations are now persistent and cannot be turned into group conversations by accident anymore. Also when one of the participants leaves the conversation, the conversation is not automatically deleted anymore. Only if both participants leave, the conversation is deleted from the server'),
			$this->l->t('- You can now notify all participants by posting "@all" into the chat'),
			$this->l->t('- With the "arrow-up" key you can repost your last message'),
			$this->l->t('- Talk can now have commands, send "/help" as a chat message to see if your administrator configured some'),
			$this->l->t('- With projects you can create quick links between conversations, files and other items'),
			$this->l->t('New in Talk %s', ['7']),
			$this->l->t('- You can now mention guests in the chat'),
			$this->l->t('- Conversations can now have a lobby. This will allow moderators to join the chat and call already to prepare the meeting, while users and guests have to wait'),
			$this->l->t('New in Talk %s', ['8']),
			$this->l->t('- You can now directly reply to messages giving the other users more context what your message is about'),
			$this->l->t('- Searching for conversations and participants will now also filter your existing conversations, making it much easier to find previous conversations'),
			$this->l->t('- You can now add custom user groups to conversations when the circles app is installed'),
			$this->l->t('New in Talk %s', ['9']),
			$this->l->t('- Check out the new grid and call view'),
			$this->l->t('- You can now upload and drag\'n\'drop files directly from your device into the chat'),
			$this->l->t('- Shared files are now opened directly inside the chat view with the viewer apps'),
			$this->l->t('New in Talk %s', ['10']),
			$this->l->t('- You can now search for chats and messages in the unified search in the top bar'),
			$this->l->t('- Spice up your messages with emojis from the emoji picker'),
			$this->l->t('- You can now change your camera and microphone while being in a call'),
			$this->l->t('New in Talk %s', ['11']),
			$this->l->t('- Give your conversations some context with a description and open it up so logged in users can find it and join themselves'),
			$this->l->t('- See a read status and send failed messages again'),
			$this->l->t('- Raise your hand in a call with the R key'),
			$this->l->t('New in Talk %s', ['12']),
			$this->l->t('- Join the same conversation and call from multiple devices'),
			$this->l->t('- Send voice messages, share your location or contact details'),
			$this->l->t('- Add groups to a conversation and new group members will automatically be added as participants'),
		];
	}
}
