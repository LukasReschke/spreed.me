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

namespace OCA\Talk\Avatar;

use OCA\Talk\Room;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAvatar;
use OCP\IImage;
use OCP\IL10N;
use OCP\Image;
use Psr\Log\LoggerInterface;

class RoomAvatar implements IAvatar {

	/** @var ISimpleFolder */
	private $folder;

	/** @var Room */
	private $room;

	/** @var IL10N */
	private $l;

	/** @var LoggerInterface */
	private $logger;

	/** @var Util */
	private $util;

	public function __construct(
			ISimpleFolder $folder,
			Room $room,
			IL10N $l,
			LoggerInterface $logger,
			Util $util) {
		$this->folder = $folder;
		$this->room = $room;
		$this->l = $l;
		$this->logger = $logger;
		$this->util = $util;
	}

	public function getRoom(): Room {
		return $this->room;
	}

	/**
	 * Returns the default room avatar type ("user", "icon-public",
	 * "icon-contacts"...) for the given room data
	 *
	 * @param int $roomType the type of the room
	 * @param string $objectType the object type of the room
	 * @return string the room avatar type
	 */
	public static function getDefaultRoomAvatarType(int $roomType, string $objectType): string {
		if ($roomType === Room::ONE_TO_ONE_CALL) {
			return 'user';
		}

		if ($objectType === 'emails') {
			return 'icon-mail';
		}

		if ($objectType === 'file') {
			return 'icon-file';
		}

		if ($objectType === 'share:password') {
			return 'icon-password';
		}

		if ($roomType === Room::CHANGELOG_CONVERSATION) {
			return 'icon-changelog';
		}

		if ($roomType === Room::GROUP_CALL) {
			return 'icon-contacts';
		}

		return 'icon-public';
	}

	/**
	 * Returns the room avatar type ("custom", "user", "icon-public",
	 * "icon-contacts"...) of this RoomAvatar
	 *
	 * @return string the room avatar type
	 */
	public function getRoomAvatarType(): string {
		if ($this->isCustomAvatar()) {
			return 'custom';
		}

		return self::getDefaultRoomAvatarType($this->room->getType(), $this->room->getObjectType());
	}

	/**
	 * Gets the room avatar
	 *
	 * @param int $size size in px of the avatar, avatars are square, defaults
	 *        to 64, -1 can be used to not scale the image
	 * @return bool|\OCP\IImage containing the avatar or false if there is no
	 *         image
	 */
	public function get($size = 64) {
		$size = (int) $size;

		try {
			$file = $this->getFile($size);
		} catch (NotFoundException $e) {
			return false;
		}

		$avatar = new Image();
		$avatar->loadFromData($file->getContent());
		return $avatar;
	}

	/**
	 * Checks if an avatar exists for the room
	 *
	 * @return bool
	 */
	public function exists(): bool {
		return $this->folder->fileExists('avatar.jpg') || $this->folder->fileExists('avatar.png');
	}

	/**
	 * Checks if the avatar of a room is a custom uploaded one
	 *
	 * @return bool
	 */
	public function isCustomAvatar(): bool {
		return $this->exists();
	}

	/**
	 * Sets the room avatar
	 *
	 * @param \OCP\IImage|resource|string $data An image object, imagedata or
	 *        path to set a new avatar
	 * @throws \Exception if the provided file is not a jpg or png image
	 * @throws \Exception if the provided image is not valid
	 * @return void
	 */
	public function set($data): void {
		$image = $this->getAvatarImage($data);
		$data = $image->data();

		$this->validateAvatar($image);

		$this->removeFiles();
		$type = $this->getAvatarImageType($image);
		$file = $this->folder->newFile('avatar.' . $type);
		$file->putContent($data);

		$this->room->setAvatar($this->getRoomAvatarType(), $this->room->getAvatarVersion() + 1);
	}

	/**
	 * Returns an image from several sources
	 *
	 * @param IImage|resource|string $data An image object, imagedata or path to
	 *        the avatar
	 * @return IImage
	 */
	private function getAvatarImage($data): IImage {
		if ($data instanceof IImage) {
			return $data;
		}

		$image = new Image();
		if (is_resource($data) && get_resource_type($data) === 'gd') {
			$image->setResource($data);
		} elseif (is_resource($data)) {
			$image->loadFromFileHandle($data);
		} else {
			try {
				// detect if it is a path or maybe the images as string
				$result = @realpath($data);
				if ($result === false || $result === null) {
					$image->loadFromData($data);
				} else {
					$image->loadFromFile($data);
				}
			} catch (\Error $e) {
				$image->loadFromData($data);
			}
		}

		return $image;
	}

	/**
	 * Returns the avatar image type
	 *
	 * @param IImage $avatar
	 * @return string
	 */
	private function getAvatarImageType(IImage $avatar): string {
		$type = substr($avatar->mimeType(), -3);
		if ($type === 'peg') {
			$type = 'jpg';
		}
		return $type;
	}

	/**
	 * Validates an avatar image:
	 * - must be "png" or "jpg"
	 * - must be "valid"
	 * - must be in square format
	 *
	 * @param IImage $avatar The avatar to validate
	 * @throws \Exception if the provided file is not a jpg or png image
	 * @throws \Exception if the provided image is not valid
	 * @throws \Exception if the image is not square
	 */
	private function validateAvatar(IImage $avatar): void {
		$type = $this->getAvatarImageType($avatar);

		if ($type !== 'jpg' && $type !== 'png') {
			throw new \Exception($this->l->t('Unknown filetype'));
		}

		if (!$avatar->valid()) {
			throw new \Exception($this->l->t('Invalid image'));
		}

		if (!($avatar->height() === $avatar->width())) {
			throw new \Exception($this->l->t('Avatar image is not square'));
		}
	}

	/**
	 * Remove the room avatar
	 *
	 * @return void
	 */
	public function remove(): void {
		$this->removeFiles();

		$this->room->setAvatar($this->getRoomAvatarType(), $this->room->getAvatarVersion() + 1);
	}

	/**
	 * Remove the files for the room avatar
	 *
	 * @return void
	 */
	private function removeFiles(): void {
		$files = $this->folder->getDirectoryListing();

		// Deletes the original image as well as the resized ones.
		foreach ($files as $file) {
			$file->delete();
		}
	}

	/**
	 * Get the file of the avatar
	 *
	 * @param int $size -1 can be used to not scale the image
	 * @return ISimpleFile|File
	 * @throws NotFoundException
	 */
	public function getFile($size) {
		$size = (int) $size;

		if ($this->room->getType() === Room::ONE_TO_ONE_CALL) {
			$userAvatar = $this->util->getUserAvatarForOtherParticipant($this->room);

			return $userAvatar->getFile($size);
		}

		$extension = $this->getExtension();

		if ($size === -1) {
			$path = 'avatar.' . $extension;
		} else {
			$path = 'avatar.' . $size . '.' . $extension;
		}

		try {
			$file = $this->folder->getFile($path);
		} catch (NotFoundException $e) {
			if ($size <= 0) {
				throw new NotFoundException();
			}

			$file = $this->generateResizedAvatarFile($extension, $path, $size);
		}

		return $file;
	}

	/**
	 * Gets the extension of the avatar file
	 *
	 * @return string the extension
	 * @throws NotFoundException if there is no avatar
	 */
	private function getExtension(): string {
		if ($this->folder->fileExists('avatar.jpg')) {
			return 'jpg';
		}
		if ($this->folder->fileExists('avatar.png')) {
			return 'png';
		}
		throw new NotFoundException;
	}

	/**
	 * Generates a resized avatar file with the given size
	 *
	 * @param string $extension the extension of the original avatar file
	 * @param string $path the path to the resized avatar file
	 * @param int $size the size of the avatar
	 * @return ISimpleFile the resized avatar file
	 * @throws NotFoundException if it was not possible to generate the resized
	 *         avatar file
	 */
	private function generateResizedAvatarFile(string $extension, string $path, int $size): ISimpleFile {
		$avatar = new Image();
		$file = $this->folder->getFile('avatar.' . $extension);
		$avatar->loadFromData($file->getContent());
		$avatar->resize($size);
		$data = $avatar->data();

		try {
			$file = $this->folder->newFile($path);
			$file->putContent($data);
		} catch (NotPermittedException $e) {
			$this->logger->error('Failed to save avatar for room ' . $this->room->getToken() . ' with size ' . $size);
			throw new NotFoundException();
		}

		return $file;
	}

	/**
	 * Ignored.
	 */
	public function avatarBackgroundColor(string $text) {
		// Unused, unneeded, and Color class it not even public, so just return
		// null.
		return null;
	}

	/**
	 * Ignored.
	 */
	public function userChanged($feature, $oldValue, $newValue) {
	}
}
