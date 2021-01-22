<?php
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

use Behat\Gherkin\Node\TableNode;
use PHPUnit\Framework\Assert;

trait AvatarTrait {

	/** @var string **/
	private $lastAvatar;

	/** @AfterScenario **/
	public function cleanupLastAvatar() {
		$this->lastAvatar = null;
	}

	private function getLastAvatar() {
		$this->lastAvatar = '';

		$body = $this->response->getBody();
		while (!$body->eof()) {
			$this->lastAvatar .= $body->read(8192);
		}
		$body->close();
	}
	/**
	 * @When user :user gets avatar for room :identifier
	 *
	 * @param string $user
	 * @param string $identifier
	 */
	public function userGetsAvatarForRoom(string $user, string $identifier) {
		$this->userGetsAvatarForRoomWithSize($user, $identifier, '128');
	}

	/**
	 * @When user :user gets avatar for room :identifier with size :size
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $size
	 */
	public function userGetsAvatarForRoomWithSize(string $user, string $identifier, string $size) {
		$this->userGetsAvatarForRoomWithSizeWith($user, $identifier, $size, '200');
	}

	/**
	 * @When user :user gets avatar for room :identifier with size :size with :statusCode
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $size
	 * @param string $statusCode
	 */
	public function userGetsAvatarForRoomWithSizeWith(string $user, string $identifier, string $size, string $statusCode) {
		$this->setCurrentUser($user);
		$this->sendRequest('GET', '/apps/spreed/api/v3/avatar/' . FeatureContext::getTokenForIdentifier($identifier) . '/' . $size, null);
		$this->assertStatusCode($this->response, $statusCode);

		if ($statusCode !== '200') {
			return;
		}

		$this->getLastAvatar();
	}

	/**
	 * @When user :user sets avatar for room :identifier from file :source
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $source
	 */
	public function userSetsAvatarForRoomFromFile(string $user, string $identifier, string $source) {
		$this->userSetsAvatarForRoomFromFileWith($user, $identifier, $source, '200');
	}

	/**
	 * @When user :user sets avatar for room :identifier from file :source with :statusCode
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $source
	 * @param string $statusCode
	 */
	public function userSetsAvatarForRoomFromFileWith(string $user, string $identifier, string $source, string $statusCode) {
		$file = \GuzzleHttp\Psr7\stream_for(fopen($source, 'r'));

		$this->setCurrentUser($user);
		$this->sendRequest('POST', '/apps/spreed/api/v3/avatar/' . FeatureContext::getTokenForIdentifier($identifier),
			[
				'multipart' => [
					[
						'name' => 'files[]',
						'contents' => $file
					]
				]
			]);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @When user :user deletes avatar for room :identifier
	 *
	 * @param string $user
	 * @param string $identifier
	 */
	public function userDeletesAvatarForRoom(string $user, string $identifier) {
		$this->userDeletesAvatarForRoomWith($user, $identifier, '200');
	}

	/**
	 * @When user :user deletes avatar for room :identifier with :statusCode
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $statusCode
	 */
	public function userDeletesAvatarForRoomWith(string $user, string $identifier, string $statusCode) {
		$this->setCurrentUser($user);
		$this->sendRequest('DELETE', '/apps/spreed/api/v3/avatar/' . FeatureContext::getTokenForIdentifier($identifier), null);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @When logged in user posts temporary avatar from file :source
	 *
	 * @param string $source
	 */
	public function loggedInUserPostsTemporaryAvatarFromFile(string $source) {
		$file = \GuzzleHttp\Psr7\stream_for(fopen($source, 'r'));

		$this->sendingToWithRequestToken('POST', '/index.php/avatar',
			[
				'multipart' => [
					[
						'name' => 'files[]',
						'contents' => $file
					]
				]
			]);
		$this->assertStatusCode($this->response, '200');
	}

	/**
	 * @When logged in user crops temporary avatar
	 *
	 * @param TableNode $crop
	 */
	public function loggedInUserCropsTemporaryAvatar(TableNode $crop) {
		$parameters = [];
		foreach ($crop->getRowsHash() as $key => $value) {
			$parameters[] = 'crop[' . $key . ']=' . $value;
		}

		$this->sendingToWithRequestToken('POST', '/index.php/avatar/cropped?' . implode('&', $parameters));
		$this->assertStatusCode($this->response, '200');
	}

	/**
	 * @When logged in user deletes the user avatar
	 */
	public function loggedInUserDeletesTheUserAvatar() {
		$this->sendingToWithRequesttoken('DELETE', '/index.php/avatar');
		$this->assertStatusCode($this->response, '200');
	}

	/**
	 * @Then last avatar is a default avatar of size :size
	 *
	 * @param string size
	 */
	public function lastAvatarIsADefaultAvatarOfSize(string $size) {
		$this->theFollowingHeadersShouldBeSet(new TableNode([
			[ 'Content-Type', 'image/png' ],
			[ 'X-NC-IsCustomAvatar', '0' ]
		]));
		$this->lastAvatarIsASquareOfSize($size);
		$this->lastAvatarIsNotASingleColor();
	}

	/**
	 * @Then last avatar is a custom avatar of size :size and color :color
	 *
	 * @param string size
	 */
	public function lastAvatarIsACustomAvatarOfSizeAndColor(string $size, string $color) {
		$this->theFollowingHeadersShouldBeSet(new TableNode([
			[ 'Content-Type', 'image/png' ],
			[ 'X-NC-IsCustomAvatar', '1' ]
		]));
		$this->lastAvatarIsASquareOfSize($size);
		$this->lastAvatarIsASingleColor($color);
	}

	/**
	 * @Then last avatar is a square of size :size
	 *
	 * @param string size
	 */
	public function lastAvatarIsASquareOfSize(string $size) {
		list($width, $height) = getimagesizefromstring($this->lastAvatar);

		Assert::assertEquals($width, $height, 'Avatar is not a square');
		Assert::assertEquals($size, $width);
	}

	/**
	 * @Then last avatar is not a single color
	 */
	public function lastAvatarIsNotASingleColor() {
		Assert::assertEquals(null, $this->getColorFromLastAvatar());
	}

	/**
	 * @Then last avatar is a single :color color
	 *
	 * @param string $color
	 * @param string $size
	 */
	public function lastAvatarIsASingleColor(string $color) {
		$expectedColor = $this->hexStringToRgbColor($color);
		$colorFromLastAvatar = $this->getColorFromLastAvatar();

		if (!$colorFromLastAvatar) {
			Assert::fail('Last avatar is not a single color');
		}

		Assert::assertTrue($this->isSameColor($expectedColor, $colorFromLastAvatar),
			$this->rgbColorToHexString($colorFromLastAvatar) . ' does not match expected ' . $color);
	}

	private function hexStringToRgbColor($hexString) {
		// Strip initial "#"
		$hexString = substr($hexString, 1);

		$rgbColorInt = hexdec($hexString);

		// RGBA hex strings are not supported; the given string is assumed to be
		// an RGB hex string.
		return [
			'red' => ($rgbColorInt >> 16) & 0xFF,
			'green' => ($rgbColorInt >> 8) & 0xFF,
			'blue' => $rgbColorInt & 0xFF,
			'alpha' => 0
		];
	}

	private function rgbColorToHexString($rgbColor) {
		$rgbColorInt = ($rgbColor['red'] << 16) + ($rgbColor['green'] << 8) + ($rgbColor['blue']);

		return '#' . str_pad(strtoupper(dechex($rgbColorInt)), 6, '0', STR_PAD_LEFT);
	}

	private function getColorFromLastAvatar() {
		$image = imagecreatefromstring($this->lastAvatar);

		$firstPixelColorIndex = imagecolorat($image, 0, 0);
		$firstPixelColor = imagecolorsforindex($image, $firstPixelColorIndex);

		for ($i = 0; $i < imagesx($image); $i++) {
			for ($j = 0; $j < imagesx($image); $j++) {
				$currentPixelColorIndex = imagecolorat($image, $i, $j);
				$currentPixelColor = imagecolorsforindex($image, $currentPixelColorIndex);

				// The colors are compared with a small allowed delta, as even
				// on solid color images the resizing can cause some small
				// artifacts that slightly modify the color of certain pixels.
				if (!$this->isSameColor($firstPixelColor, $currentPixelColor)) {
					imagedestroy($image);

					return null;
				}
			}
		}

		imagedestroy($image);

		return $firstPixelColor;
	}

	private function isSameColor(array $firstColor, array $secondColor, int $allowedDelta = 1) {
		if ($this->isSameColorComponent($firstColor['red'], $secondColor['red'], $allowedDelta) &&
			$this->isSameColorComponent($firstColor['green'], $secondColor['green'], $allowedDelta) &&
			$this->isSameColorComponent($firstColor['blue'], $secondColor['blue'], $allowedDelta) &&
			$this->isSameColorComponent($firstColor['alpha'], $secondColor['alpha'], $allowedDelta)) {
			return true;
		}

		return false;
	}

	private function isSameColorComponent(int $firstColorComponent, int $secondColorComponent, int $allowedDelta) {
		if ($firstColorComponent >= ($secondColorComponent - $allowedDelta) &&
			$firstColorComponent <= ($secondColorComponent + $allowedDelta)) {
			return true;
		}

		return false;
	}
}
