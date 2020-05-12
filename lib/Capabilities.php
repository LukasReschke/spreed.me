<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2018, Joas Schilling <coding@schilljs.com>
 *
 * @author Joas Schilling <coding@schilljs.com>
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

use OCA\Talk\Chat\ChatManager;
use OCP\Capabilities\IPublicCapability;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;

class Capabilities implements IPublicCapability {

	/** @var Config */
	protected $talkConfig;
	/** @var IUserSession */
	protected $userSession;

	public function __construct(Config $talkConfig,
								IUserSession $userSession) {
		$this->talkConfig = $talkConfig;
		$this->userSession = $userSession;
	}

	public function getCapabilities(): array {
		$user = $this->userSession->getUser();
		if ($user instanceof IUser && $this->talkConfig->isDisabledForUser($user)) {
			return [];
		}

		$capabilities = [
			'spreed' => [
				'features' => [
					'audio',
					'video',
					'chat-v2',
					'conversation-v2',
					'guest-signaling',
					'empty-group-room',
					'guest-display-names',
					'multi-room-users',
					'favorites',
					'last-room-activity',
					'no-ping',
					'system-messages',
					'mention-flag',
					'in-call-flags',
					'notification-levels',
					'invite-groups-and-mails',
					'locked-one-to-one-rooms',
					'read-only-rooms',
					'chat-read-marker',
					'webinary-lobby',
					'start-call-flag',
					'chat-replies',
					'circles-support',
					'force-mute',
					'chat-reference-id',
				],
				'config' => [
					'attachments' => [
						'allowed' => false,
					],
					'chat' => [
						'max-length' => ChatManager::MAX_CHAT_LENGTH,
					],
					'conversations' => [
						'can-create' => false
					],
				],
			],
		];

		if ($user instanceof IUser) {
			$capabilities['spreed']['features'][] = 'notes';

			$capabilities['spreed']['config']['attachments'] = [
				'allowed' => true,
				'folder' => $this->talkConfig->getAttachmentFolder($user->getUID()),
			];

			$capabilities['spreed']['config']['conversations']['can-create'] = !$this->talkConfig->isNotAllowedToCreateConversations($user);
		}

		return $capabilities;
	}
}
