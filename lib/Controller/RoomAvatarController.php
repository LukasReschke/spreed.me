<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2020, Daniel Calviño Sánchez (danxuliu@gmail.com)
 *
 * @author Daniel Calviño Sánchez <danxuliu@gmail.com>
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Talk\Controller;

use OCA\Talk\Avatar\RoomAvatarProvider;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\Image;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class RoomAvatarController extends OCSController {

	/** @var IL10N */
	protected $l;

	/** @var LoggerInterface */
	protected $logger;

	/** @var RoomAvatarProvider */
	protected $roomAvatarProvider;

	public function __construct($appName,
								IRequest $request,
								IL10N $l10n,
								LoggerInterface $logger,
								RoomAvatarProvider $roomAvatarProvider) {
		parent::__construct($appName, $request);

		$this->l = $l10n;
		$this->logger = $logger;
		$this->roomAvatarProvider = $roomAvatarProvider;
	}

	/**
	 * @PublicPage
	 *
	 * @param string $roomToken
	 * @param int $size
	 * @return DataResponse|FileDisplayResponse
	 */
	public function getAvatar(string $roomToken, int $size): Response {
		$size = $this->sanitizeSize($size);

		try {
			$avatar = $this->roomAvatarProvider->getAvatar($roomToken);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		if (!$this->roomAvatarProvider->canBeAccessedByCurrentUser($avatar)) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$avatarFile = $avatar->getFile($size);
			$response = new FileDisplayResponse(
				$avatarFile,
				Http::STATUS_OK,
				[
					'Content-Type' => $avatarFile->getMimeType(),
					'X-NC-IsCustomAvatar' => $avatar->isCustomAvatar() ? '1' : '0',
				]
			);
		} catch (NotFoundException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$cache = $this->roomAvatarProvider->getCacheTimeToLive($avatar);
		if ($cache !== null) {
			$response->cacheFor($cache);
		}

		return $response;
	}

	/**
	 * Returns the closest value to the predefined set of sizes
	 *
	 * @param int $size the size to sanitize
	 * @return int the sanitized size
	 */
	private function sanitizeSize(int $size): int {
		$validSizes = [64, 128, 256, 512];

		if ($size < $validSizes[0]) {
			return $validSizes[0];
		}

		if ($size > $validSizes[count($validSizes) - 1]) {
			return $validSizes[count($validSizes) - 1];
		}

		for ($i = 0; $i < count($validSizes) - 1; $i++) {
			if ($size >= $validSizes[$i] && $size <= $validSizes[$i + 1]) {
				$middlePoint = ($validSizes[$i] + $validSizes[$i + 1]) / 2;
				if ($size < $middlePoint) {
					return $validSizes[$i];
				}
				return $validSizes[$i + 1];
			}
		}

		return $size;
	}

	/**
	 * @PublicPage
	 *
	 * @param string $roomToken
	 * @return DataResponse
	 */
	public function setAvatar(string $roomToken): DataResponse {
		$files = $this->request->getUploadedFile('files');

		if (is_null($files)) {
			return new DataResponse(
				['data' => ['message' => $this->l->t('No file provided')]],
				Http::STATUS_BAD_REQUEST
			);
		}

		if (
			$files['error'][0] !== 0 ||
			!is_uploaded_file($files['tmp_name'][0]) ||
			\OC\Files\Filesystem::isFileBlacklisted($files['tmp_name'][0])
		) {
			return new DataResponse(
				['data' => ['message' => $this->l->t('Invalid file provided')]],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($files['size'][0] > 20 * 1024 * 1024) {
			return new DataResponse(
				['data' => ['message' => $this->l->t('File is too big')]],
				Http::STATUS_BAD_REQUEST
			);
		}

		$content = file_get_contents($files['tmp_name'][0]);
		unlink($files['tmp_name'][0]);

		$image = new Image();
		$image->loadFromData($content);

		try {
			$avatar = $this->roomAvatarProvider->getAvatar($roomToken);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		if (!$this->roomAvatarProvider->canBeModifiedByCurrentUser($avatar)) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$avatar->set($image);
			return new DataResponse(
				['status' => 'success']
			);
		} catch (\OC\NotSquareException $e) {
			return new DataResponse(
				['data' => ['message' => $this->l->t('Crop is not square')]],
				Http::STATUS_BAD_REQUEST
			);
		} catch (\Exception $e) {
			$this->logger->error('Error when setting avatar', ['app' => 'core', 'exception' => $e]);
			return new DataResponse(
				['data' => ['message' => $this->l->t('An error occurred. Please contact your admin.')]],
				Http::STATUS_BAD_REQUEST
			);
		}
	}

	/**
	 * @PublicPage
	 *
	 * @param string $roomToken
	 * @return DataResponse
	 */
	public function deleteAvatar(string $roomToken): DataResponse {
		try {
			$avatar = $this->roomAvatarProvider->getAvatar($roomToken);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		if (!$this->roomAvatarProvider->canBeModifiedByCurrentUser($avatar)) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$avatar->remove();
			return new DataResponse();
		} catch (\Exception $e) {
			$this->logger->error('Error when deleting avatar', ['app' => 'core', 'exception' => $e]);
			return new DataResponse(
				['data' => ['message' => $this->l->t('An error occurred. Please contact your admin.')]],
				Http::STATUS_BAD_REQUEST
			);
		}
	}
}
