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

namespace OCA\Talk;

use OC\HintException;
use OC\User\NoUserException;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IUser;
use OCP\Util;

trait TInitialState {

	/** @var Config */
	protected $talkConfig;
	/** @var IConfig */
	protected $serverConfig;
	/** @var IInitialState */
	protected $initialState;
	/** @var ICacheFactory */
	protected $memcacheFactory;

	protected function publishInitialStateShared(): void {
		// Needed to enable the screensharing extension in Chromium < 72.
		Util::addHeader('meta', ['id' => 'app', 'class' => 'nc-enable-screensharing-extension']);

		$this->initialState->provideInitialState(
			'prefer_h264',
			$this->serverConfig->getAppValue('spreed', 'prefer_h264', 'no') === 'yes'
		);

		$signalingMode = $this->talkConfig->getSignalingMode();
		if ($signalingMode === Config::SIGNALING_CLUSTER_CONVERSATION
			&& !$this->memcacheFactory->isAvailable()
			&& $this->serverConfig->getAppValue('spreed', 'signaling_dev', 'no') === 'no') {
			throw new HintException(
				'High Performance Back-end clustering is only supported with a distributed cache!'
			);
		}

		$this->initialState->provideInitialState(
			'signaling_mode',
			$this->talkConfig->getSignalingMode()
		);

		$this->initialState->provideInitialState(
			'sip_dialin_info',
			$this->talkConfig->getDialInInfo()
		);

		$this->initialState->provideInitialState(
			'ask_guest_username',
			(int) $this->serverConfig->getAppValue('spreed', 'ask_guest_username', '0')
		);
	}

	protected function publishInitialStateForUser(IUser $user, IRootFolder $rootFolder, IAppManager $appManager): void {
		$this->publishInitialStateShared();

		$this->initialState->provideInitialState(
			'start_conversations',
			!$this->talkConfig->isNotAllowedToCreateConversations($user)
		);

		$this->initialState->provideInitialState(
			'circles_enabled',
			$appManager->isEnabledForUser('circles', $user)
		);

		$this->initialState->provideInitialState(
			'guests_accounts_enabled',
			$appManager->isEnabledForUser('guests', $user)
		);

		$this->initialState->provideInitialState(
			'read_status_privacy',
			$this->talkConfig->getUserReadPrivacy($user->getUID())
		);

		$this->initialState->provideInitialState(
			'play_sounds',
			$this->serverConfig->getUserValue($user->getUID(), 'spreed', 'play_sounds', 'yes') === 'yes'
		);

		$attachmentFolder = $this->talkConfig->getAttachmentFolder($user->getUID());
		$freeSpace = 0;

		if ($attachmentFolder) {
			try {
				$userFolder = $rootFolder->getUserFolder($user->getUID());

				try {
					if (!$userFolder->nodeExists($attachmentFolder)) {
						$userFolder->newFolder($attachmentFolder);
					}

					$freeSpace = $userFolder->get($attachmentFolder)->getFreeSpace();
				} catch (NotPermittedException $e) {
					$attachmentFolder = '/';
					$this->serverConfig->setUserValue($user->getUID(), 'spreed', 'attachment_folder', '/');
					$freeSpace = $userFolder->getFreeSpace();
				}
			} catch (NoUserException $e) {
			}
		}

		$this->initialState->provideInitialState(
			'attachment_folder',
			$attachmentFolder
		);

		$this->initialState->provideInitialState(
			'attachment_folder_free_space',
			$freeSpace
		);

		$this->initialState->provideInitialState(
			'enable_matterbridge',
			$this->serverConfig->getAppValue('spreed', 'enable_matterbridge', '0') === '1'
		);
	}

	protected function publishInitialStateForGuest(): void {
		$this->publishInitialStateShared();

		$this->initialState->provideInitialState(
			'start_conversations',
			false
		);

		$this->initialState->provideInitialState(
			'circles_enabled',
			false
		);

		$this->initialState->provideInitialState(
			'read_status_privacy',
			Participant::PRIVACY_PUBLIC
		);

		$this->initialState->provideInitialState(
			'attachment_folder',
			''
		);

		$this->initialState->provideInitialState(
			'attachment_folder_free_space',
			''
		);

		$this->initialState->provideInitialState(
			'enable_matterbridge',
			false
		);

		$this->initialState->provideInitialState(
			'play_sounds',
			false
		);
	}
}
