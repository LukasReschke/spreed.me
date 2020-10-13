<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2017 Joas Schilling <coding@schilljs.com>
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

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;

class Participant {
	public const OWNER = 1;
	public const MODERATOR = 2;
	public const USER = 3;
	public const GUEST = 4;
	public const USER_SELF_JOINED = 5;
	public const GUEST_MODERATOR = 6;

	public const FLAG_DISCONNECTED = 0;
	public const FLAG_IN_CALL = 1;
	public const FLAG_WITH_AUDIO = 2;
	public const FLAG_WITH_VIDEO = 4;

	public const NOTIFY_DEFAULT = 0;
	public const NOTIFY_ALWAYS = 1;
	public const NOTIFY_MENTION = 2;
	public const NOTIFY_NEVER = 3;

	public const SEND_MESSAGE_KEY_ENTER = 'enter';
	public const SEND_MESSAGE_KEY_SHIFT_ENTER = 'shift_enter';

	/** @var IDBConnection */
	protected $db;
	/** @var IConfig */
	protected $config;
	/** @var Room */
	protected $room;
	/** @var string */
	protected $user;
	/** @var int */
	protected $participantType;
	/** @var int */
	protected $lastPing;
	/** @var string */
	protected $sessionId;
	/** @var int */
	protected $inCall;
	/** @var int */
	protected $notificationLevel;
	/** @var bool */
	private $isFavorite;
	/** @var int */
	private $lastReadMessage;
	/** @var int */
	private $lastMentionMessage;
	/** @var \DateTime|null */
	private $lastJoinedCall;

	public function __construct(IDBConnection $db,
								IConfig $config,
								Room $room,
								string $user,
								int $participantType,
								int $lastPing,
								string $sessionId,
								int $inCall,
								int $notificationLevel,
								bool $isFavorite,
								int $lastReadMessage,
								int $lastMentionMessage,
								\DateTime $lastJoinedCall = null) {
		$this->db = $db;
		$this->config = $config;
		$this->room = $room;
		$this->user = $user;
		$this->participantType = $participantType;
		$this->lastPing = $lastPing;
		$this->sessionId = $sessionId;
		$this->inCall = $inCall;
		$this->notificationLevel = $notificationLevel;
		$this->isFavorite = $isFavorite;
		$this->lastReadMessage = $lastReadMessage;
		$this->lastMentionMessage = $lastMentionMessage;
		$this->lastJoinedCall = $lastJoinedCall;
	}

	public function getUser(): string {
		return $this->user;
	}

	public function getParticipantType(): int {
		return $this->participantType;
	}

	public function isGuest(): bool {
		return \in_array($this->participantType, [self::GUEST, self::GUEST_MODERATOR], true);
	}

	public function hasModeratorPermissions(bool $guestModeratorAllowed = true): bool {
		if (!$guestModeratorAllowed) {
			return \in_array($this->participantType, [self::OWNER, self::MODERATOR], true);
		}

		return \in_array($this->participantType, [self::OWNER, self::MODERATOR, self::GUEST_MODERATOR], true);
	}

	public function getLastPing(): int {
		return $this->lastPing;
	}

	public function getSessionId(): string {
		return $this->sessionId;
	}

	public function getInCallFlags(): int {
		return $this->inCall;
	}

	/**
	 * @return \DateTime|null
	 */
	public function getJoinedCall(): ?\DateTime {
		return $this->lastJoinedCall;
	}

	public function isFavorite(): bool {
		return $this->isFavorite;
	}

	public function setFavorite(bool $favor): bool {
		if (!$this->user) {
			return false;
		}

		$query = $this->db->getQueryBuilder();
		$query->update('talk_participants')
			->set('favorite', $query->createNamedParameter((int) $favor, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('user_id', $query->createNamedParameter($this->user)))
			->andWhere($query->expr()->eq('room_id', $query->createNamedParameter($this->room->getId())));
		$query->execute();

		$this->isFavorite = $favor;
		return true;
	}

	public function getNotificationLevel(): int {
		return $this->notificationLevel;
	}

	public function setNotificationLevel(int $notificationLevel): bool {
		if (!$this->user) {
			return false;
		}

		if (!\in_array($notificationLevel, [
			self::NOTIFY_ALWAYS,
			self::NOTIFY_MENTION,
			self::NOTIFY_NEVER
		], true)) {
			return false;
		}

		$query = $this->db->getQueryBuilder();
		$query->update('talk_participants')
			->set('notification_level', $query->createNamedParameter($notificationLevel, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('user_id', $query->createNamedParameter($this->user)))
			->andWhere($query->expr()->eq('room_id', $query->createNamedParameter($this->room->getId())));
		$query->execute();

		$this->notificationLevel = $notificationLevel;
		return true;
	}

	public function getLastReadMessage(): int {
		return $this->lastReadMessage;
	}

	public function setLastReadMessage(int $messageId): bool {
		if (!$this->user) {
			return false;
		}

		$query = $this->db->getQueryBuilder();
		$query->update('talk_participants')
			->set('last_read_message', $query->createNamedParameter($messageId, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('user_id', $query->createNamedParameter($this->user)))
			->andWhere($query->expr()->eq('room_id', $query->createNamedParameter($this->room->getId())));
		$query->execute();

		$this->lastReadMessage = $messageId;
		return true;
	}

	public function getLastMentionMessage(): int {
		return $this->lastMentionMessage;
	}

	public function setLastMentionMessage(int $messageId): bool {
		if (!$this->user) {
			return false;
		}

		$query = $this->db->getQueryBuilder();
		$query->update('talk_participants')
			->set('last_mention_message', $query->createNamedParameter($messageId, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('user_id', $query->createNamedParameter($this->user)))
			->andWhere($query->expr()->eq('room_id', $query->createNamedParameter($this->room->getId())));
		$query->execute();

		$this->lastMentionMessage = $messageId;
		return true;
	}

	public function canStartCall(): bool {
		$defaultStartCall = (int) $this->config->getAppValue('spreed', 'start_calls', Room::START_CALL_EVERYONE);

		if ($defaultStartCall === Room::START_CALL_EVERYONE) {
			return true;
		}

		if ($defaultStartCall === Room::START_CALL_USERS && (!$this->isGuest() || $this->hasModeratorPermissions())) {
			return true;
		}

		if ($defaultStartCall === Room::START_CALL_MODERATORS && $this->hasModeratorPermissions()) {
			return true;
		}

		return false;
	}
}
