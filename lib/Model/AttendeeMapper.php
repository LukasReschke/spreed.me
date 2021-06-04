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

namespace OCA\Talk\Model;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @method Attendee mapRowToEntity(array $row)
 */
class AttendeeMapper extends QBMapper {

	/**
	 * @param IDBConnection $db
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'talk_attendees', Attendee::class);
	}

	/**
	 * @param int $roomId
	 * @param string $actorType
	 * @param string $actorId
	 * @return Attendee
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 */
	public function findByActor(int $roomId, string $actorType, string $actorId): Attendee {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from($this->getTableName())
			->where($query->expr()->eq('actor_type', $query->createNamedParameter($actorType)))
			->andWhere($query->expr()->eq('actor_id', $query->createNamedParameter($actorId)))
			->andWhere($query->expr()->eq('room_id', $query->createNamedParameter($roomId)));

		return $this->findEntity($query);
	}

	/**
	 * @param int $roomId
	 * @param string $actorType
	 * @param int|null $lastJoinedCall
	 * @return Attendee[]
	 */
	public function getActorsByType(int $roomId, string $actorType, ?int $lastJoinedCall = null): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from($this->getTableName())
			->where($query->expr()->eq('room_id', $query->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('actor_type', $query->createNamedParameter($actorType)));

		if ($lastJoinedCall !== null) {
			$query->andWhere($query->expr()->gte('last_joined_call', $query->createNamedParameter($lastJoinedCall, IQueryBuilder::PARAM_INT)));
		}

		return $this->findEntities($query);
	}

	/**
	 * @param int $roomId
	 * @param string $actorType
	 * @param int|null $lastJoinedCall
	 * @return int
	 */
	public function getActorsCountByType(int $roomId, string $actorType, ?int $lastJoinedCall = null): int {
		$query = $this->db->getQueryBuilder();
		$query->select($query->func()->count('*', 'num_actors'))
			->from($this->getTableName())
			->where($query->expr()->eq('room_id', $query->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('actor_type', $query->createNamedParameter($actorType)));

		if ($lastJoinedCall !== null) {
			$query->andWhere($query->expr()->gte('last_joined_call', $query->createNamedParameter($lastJoinedCall, IQueryBuilder::PARAM_INT)));
		}

		$result = $query->execute();
		$count = (int) $result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * @param int $roomId
	 * @param int[] $participantType
	 * @return int
	 */
	public function countActorsByParticipantType(int $roomId, array $participantType): int {
		$query = $this->db->getQueryBuilder();
		$query->select($query->func()->count('*', 'num_actors'))
			->from($this->getTableName())
			->where($query->expr()->eq('room_id', $query->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));

		// TODO Should exclude groups and circles when we add them

		if (!empty($participantType)) {
			$query->andWhere($query->expr()->in('participant_type', $query->createNamedParameter($participantType, IQueryBuilder::PARAM_INT_ARRAY)));
		}

		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		return (int) ($row['num_actors'] ?? 0);
	}

	/**
	 * @param int[] $ids
	 * @return int Number of deleted entities
	 */
	public function deleteByIds(array $ids): int {
		$query = $this->db->getQueryBuilder();
		$query->delete($this->getTableName())
			->where($query->expr()->in('id', $query->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

		return (int) $query->execute();
	}

	public function createAttendeeFromRow(array $row): Attendee {
		return $this->mapRowToEntity([
			'id' => $row['a_id'],
			'room_id' => $row['room_id'],
			'actor_type' => $row['actor_type'],
			'actor_id' => $row['actor_id'],
			'display_name' => (string) $row['display_name'],
			'pin' => $row['pin'],
			'participant_type' => (int) $row['participant_type'],
			'favorite' => (bool) $row['favorite'],
			'notification_level' => (int) $row['notification_level'],
			'last_joined_call' => (int) $row['last_joined_call'],
			'last_read_message' => (int) $row['last_read_message'],
			'last_mention_message' => (int) $row['last_mention_message'],
			'read_privacy' => (int) $row['read_privacy'],
			'publishing_permissions' => (int) $row['publishing_permissions'],
		]);
	}
}
