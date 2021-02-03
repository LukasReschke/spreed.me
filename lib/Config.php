<?php

declare(strict_types=1);
/**
 * @author Joachim Bauch <mail@joachim-bauch.de>
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

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Security\ISecureRandom;

class Config {
	public const SIGNALING_INTERNAL = 'internal';
	public const SIGNALING_EXTERNAL = 'external';
	public const SIGNALING_CLUSTER_CONVERSATION = 'conversation_cluster';

	/** @var IConfig */
	protected $config;
	/** @var ITimeFactory */
	protected $timeFactory;
	/** @var IGroupManager */
	private $groupManager;
	/** @var ISecureRandom */
	private $secureRandom;

	/** @var array */
	protected $canEnableSIP = [];

	public function __construct(IConfig $config,
								ISecureRandom $secureRandom,
								IGroupManager $groupManager,
								ITimeFactory $timeFactory) {
		$this->config = $config;
		$this->secureRandom = $secureRandom;
		$this->groupManager = $groupManager;
		$this->timeFactory = $timeFactory;
	}

	/**
	 * @return string[]
	 */
	public function getAllowedTalkGroupIds(): array {
		$groups = $this->config->getAppValue('spreed', 'allowed_groups', '[]');
		$groups = json_decode($groups, true);
		return \is_array($groups) ? $groups : [];
	}

	public function getUserReadPrivacy(string $userId): int {
		return (int) $this->config->getUserValue(
			$userId,
			'spreed', 'read_status_privacy',
			(string) Participant::PRIVACY_PUBLIC);
	}

	/**
	 * @return string[]
	 */
	public function getSIPGroups(): array {
		$groups = $this->config->getAppValue('spreed', 'sip_bridge_groups', '[]');
		$groups = json_decode($groups, true);
		return \is_array($groups) ? $groups : [];
	}

	public function isSIPConfigured(): bool {
		return $this->getSIPSharedSecret() !== ''
			&& $this->getDialInInfo() !== '';
	}

	public function getDialInInfo(): string {
		return $this->config->getAppValue('spreed', 'sip_bridge_dialin_info');
	}

	public function getSIPSharedSecret(): string {
		return $this->config->getAppValue('spreed', 'sip_bridge_shared_secret');
	}

	public function canUserEnableSIP(IUser $user): bool {
		if (isset($this->canEnableSIP[$user->getUID()])) {
			return $this->canEnableSIP[$user->getUID()];
		}

		$this->canEnableSIP[$user->getUID()] = false;

		$allowedGroups = $this->getSIPGroups();
		if (empty($allowedGroups)) {
			$this->canEnableSIP[$user->getUID()] = true;
		} else {
			$userGroups = $this->groupManager->getUserGroupIds($user);
			$this->canEnableSIP[$user->getUID()] = !empty(array_intersect($allowedGroups, $userGroups));
		}

		return $this->canEnableSIP[$user->getUID()];
	}

	public function isDisabledForUser(IUser $user): bool {
		$allowedGroups = $this->getAllowedTalkGroupIds();
		if (empty($allowedGroups)) {
			return false;
		}

		$userGroups = $this->groupManager->getUserGroupIds($user);
		return empty(array_intersect($allowedGroups, $userGroups));
	}

	/**
	 * @return string[]
	 */
	public function getAllowedConversationsGroupIds(): array {
		$groups = $this->config->getAppValue('spreed', 'start_conversations', '[]');
		$groups = json_decode($groups, true);
		return \is_array($groups) ? $groups : [];
	}

	public function isNotAllowedToCreateConversations(IUser $user): bool {
		$allowedGroups = $this->getAllowedConversationsGroupIds();
		if (empty($allowedGroups)) {
			return false;
		}

		$userGroups = $this->groupManager->getUserGroupIds($user);
		return empty(array_intersect($allowedGroups, $userGroups));
	}

	public function getAttachmentFolder(string $userId): string {
		return $this->config->getUserValue($userId, 'spreed', 'attachment_folder', '/Talk');
	}

	/**
	 * @return string[]
	 */
	public function getAllServerUrlsForCSP(): array {
		$urls = [];

		foreach ($this->getStunServers() as $server) {
			if (substr(strtolower($server), 0, 5) === 'stun:') {
				$urls[] = substr($server, 5);
			} elseif (substr(strtolower($server), 0, 6) === 'stuns:') {
				$urls[] = substr($server, 6);
			} else {
				$urls[] = $server;
			}
		}

		foreach ($this->getTurnServers() as $server) {
			if (substr(strtolower($server['server']), 0, 5) === 'turn:') {
				$urls[] = substr($server['server'], 5);
			} elseif (substr(strtolower($server['server']), 0, 6) === 'turns:') {
				$urls[] = substr($server['server'], 6);
			} else {
				$urls[] = $server['server'];
			}
		}

		foreach ($this->getSignalingServers() as $server) {
			$urls[] = $this->getWebSocketDomainForSignalingServer($server['server']);
		}

		return $urls;
	}

	protected function getWebSocketDomainForSignalingServer(string $url): string {
		$url .= '/';
		if (strpos($url, 'https://') === 0) {
			return 'wss://' . substr($url, 8, strpos($url, '/', 9) - 8);
		}

		if (strpos($url, 'http://') === 0) {
			return 'ws://' . substr($url, 7, strpos($url, '/', 8) - 7);
		}

		if (strpos($url, 'wss://') === 0) {
			return substr($url, 0, strpos($url, '/', 7));
		}

		if (strpos($url, 'ws://') === 0) {
			return substr($url, 0, strpos($url, '/', 6));
		}

		$protocol = strpos($url, '://');
		if ($protocol !== false) {
			return substr($url, $protocol + 3, strpos($url, '/', $protocol + 3) - $protocol - 3);
		}

		return substr($url, 0, strpos($url, '/'));
	}

	/**
	 * @return string[]
	 */
	public function getStunServers(): array {
		$config = $this->config->getAppValue('spreed', 'stun_servers', json_encode(['stun.nextcloud.com:443']));
		$servers = json_decode($config, true);

		if (!is_array($servers) || empty($servers)) {
			$servers = ['stun.nextcloud.com:443'];
		}

		if (!$this->config->getSystemValueBool('has_internet_connection', true)) {
			$servers = array_filter($servers, static function ($server) {
				return $server !== 'stun.nextcloud.com:443';
			});
		}

		return $servers;
	}

	/**
	 * Generates a username and password for the TURN server
	 *
	 * @return array
	 */
	public function getTurnServers(): array {
		$config = $this->config->getAppValue('spreed', 'turn_servers');
		$servers = json_decode($config, true);

		if ($servers === null || empty($servers) || !is_array($servers)) {
			return [];
		}

		return $servers;
	}

	/**
	 * Generates a username and password for the TURN server
	 *
	 * @return array
	 */
	public function getTurnSettings(): array {
		$servers = $this->getTurnServers();

		if (empty($servers)) {
			return [
				[
					'server' => '',
					'username' => '',
					'password' => '',
					'protocols' => '',
				],
			];
		}

		// Credentials are valid for 24h
		// FIXME add the TTL to the response and properly reconnect then
		$timestamp = $this->timeFactory->getTime() + 86400;
		$rnd = $this->secureRandom->generate(16);
		$username = $timestamp . ':' . $rnd;

		$settings = [];
		foreach ($servers as $server) {
			$settings[] = [
				'server' => $server['server'],
				'username' => $username,
				'password' => base64_encode(hash_hmac('sha1', $username, $server['secret'], true)),
				'protocols' => $server['protocols'],
			];
		}
		return $settings;
	}

	public function getSignalingMode(bool $cleanExternalSignaling = true): string {
		$validModes = [
			self::SIGNALING_INTERNAL,
			self::SIGNALING_EXTERNAL,
			self::SIGNALING_CLUSTER_CONVERSATION,
		];

		$mode = $this->config->getAppValue('spreed', 'signaling_mode', null);
		if ($mode === self::SIGNALING_INTERNAL) {
			return self::SIGNALING_INTERNAL;
		}

		$numSignalingServers = count($this->getSignalingServers());
		if ($numSignalingServers === 0) {
			return self::SIGNALING_INTERNAL;
		}
		if ($numSignalingServers === 1
			&& $cleanExternalSignaling
			&& $this->config->getAppValue('spreed', 'signaling_dev', 'no') === 'no') {
			return self::SIGNALING_EXTERNAL;
		}

		return \in_array($mode, $validModes, true) ? $mode : self::SIGNALING_EXTERNAL;
	}

	/**
	 * Returns list of signaling servers. Each entry contains the URL of the
	 * server and a flag whether the certificate should be verified.
	 *
	 * @return array
	 */
	public function getSignalingServers(): array {
		$config = $this->config->getAppValue('spreed', 'signaling_servers');
		$signaling = json_decode($config, true);
		if (!is_array($signaling) || !isset($signaling['servers'])) {
			return [];
		}

		return $signaling['servers'];
	}

	/**
	 * @return string
	 */
	public function getSignalingSecret(): string {
		$config = $this->config->getAppValue('spreed', 'signaling_servers');
		$signaling = json_decode($config, true);

		if (!is_array($signaling)) {
			return '';
		}

		return $signaling['secret'];
	}

	public function getHideSignalingWarning(): bool {
		return $this->config->getAppValue('spreed', 'hide_signaling_warning', 'no') === 'yes';
	}

	/**
	 * @param string $userId
	 * @return string
	 */
	public function getSignalingTicket(?string $userId): string {
		if (empty($userId)) {
			$secret = $this->config->getAppValue('spreed', 'signaling_ticket_secret');
		} else {
			$secret = $this->config->getUserValue($userId, 'spreed', 'signaling_ticket_secret');
		}
		if (empty($secret)) {
			// Create secret lazily on first access.
			// TODO(fancycode): Is there a possibility for a race condition?
			$secret = $this->secureRandom->generate(255);
			if (empty($userId)) {
				$this->config->setAppValue('spreed', 'signaling_ticket_secret', $secret);
			} else {
				$this->config->setUserValue($userId, 'spreed', 'signaling_ticket_secret', $secret);
			}
		}

		// Format is "random:timestamp:userid:checksum" and "checksum" is the
		// SHA256-HMAC of "random:timestamp:userid" with the per-user secret.
		$random = $this->secureRandom->generate(16);
		$timestamp = $this->timeFactory->getTime();
		$data = $random . ':' . $timestamp . ':' . $userId;
		$hash = hash_hmac('sha256', $data, $secret);
		return $data . ':' . $hash;
	}

	/**
	 * @param string $userId
	 * @param string $ticket
	 * @return bool
	 */
	public function validateSignalingTicket(?string $userId, string $ticket): bool {
		if (empty($userId)) {
			$secret = $this->config->getAppValue('spreed', 'signaling_ticket_secret');
		} else {
			$secret = $this->config->getUserValue($userId, 'spreed', 'signaling_ticket_secret');
		}
		if (empty($secret)) {
			return false;
		}

		$lastColon = strrpos($ticket, ':');
		if ($lastColon === false) {
			// Immediately reject invalid formats.
			return false;
		}

		// TODO(fancycode): Should we reject tickets that are too old?
		$data = substr($ticket, 0, $lastColon);
		$hash = hash_hmac('sha256', $data, $secret);
		return hash_equals($hash, substr($ticket, $lastColon + 1));
	}
}
