<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2018 Peter Edens <petere@conceiva.com>
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
namespace OCA\Talk\Tests\php;

use OC\EventDispatcher\EventDispatcher;
use OCA\Talk\Events\VerifyRoomPasswordEvent;
use OCA\Talk\Manager;
use OCA\Talk\Room;
use OCA\Talk\Webinary;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class RoomTest extends TestCase {
	public function testVerifyPassword() {
		$dispatcher = new EventDispatcher(
			new \Symfony\Component\EventDispatcher\EventDispatcher(),
			\OC::$server,
			$this->createMock(LoggerInterface::class)
		);
		$dispatcher->addListener(Room::EVENT_PASSWORD_VERIFY, static function (VerifyRoomPasswordEvent $event) {
			$password = $event->getPassword();

			if ($password === '1234') {
				$event->setIsPasswordValid(true);
				$event->setRedirectUrl('');
			} else {
				$event->setIsPasswordValid(false);
				$event->setRedirectUrl('https://test');
			}
		});

		$room = new Room(
			$this->createMock(Manager::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(ISecureRandom::class),
			$dispatcher,
			$this->createMock(ITimeFactory::class),
			$this->createMock(IHasher::class),
			1,
			Room::PUBLIC_CALL,
			Room::READ_WRITE,
			Room::LISTABLE_NONE,
			Webinary::LOBBY_NONE,
			0,
			null,
			'foobar',
			'Test',
			'description',
			'avatar-id',
			1,
			'passy',
			0,
			null,
			null,
			0
		);
		$verificationResult = $room->verifyPassword('1234');
		$this->assertSame($verificationResult, ['result' => true, 'url' => '']);
		$verificationResult = $room->verifyPassword('4321');
		$this->assertSame($verificationResult, ['result' => false, 'url' => 'https://test']);
		$this->assertSame('passy', $room->getPassword());
	}
}
