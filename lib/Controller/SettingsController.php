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
use OCA\Talk\Participant;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class SettingsController extends OCSController {

	/** @var IRootFolder */
	protected $rootFolder;
	/** @var IConfig */
	protected $config;
	/** @var LoggerInterface */
	protected $logger;
	/** @var string|null */
	protected $userId;

	public function __construct(string $appName,
								IRequest $request,
								IRootFolder $rootFolder,
								IConfig $config,
								LoggerInterface $logger,
								?string $userId) {
		parent::__construct($appName, $request);
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

		$this->config->setUserValue($this->userId, 'spreed', $key, $value);

		return new DataResponse();
	}

	protected function validateUserSetting(string $setting, ?string $value): bool {
		if ($setting === 'send_message_key') {
			return $value === Participant::SEND_MESSAGE_KEY_ENTER
				|| $value === Participant::SEND_MESSAGE_KEY_SHIFT_ENTER;
		} elseif ($setting === 'attachment_folder') {
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
				$this->logger->error($e->getMessage(), ['exception' => $e]);
			}
			return false;
		}

		return false;
	}
}
