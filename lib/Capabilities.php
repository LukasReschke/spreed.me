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

	/** @var IConfig */
	protected $serverConfig;
	/** @var Config */
	protected $talkConfig;
	/** @var IUserSession */
	protected $userSession;

	public function __construct(IConfig $serverConfig,
								Config $talkConfig,
								IUserSession $userSession) {
		$this->serverConfig = $serverConfig;
		$this->talkConfig = $talkConfig;
		$this->userSession = $userSession;
	}

	public function getCapabilities(): array {
		$user = $this->userSession->getUser();
		if ($user instanceof IUser && $this->talkConfig->isDisabledForUser($user)) {
			return [];
		}

		$capabilities = [
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
				'listable-rooms',
				'chat-read-marker',
				'webinary-lobby',
				'start-call-flag',
				'chat-replies',
				'circles-support',
				'force-mute',
				'conversation-v3',
				'sip-support',
				'chat-read-status',
				'phonebook-search',
				'raise-hand',
				'room-description',
				'room-avatar',
			],
			'config' => [
				'attachments' => [
					'allowed' => $user instanceof IUser,
				],
				'chat' => [
					'max-length' => ChatManager::MAX_CHAT_LENGTH,
					'read-privacy' => Participant::PRIVACY_PUBLIC,
				],
				'conversations' => [],
				'previews' => [
					'max-gif-size' => (int)$this->serverConfig->getAppValue('spreed', 'max-gif-size', '3145728')
				],
			],
		];

		if ($user instanceof IUser) {
			$capabilities['config']['attachments']['folder'] = $this->talkConfig->getAttachmentFolder($user->getUID());
			$capabilities['config']['chat']['read-privacy'] = $this->talkConfig->getUserReadPrivacy($user->getUID());
		}

		$capabilities['config']['conversations']['can-create'] = $user instanceof IUser && !$this->talkConfig->isNotAllowedToCreateConversations($user);

		if ($this->serverConfig->getAppValue('spreed', 'has_reference_id', 'no') === 'yes') {
			$capabilities['features'][] = 'chat-reference-id';
		}

		return [
			'spreed' => $capabilities,
		];
	}
}
