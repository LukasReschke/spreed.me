<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020 Joas Schilling <coding@schilljs.com>
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

namespace OCA\Talk\Controller;

use OCA\Files_Sharing\SharedStorage;
use OCA\Talk\Chat\SpecialRoom\Manager as SpecialRoomManager;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Manager;
use OCA\Talk\Room;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IRequest;

class SettingsController extends OCSController {

	/** @var Manager */
	protected $talkManager;
	/** @var SpecialRoomManager */
	protected $specialRoomManager;
	/** @var IRootFolder */
	protected $rootFolder;
	/** @var IConfig */
	protected $config;
	/** @var ILogger */
	protected $logger;
	/** @var string|null */
	protected $userId;

	public function __construct(string $appName,
								IRequest $request,
								Manager $talkManager,
								SpecialRoomManager $specialRoomManager,
								IRootFolder $rootFolder,
								IConfig $config,
								ILogger $logger,
								?string $userId) {
		parent::__construct($appName, $request);
		$this->talkManager = $talkManager;
		$this->specialRoomManager = $specialRoomManager;
		$this->rootFolder = $rootFolder;
		$this->config = $config;
		$this->logger = $logger;
		$this->userId = $userId;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $key
	 * @param string|null $value
	 * @return DataResponse
	 */
	public function setUserSetting(string $key, ?string $value): DataResponse {
		if (!$this->validateUserSetting($key, $value)) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		if ($key === 'notes' && $value === '1') {
			// Setting it to 2 so createNotesIfNeeded can create the conversation and set it to 1 again
			$value = '2';
		}

		$this->config->setUserValue($this->userId, 'spreed', $key, $value);


		if ($key === 'notes') {
			if ($value === '0') {
				try {
					$room = $this->talkManager->getSpecialRoom($this->userId, Room::NOTES_CONVERSATION, false);
					$room->deleteRoom();
				} catch (RoomNotFoundException $e) {
				}
			} else {
				$this->specialRoomManager->createNotesIfNeeded($this->userId);
			}
		}

		return new DataResponse();
	}

	protected function validateUserSetting(string $setting, ?string $value): bool {
		if ($setting === 'attachment_folder') {
			$userFolder = $this->rootFolder->getUserFolder($this->userId);
			try {
				$node = $userFolder->get($value);
				if (!$node instanceof Folder) {
					throw new NotPermittedException('Node is not a directory');
				}
				return !$node->getStorage()->instanceOfStorage(SharedStorage::class);
			} catch (NotFoundException $e) {
				$userFolder->newFolder($value);
				return true;
			} catch (NotPermittedException $e) {
			} catch (\Exception $e) {
				$this->logger->logException($e);
			}
			return false;
		}
		if ($setting === 'notes') {
			return $value === '0' || $value === '1';
		}

		return false;
	}
}
