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
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IInitialStateService;
use OCP\IUser;
use OCP\Util;

trait TInitialState {

	/** @var Config */
	protected $talkConfig;
	/** @var IConfig */
	protected $serverConfig;
	/** @var IInitialStateService */
	protected $initialStateService;
	/** @var ICacheFactory */
	protected $memcacheFactory;

	protected function publishInitialStateShared(): void {
		// Needed to enable the screensharing extension in Chromium < 72.
		Util::addHeader('meta', ['id' => 'app', 'class' => 'nc-enable-screensharing-extension']);

		$this->initialStateService->provideInitialState(
			'talk', 'prefer_h264',
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

		$this->initialStateService->provideInitialState(
			'talk', 'signaling_mode',
			$this->talkConfig->getSignalingMode()
		);
	}

	protected function publishInitialStateForUser(IUser $user, IRootFolder $rootFolder, IAppManager $appManager): void {
		$this->publishInitialStateShared();

		$this->initialStateService->provideInitialState(
			'talk', 'start_conversations',
			!$this->talkConfig->isNotAllowedToCreateConversations($user)
		);

		$this->initialStateService->provideInitialState(
			'talk', 'circles_enabled',
			$appManager->isEnabledForUser('circles', $user)
		);

		$this->initialStateService->provideInitialState(
			'talk', 'send_message_key',
			$this->serverConfig->getUserValue(
				$user->getUID(), 'spreed', 'send_message_key', Participant::SEND_MESSAGE_KEY_ENTER
			)
		);

		$attachmentFolder = $this->talkConfig->getAttachmentFolder($user->getUID());

		if ($attachmentFolder) {
			try {
				$userFolder = $rootFolder->getUserFolder($user->getUID());

				if (!$userFolder->nodeExists($attachmentFolder)) {
					$userFolder->newFolder($attachmentFolder);
				}
			} catch (NotPermittedException $e) {
				$attachmentFolder = '/';
				$this->serverConfig->setUserValue($user->getUID(), 'spreed', 'attachment_folder', '/');
			} catch (NoUserException $e) {
			}
		}

		$this->initialStateService->provideInitialState(
			'talk', 'attachment_folder',
			$attachmentFolder
		);

		$this->initialStateService->provideInitialState(
			'talk', 'enable_matterbridge',
			$this->serverConfig->getAppValue('spreed', 'enable_matterbridge', '0') === '1'
		);
	}

	protected function publishInitialStateForGuest(): void {
		$this->publishInitialStateShared();

		$this->initialStateService->provideInitialState(
			'talk', 'start_conversations',
			false
		);

		$this->initialStateService->provideInitialState(
			'talk', 'circles_enabled',
			false
		);

		$this->initialStateService->provideInitialState(
			'talk', 'attachment_folder',
			''
		);

		$this->initialStateService->provideInitialState(
			'talk', 'send_message_key', Participant::SEND_MESSAGE_KEY_ENTER
		);

		$this->initialStateService->provideInitialState(
			'talk', 'enable_matterbridge',
			false
		);
	}
}
