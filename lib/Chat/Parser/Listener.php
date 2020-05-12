<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2018 Joas Schilling <coding@schilljs.com>
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

use OCA\Talk\Chat\MessageParser;
use OCA\Talk\Chat\Parser\Command as CommandParser;
use OCA\Talk\Events\ChatMessageEvent;
use OCP\EventDispatcher\IEventDispatcher;

class Listener {

	public static function register(IEventDispatcher $dispatcher): void {
		$dispatcher->addListener(MessageParser::EVENT_MESSAGE_PARSE, static function(ChatMessageEvent $event) {
			$message = $event->getMessage();

			if ($message->getMessageType() !== 'comment') {
				return;
			}

			/** @var UserMention $parser */
			$parser = \OC::$server->query(UserMention::class);
			$parser->parseMessage($message);
		}, -100);

		$dispatcher->addListener(MessageParser::EVENT_MESSAGE_PARSE, static function(ChatMessageEvent $event) {
			$message = $event->getMessage();

			if ($message->getMessageType() !== 'comment') {
				return;
			}

			/** @var Changelog $parser */
			$changelogParser = \OC::$server->query(Changelog::class);
			try {
				$changelogParser->parseMessage($message);
				$event->stopPropagation();
			} catch (\OutOfBoundsException $e) {
				// Unknown message, ignore
			}

			/** @var Notes $parser */
			$notesParser = \OC::$server->query(Notes::class);
			try {
				$notesParser->parseMessage($message);
				$event->stopPropagation();
			} catch (\OutOfBoundsException $e) {
				// Unknown message, ignore
			}

		}, -75);

		$dispatcher->addListener(MessageParser::EVENT_MESSAGE_PARSE, static function(ChatMessageEvent $event) {
			$message = $event->getMessage();

			if ($message->getMessageType() !== 'system') {
				return;
			}

			/** @var SystemMessage $parser */
			$parser = \OC::$server->query(SystemMessage::class);

			try {
				$parser->parseMessage($message);
				$event->stopPropagation();
			} catch (\OutOfBoundsException $e) {
				// Unknown message, ignore
			}
		});

		$dispatcher->addListener(MessageParser::EVENT_MESSAGE_PARSE, static function(ChatMessageEvent $event) {
			$chatMessage = $event->getMessage();

			if ($chatMessage->getMessageType() !== 'command') {
				return;
			}

			/** @var CommandParser $parser */
			$parser = \OC::$server->query(CommandParser::class);

			try {
				$parser->parseMessage($chatMessage);
				$event->stopPropagation();
			} catch (\OutOfBoundsException $e) {
				// Unknown message, ignore
			} catch (\RuntimeException $e) {
				$event->stopPropagation();
			}
		});
	}
}
