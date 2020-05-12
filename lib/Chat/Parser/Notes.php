<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020 Mario Danic <mario@lovelyhq.com>
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

namespace OCA\Talk\Chat\Parser;

use OCA\Talk\Model\Message;

class Notes {

	/**
	 * @param Message $chatMessage
	 * @throws \OutOfBoundsException
	 */
	public function parseMessage(Message $chatMessage): void {

		if ($chatMessage->getActorType() !== 'guests' ||
			$chatMessage->getActorId() !== 'notes') {
			throw new \OutOfBoundsException('Not notes');
		}

		$l = $chatMessage->getL10n();
		$chatMessage->setActor('bots', 'notes', $l->t('My notes'));
	}
}
