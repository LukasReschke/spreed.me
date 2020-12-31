<?php
/**
 * @author Joas Schilling <coding@schilljs.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */
require __DIR__ . '/../../vendor/autoload.php';

use Behat\Behat\Context\Context;
use Behat\Behat\Context\SnippetAcceptingContext;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\TableNode;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ClientException;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;

/**
 * Defines application features from the specific context.
 */
class FeatureContext implements Context, SnippetAcceptingContext {
	public const TEST_PASSWORD = '123456';

	/** @var array[] */
	protected static $identifierToToken;
	/** @var array[] */
	protected static $tokenToIdentifier;
	/** @var array[] */
	protected static $sessionIdToUser;
	/** @var array[] */
	protected static $userToSessionId;
	/** @var array[] */
	protected static $messages;

	/** @var string */
	protected $currentUser;

	/** @var string */
	protected $loggedInUser;

	/** @var ResponseInterface */
	private $response;

	/** @var CookieJar[] */
	private $cookieJars;

	/** @var string */
	private $requestToken;

	/** @var string */
	protected $baseUrl;

	/** @var string */
	protected $lastEtag;

	/** @var array */
	protected $createdUsers = [];

	/** @var array */
	protected $createdGroups = [];

	/** @var array */
	protected $createdGuestAccountUsers = [];

	/** @var array */
	protected $changedConfigs = [];

	/** @var SharingContext */
	private $sharingContext;

	/** @var null|bool */
	private $guestsAppWasEnabled = null;

	/** @var string */
	private $guestsOldWhitelist;

	use AvatarTrait;
	use CommandLineTrait;

	public static function getTokenForIdentifier(string $identifier) {
		return self::$identifierToToken[$identifier];
	}

	/**
	 * FeatureContext constructor.
	 */
	public function __construct() {
		$this->cookieJars = [];
		$this->baseUrl = getenv('TEST_SERVER_URL');
		$this->guestsAppWasEnabled = null;
	}

	/**
	 * @BeforeScenario
	 */
	public function setUp() {
		self::$identifierToToken = [];
		self::$tokenToIdentifier = [];
		self::$sessionIdToUser = [];
		self::$userToSessionId = [];
		self::$messages = [];

		$this->createdUsers = [];
		$this->createdGroups = [];
		$this->createdGuestAccountUsers = [];
	}

	/**
	 * @BeforeScenario
	 */
	public function getOtherRequiredSiblingContexts(BeforeScenarioScope $scope) {
		$environment = $scope->getEnvironment();

		$this->sharingContext = $environment->getContext("SharingContext");
	}

	/**
	 * @AfterScenario
	 */
	public function tearDown() {
		foreach ($this->createdUsers as $user) {
			$this->deleteUser($user);
		}
		foreach ($this->createdGroups as $group) {
			$this->deleteGroup($group);
		}
		foreach ($this->createdGuestAccountUsers as $user) {
			$this->deleteUser($user);
		}
	}

	/**
	 * @Then /^user "([^"]*)" cannot find any listed rooms(?: \((v3)\))?$/
	 *
	 * @param string $user
	 * @param string $apiVersion
	 */
	public function userCannotFindAnyListedRooms($user, $apiVersion = 'v3') {
		$this->userCanFindListedRoomsWithTerm($user, '', $apiVersion, null);
	}

	/**
	 * @Then /^user "([^"]*)" cannot find any listed rooms with (\d+)(?: \((v3)\))?$/
	 *
	 * @param string $user
	 * @param int $statusCode
	 * @param string $apiVersion
	 */
	public function userCannotFindAnyListedRoomsWithStatus($user, $statusCode, $apiVersion = 'v3') {
		$this->setCurrentUser($user);
		$this->sendRequest('GET', '/apps/spreed/api/' . $apiVersion . '/room');
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" cannot find any listed rooms with term "([^"]*)"(?: \((v3)\))?$/
	 *
	 * @param string $user
	 * @param string $term
	 * @param string $apiVersion
	 */
	public function userCannotFindAnyListedRoomsWithTerm($user, $term, $apiVersion = 'v3') {
		$this->userCanFindListedRoomsWithTerm($user, $term, $apiVersion, null);
	}

	/**
	 * @Then /^user "([^"]*)" can find listed rooms(?: \((v3)\))?$/
	 *
	 * @param string $user
	 * @param string $apiVersion
	 * @param TableNode|null $formData
	 */
	public function userCanFindListedRooms($user, $apiVersion = 'v3', TableNode $formData = null) {
		$this->userCanFindListedRoomsWithTerm($user, '', $apiVersion, $formData);
	}

	/**
	 * @Then /^user "([^"]*)" can find listed rooms with term "([^"]*)"(?: \((v3)\))?$/
	 *
	 * @param string $user
	 * @param string $term
	 * @param string $apiVersion
	 * @param TableNode|null $formData
	 */
	public function userCanFindListedRoomsWithTerm($user, $term, $apiVersion = 'v3', TableNode $formData = null) {
		$this->setCurrentUser($user);
		$suffix = '';
		if ($term !== '') {
			$suffix = '?searchTerm=' . \rawurlencode($term);
		}
		$this->sendRequest('GET', '/apps/spreed/api/' . $apiVersion . '/listed-room' . $suffix);
		$this->assertStatusCode($this->response, 200);

		$rooms = $this->getDataFromResponse($this->response);

		if ($formData === null) {
			Assert::assertEmpty($rooms);
			return;
		}

		$this->assertRooms($rooms, $formData);
	}

	/**
	 * @Then /^user "([^"]*)" is participant of the following rooms(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param TableNode|null $formData
	 */
	public function userIsParticipantOfRooms($user, $apiVersion = 'v1', TableNode $formData = null) {
		$this->setCurrentUser($user);
		$this->sendRequest('GET', '/apps/spreed/api/' . $apiVersion . '/room');
		$this->assertStatusCode($this->response, 200);

		$rooms = $this->getDataFromResponse($this->response);

		$rooms = array_filter($rooms, function ($room) {
			return $room['type'] !== 4;
		});

		if ($formData === null) {
			Assert::assertEmpty($rooms);
			return;
		}

		$this->assertRooms($rooms, $formData);
	}

	/**
	 * @param array $rooms
	 * @param TableNode $formData
	 */
	private function assertRooms($rooms, TableNode $formData) {
		Assert::assertCount(count($formData->getHash()), $rooms, 'Room count does not match');
		Assert::assertEquals($formData->getHash(), array_map(function ($room, $expectedRoom) {
			$data = [];
			if (isset($expectedRoom['id'])) {
				$data['id'] = self::$tokenToIdentifier[$room['token']];
			}
			if (isset($expectedRoom['name'])) {
				$data['name'] = $room['name'];
			}
			if (isset($expectedRoom['description'])) {
				$data['description'] = $room['description'];
			}
			if (isset($expectedRoom['avatarId'])) {
				$data['avatarId'] = $room['avatarId'];
			}
			if (isset($expectedRoom['avatarVersion'])) {
				$data['avatarVersion'] = $room['avatarVersion'];
			}
			if (isset($expectedRoom['type'])) {
				$data['type'] = (string) $room['type'];
			}
			if (isset($expectedRoom['hasPassword'])) {
				$data['hasPassword'] = (string) $room['hasPassword'];
			}
			if (isset($expectedRoom['readOnly'])) {
				$data['readOnly'] = (string) $room['readOnly'];
			}
			if (isset($expectedRoom['listable'])) {
				$data['listable'] = (string) $room['listable'];
			}
			if (isset($expectedRoom['participantType'])) {
				$data['participantType'] = (string) $room['participantType'];
			}
			if (isset($expectedRoom['sipEnabled'])) {
				$data['sipEnabled'] = (string) $room['sipEnabled'];
			}
			if (isset($expectedRoom['attendeePin'])) {
				$data['attendeePin'] = $room['attendeePin'] ? '**PIN**' : '';
			}
			if (isset($expectedRoom['lastMessage'])) {
				$data['lastMessage'] = $room['lastMessage'] ? $room['lastMessage']['message'] : '';
			}
			if (isset($expectedRoom['participants'])) {
				$participantNames = array_map(function ($participant) {
					return $participant['name'];
				}, $room['participants']);

				// When participants have the same last ping the order in which they
				// are returned from the server is undefined. That is the most
				// common case during the tests, so by default the list of
				// participants returned by the server is sorted alphabetically. In
				// order to check the exact order of participants returned by the
				// server " [exact order]" can be appended in the test definition to
				// the list of expected participants of the room.
				if (strpos($expectedRoom['participants'], ' [exact order]') === false) {
					sort($participantNames);
				} else {
					// "end(array_keys(..." would generate the Strict Standards
					// error "Only variables should be passed by reference".
					$participantNamesKeys = array_keys($participantNames);
					$lastParticipantKey = end($participantNamesKeys);

					// Append " [exact order]" to the last participant so the
					// imploded string is the same as the expected one.
					$participantNames[$lastParticipantKey] .= ' [exact order]';
				}
				$data['participants'] = implode(', ', $participantNames);
			}

			return $data;
		}, $rooms, $formData->getHash()));
	}

	/**
	 * @Then /^user "([^"]*)" (is|is not) participant of room "([^"]*)"(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $isOrNotParticipant
	 * @param string $identifier
	 * @param string $apiVersion
	 * @param TableNode|null $formData
	 */
	public function userIsParticipantOfRoom($user, $isOrNotParticipant, $identifier, $apiVersion = 'v1', TableNode $formData = null) {
		if (strpos($user, 'guest') === 0) {
			$this->guestIsParticipantOfRoom($user, $isOrNotParticipant, $identifier, $apiVersion, $formData);

			return;
		}

		$this->setCurrentUser($user);
		$this->sendRequest('GET', '/apps/spreed/api/' . $apiVersion . '/room');
		$this->assertStatusCode($this->response, 200);

		$isParticipant = $isOrNotParticipant === 'is';

		$rooms = $this->getDataFromResponse($this->response);

		$rooms = array_filter($rooms, function ($room) {
			return $room['type'] !== 4;
		});

		if ($isParticipant) {
			Assert::assertNotEmpty($rooms);
		}

		foreach ($rooms as $room) {
			if (self::$tokenToIdentifier[$room['token']] === $identifier) {
				Assert::assertEquals($isParticipant, true, 'Room ' . $identifier . ' found in user´s room list');

				if ($formData) {
					$this->sendRequest('GET', '/apps/spreed/api/' . $apiVersion . '/room/' . self::$identifierToToken[$identifier]);

					$rooms = [$this->getDataFromResponse($this->response)];

					$this->assertRooms($rooms, $formData);
				}

				return;
			}
		}

		Assert::assertEquals($isParticipant, false, 'Room ' . $identifier . ' not found in user´s room list');
	}

	/**
	 * @Then /^user "([^"]*)" sees the following attendees in room "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $statusCode
	 * @param string $apiVersion
	 * @param TableNode $formData
	 */
	public function userSeesAttendeesInRoom($user, $identifier, $statusCode, $apiVersion = 'v1', TableNode $formData = null) {
		$this->setCurrentUser($user);
		$this->sendRequest('GET', '/apps/spreed/api/' . $apiVersion . '/room/' . self::$identifierToToken[$identifier] . '/participants');
		$this->assertStatusCode($this->response, $statusCode);

		if ($formData instanceof TableNode) {
			$attendees = $this->getDataFromResponse($this->response);
			$expectedKeys = array_flip($formData->getRows()[0]);

			$result = [];
			foreach ($attendees as $attendee) {
				$data = [];
				if (isset($expectedKeys['actorType'])) {
					$data['actorType'] = $attendee['actorType'];
				}
				if (isset($expectedKeys['actorId'])) {
					$data['actorId'] = $attendee['actorId'];
				}
				if (isset($expectedKeys['participantType'])) {
					$data['participantType'] = (string) $attendee['participantType'];
				}
				if (isset($expectedKeys['inCall'])) {
					$data['inCall'] = (string) $attendee['inCall'];
				}
				if (isset($expectedKeys['attendeePin'])) {
					$data['attendeePin'] = $attendee['attendeePin'] ? '**PIN**' : '';
				}

				$result[] = $data;
			}

			$expected = array_map(function ($attendee) {
				if (isset($attendee['actorId']) && substr($attendee['actorId'], 0, strlen('"guest')) === '"guest') {
					$attendee['actorId'] = sha1(self::$userToSessionId[trim($attendee['actorId'], '"')]);
				}
				if (isset($attendee['participantType'])) {
					$attendee['participantType'] = (string)$this->mapParticipantTypeTestInput($attendee['participantType']);
				}
				return $attendee;
			}, $formData->getHash());

			usort($expected, [$this, 'sortAttendees']);
			usort($result, [$this, 'sortAttendees']);

			Assert::assertEquals($expected, $result);
		} else {
			Assert::assertNull($formData);
		}
	}

	protected function sortAttendees(array $a1, array $a2): int {
		if ($a1['participantType'] !== $a2['participantType']) {
			return $a1['participantType'] <=> $a2['participantType'];
		}
		if ($a1['actorType'] !== $a2['actorType']) {
			return $a1['actorType'] <=> $a2['actorType'];
		}
		return $a1['actorId'] <=> $a2['actorId'];
	}

	private function mapParticipantTypeTestInput($participantType) {
		if (is_numeric($participantType)) {
			return $participantType;
		}

		switch ($participantType) {
			case 'OWNER': return 1;
			case 'MODERATOR': return 2;
			case 'USER': return 3;
			case 'GUEST': return 4;
			case 'USER_SELF_JOINED': return 5;
			case 'GUEST_MODERATOR': return 6;
		}

		Assert::fail('Invalid test input value for participant type');
	}

	/**
	 * @param string $guest
	 * @param string $isOrNotParticipant
	 * @param string $identifier
	 * @param string $apiVersion
	 * @param TableNode|null $formData
	 */
	private function guestIsParticipantOfRoom($guest, $isOrNotParticipant, $identifier, $apiVersion = 'v1', TableNode $formData = null) {
		$this->setCurrentUser($guest);
		$this->sendRequest('GET', '/apps/spreed/api/' . $apiVersion . '/room/' . self::$identifierToToken[$identifier]);

		$response = $this->getDataFromResponse($this->response);

		$isParticipant = $isOrNotParticipant === 'is';

		if ($formData) {
			$rooms = [$response];

			$this->assertRooms($rooms, $formData);
		}

		if ($isParticipant) {
			$this->assertStatusCode($this->response, 200);
			Assert::assertEquals(self::$userToSessionId[$guest], $response['sessionId']);

			return;
		}

		if ($this->response->getStatusCode() === 200) {
			// Public rooms can always be got, but if the guest is not a
			// participant the sessionId will be 0.
			Assert::assertEquals(0, $response['sessionId']);

			return;
		}

		$this->assertStatusCode($this->response, 404);
	}

	/**
	 * @Then /^user "([^"]*)" creates room "([^"]*)"(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $apiVersion
	 * @param TableNode|null $formData
	 */
	public function userCreatesRoom($user, $identifier, $apiVersion = 'v1', TableNode $formData = null) {
		$this->userCreatesRoomWith($user, $identifier, 201, $apiVersion, $formData);
	}

	/**
	 * @Then /^user "([^"]*)" creates room "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param int $statusCode
	 * @param string $apiVersion
	 * @param TableNode|null $formData
	 */
	public function userCreatesRoomWith($user, $identifier, $statusCode, $apiVersion = 'v1', TableNode $formData = null) {
		$this->setCurrentUser($user);
		$this->sendRequest('POST', '/apps/spreed/api/' . $apiVersion . '/room', $formData);
		$this->assertStatusCode($this->response, $statusCode);

		$response = $this->getDataFromResponse($this->response);

		if ($statusCode === 201) {
			self::$identifierToToken[$identifier] = $response['token'];
			self::$tokenToIdentifier[$response['token']] = $identifier;
		}
	}

	/**
	 * @Then /^user "([^"]*)" tries to create room with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param int $statusCode
	 * @param string $apiVersion
	 * @param TableNode|null $formData
	 */
	public function userTriesToCreateRoom($user, $statusCode, $apiVersion = 'v1', TableNode $formData = null) {
		$this->setCurrentUser($user);
		$this->sendRequest('POST', '/apps/spreed/api/' . $apiVersion . '/room', $formData);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" gets the room for path "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $path
	 * @param int $statusCode
	 * @param string $apiVersion
	 */
	public function userGetsTheRoomForPath($user, $path, $statusCode, $apiVersion = 'v1') {
		$fileId = $this->getFileIdForPath($user, $path);

		$this->setCurrentUser($user);
		$this->sendRequest('GET', '/apps/spreed/api/' . $apiVersion . '/file/' . $fileId);
		$this->assertStatusCode($this->response, $statusCode);

		if ($statusCode !== '200') {
			return;
		}

		$response = $this->getDataFromResponse($this->response);

		$identifier = 'file ' . $path . ' room';
		self::$identifierToToken[$identifier] = $response['token'];
		self::$tokenToIdentifier[$response['token']] = $identifier;
	}

	/**
	 * @param string $user
	 * @param string $path
	 * @return int
	 */
	private function getFileIdForPath($user, $path) {
		$this->currentUser = $user;

		$url = "/$user/$path";

		$headers = [];
		$headers['Depth'] = 0;

		$body = '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">' .
				'	<d:prop>' .
				'		<oc:fileid/>' .
				'	</d:prop>' .
				'</d:propfind>';

		$this->sendingToDav('PROPFIND', $url, $headers, $body);

		$this->assertStatusCode($this->response, 207);

		$xmlResponse = simplexml_load_string($this->response->getBody());
		$xmlResponse->registerXPathNamespace('oc', 'http://owncloud.org/ns');

		return (int)$xmlResponse->xpath('//oc:fileid')[0];
	}

	/**
	 * @param string $verb
	 * @param string $url
	 * @param array $headers
	 * @param string $body
	 */
	private function sendingToDav(string $verb, string $url, array $headers = null, string $body = null) {
		$fullUrl = $this->baseUrl . 'remote.php/dav/files' . $url;
		$client = new Client();
		$options = [];
		if ($this->currentUser === 'admin') {
			$options['auth'] = 'admin';
		} else {
			$options['auth'] = [$this->currentUser, self::TEST_PASSWORD];
		}
		$options['headers'] = [
			'OCS_APIREQUEST' => 'true'
		];
		if ($headers !== null) {
			$options['headers'] = array_merge($options['headers'], $headers);
		}
		if ($body !== null) {
			$options['body'] = $body;
		}

		try {
			$this->response = $client->{$verb}($fullUrl, $options);
		} catch (GuzzleHttp\Exception\ClientException $ex) {
			$this->response = $ex->getResponse();
		}
	}

	/**
	 * @Then /^user "([^"]*)" gets the room for last share with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param int $statusCode
	 * @param string $apiVersion
	 */
	public function userGetsTheRoomForLastShare($user, $statusCode, $apiVersion = 'v1') {
		$shareToken = $this->sharingContext->getLastShareToken();

		$this->setCurrentUser($user);
		$this->sendRequest('GET', '/apps/spreed/api/' . $apiVersion . '/publicshare/' . $shareToken);
		$this->assertStatusCode($this->response, $statusCode);

		if ($statusCode !== '200') {
			return;
		}

		$response = $this->getDataFromResponse($this->response);

		$identifier = 'file last share room';
		self::$identifierToToken[$identifier] = $response['token'];
		self::$tokenToIdentifier[$response['token']] = $identifier;
	}

	/**
	 * @Then /^user "([^"]*)" creates the password request room for last share with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param int $statusCode
	 * @param string $apiVersion
	 */
	public function userCreatesThePasswordRequestRoomForLastShare($user, $statusCode, $apiVersion = 'v1') {
		$shareToken = $this->sharingContext->getLastShareToken();

		$this->setCurrentUser($user);
		$this->sendRequest('POST', '/apps/spreed/api/' . $apiVersion . '/publicshareauth', ['shareToken' => $shareToken]);
		$this->assertStatusCode($this->response, $statusCode);

		if ($statusCode !== '201') {
			return;
		}

		$response = $this->getDataFromResponse($this->response);

		$identifier = 'password request for last share room';
		self::$identifierToToken[$identifier] = $response['token'];
		self::$tokenToIdentifier[$response['token']] = $identifier;
	}

	/**
	 * @Then /^user "([^"]*)" joins room "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $statusCode
	 * @param string $apiVersion
	 * @param TableNode|null $formData
	 */
	public function userJoinsRoom($user, $identifier, $statusCode, $apiVersion = 'v1', TableNode $formData = null) {
		$this->setCurrentUser($user);
		$this->sendRequest(
			'POST', '/apps/spreed/api/' . $apiVersion . '/room/' . self::$identifierToToken[$identifier] . '/participants/active',
			$formData
		);
		$this->assertStatusCode($this->response, $statusCode);

		if ($statusCode !== '200') {
			return;
		}

		$response = $this->getDataFromResponse($this->response);
		if (array_key_exists('sessionId', $response)) {
			// In the chat guest users are identified by their sessionId. The
			// sessionId is larger than the size of the actorId column in the
			// database, though, so the ID stored in the database and returned
			// in chat messages is a hashed version instead.
			self::$sessionIdToUser[sha1($response['sessionId'])] = $user;
			self::$userToSessionId[$user] = $response['sessionId'];
		}
	}

	/**
	 * @Then /^user "([^"]*)" leaves room "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $statusCode
	 * @param string $apiVersion
	 */
	public function userExitsRoom($user, $identifier, $statusCode, $apiVersion = 'v1') {
		$this->setCurrentUser($user);
		$this->sendRequest('DELETE', '/apps/spreed/api/' . $apiVersion . '/room/' . self::$identifierToToken[$identifier] . '/participants/active');
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" removes themselves from room "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $statusCode
	 * @param string $apiVersion
	 */
	public function userLeavesRoom($user, $identifier, $statusCode, $apiVersion = 'v1') {
		$this->setCurrentUser($user);
		$this->sendRequest('DELETE', '/apps/spreed/api/' . $apiVersion . '/room/' . self::$identifierToToken[$identifier] . '/participants/self');
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" removes "([^"]*)" from room "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $toRemove
	 * @param string $identifier
	 * @param string $statusCode
	 * @param string $apiVersion
	 */
	public function userRemovesUserFromRoom($user, $toRemove, $identifier, $statusCode, $apiVersion = 'v1') {
		$this->setCurrentUser($user);
		$this->sendRequest(
			'DELETE', '/apps/spreed/api/' . $apiVersion . '/room/' . self::$identifierToToken[$identifier] . '/participants',
			new TableNode([['participant', $toRemove]])
		);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" deletes room "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $statusCode
	 * @param string $apiVersion
	 */
	public function userDeletesRoom($user, $identifier, $statusCode, $apiVersion = 'v1') {
		$this->setCurrentUser($user);
		$this->sendRequest('DELETE', '/apps/spreed/api/' . $apiVersion . '/room/' . self::$identifierToToken[$identifier]);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" renames room "([^"]*)" to "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $newName
	 * @param string $statusCode
	 * @param string $apiVersion
	 */
	public function userRenamesRoom($user, $identifier, $newName, $statusCode, $apiVersion = 'v1') {
		$this->setCurrentUser($user);
		$this->sendRequest(
			'PUT', '/apps/spreed/api/' . $apiVersion . '/room/' . self::$identifierToToken[$identifier],
			new TableNode([['roomName', $newName]])
		);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @When /^user "([^"]*)" sets description for room "([^"]*)" to "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $description
	 * @param string $statusCode
	 * @param string $apiVersion
	 * @param TableNode
	 */
	public function userSetsDescriptionForRoomTo($user, $identifier, $description, $statusCode, $apiVersion = 'v3') {
		$this->setCurrentUser($user);
		$this->sendRequest(
			'PUT', '/apps/spreed/api/' .$apiVersion . '/room/' . self::$identifierToToken[$identifier] . '/description',
			new TableNode([['description', $description]])
		);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @When /^user "([^"]*)" sets password "([^"]*)" for room "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $password
	 * @param string $identifier
	 * @param string $statusCode
	 * @param string $apiVersion
	 */
	public function userSetsTheRoomPassword($user, $password, $identifier, $statusCode, $apiVersion = 'v1') {
		$this->setCurrentUser($user);
		$this->sendRequest(
			'PUT', '/apps/spreed/api/' . $apiVersion . '/room/' . self::$identifierToToken[$identifier] . '/password',
			new TableNode([['password', $password]])
		);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @When /^user "([^"]*)" sets lobby state for room "([^"]*)" to "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $lobbyStateString
	 * @param string $statusCode
	 * @param string $apiVersion
	 */
	public function userSetsLobbyStateForRoomTo($user, $identifier, $lobbyStateString, $statusCode, $apiVersion = 'v1') {
		if ($lobbyStateString === 'no lobby') {
			$lobbyState = 0;
		} elseif ($lobbyStateString === 'non moderators') {
			$lobbyState = 1;
		} else {
			Assert::fail('Invalid lobby state');
		}

		$this->setCurrentUser($user);
		$this->sendRequest(
			'PUT', '/apps/spreed/api/' . $apiVersion . '/room/' . self::$identifierToToken[$identifier] . '/webinar/lobby',
			new TableNode([['state', $lobbyState]])
		);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @When /^user "([^"]*)" sets SIP state for room "([^"]*)" to "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $SIPStateString
	 * @param string $statusCode
	 * @param string $apiVersion
	 */
	public function userSetsSIPStateForRoomTo($user, $identifier, $SIPStateString, $statusCode, $apiVersion = 'v1') {
		if ($SIPStateString === 'disabled') {
			$SIPState = 0;
		} elseif ($SIPStateString === 'enabled') {
			$SIPState = 1;
		} else {
			Assert::fail('Invalid SIP state');
		}

		$this->setCurrentUser($user);
		$this->sendRequest(
			'PUT', '/apps/spreed/api/' . $apiVersion . '/room/' . self::$identifierToToken[$identifier] . '/webinar/sip',
			new TableNode([['state', $SIPState]])
		);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" makes room "([^"]*)" (public|private) with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $newType
	 * @param string $statusCode
	 * @param string $apiVersion
	 */
	public function userChangesTypeOfTheRoom($user, $identifier, $newType, $statusCode, $apiVersion = 'v1') {
		$this->setCurrentUser($user);
		$this->sendRequest(
			$newType === 'public' ? 'POST' : 'DELETE',
			'/apps/spreed/api/' . $apiVersion . '/room/' . self::$identifierToToken[$identifier] . '/public'
		);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" (locks|unlocks) room "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $newState
	 * @param string $identifier
	 * @param string $statusCode
	 * @param string $apiVersion
	 */
	public function userChangesReadOnlyStateOfTheRoom($user, $newState, $identifier, $statusCode, $apiVersion = 'v1') {
		$this->setCurrentUser($user);
		$this->sendRequest(
			'PUT', '/apps/spreed/api/' . $apiVersion . '/room/' . self::$identifierToToken[$identifier] . '/read-only',
			new TableNode([['state', $newState === 'unlocks' ? 0 : 1]])
		);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" allows listing room "([^"]*)" for "(none|users|all|\d+)" with (\d+)(?: \((v3)\))?$/
	 *
	 * @param string $user
	 * @param string $newState
	 * @param string $identifier
	 * @param string $statusCode
	 * @param string $apiVersion
	 */
	public function userChangesListableScopeOfTheRoom($user, $identifier, $newState, $statusCode, $apiVersion = 'v3') {
		$this->setCurrentUser($user);
		if ($newState === 'none') {
			$newStateValue = 0; // Room::LISTABLE_NONE
		} elseif ($newState === 'users') {
			$newStateValue = 1; // Room::LISTABLE_USERS
		} elseif ($newState === 'all') {
			$newStateValue = 2; // Room::LISTABLE_ALL
		} elseif (is_numeric($newState)) {
			$newStateValue = (int)$newState;
		} else {
			Assert::fail('Invalid listable scope value');
		}

		$this->sendRequest(
			'PUT', '/apps/spreed/api/' . $apiVersion . '/room/' . self::$identifierToToken[$identifier] . '/listable',
			new TableNode([['scope', $newStateValue]])
		);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" adds "([^"]*)" to room "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $newUser
	 * @param string $identifier
	 * @param string $statusCode
	 * @param string $apiVersion
	 */
	public function userAddUserToRoom($user, $newUser, $identifier, $statusCode, $apiVersion = 'v1') {
		$this->setCurrentUser($user);
		$this->sendRequest(
			'POST', '/apps/spreed/api/' . $apiVersion . '/room/' . self::$identifierToToken[$identifier] . '/participants',
			new TableNode([['newParticipant', $newUser]])
		);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" adds (user|group|email|circle) "([^"]*)" to room "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $newType
	 * @param string $newId
	 * @param string $identifier
	 * @param string $statusCode
	 * @param string $apiVersion
	 */
	public function userAddAttendeeToRoom($user, $newType, $newId, $identifier, $statusCode, $apiVersion = 'v1') {
		var_dump($newType);
		$this->setCurrentUser($user);
		$this->sendRequest(
			'POST', '/apps/spreed/api/' . $apiVersion . '/room/' . self::$identifierToToken[$identifier] . '/participants',
			new TableNode([
				['source', $newType . 's'],
				['newParticipant', $newId],
			])
		);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" (promotes|demotes) "([^"]*)" in room "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $isPromotion
	 * @param string $participant
	 * @param string $identifier
	 * @param string $statusCode
	 * @param string $apiVersion
	 */
	public function userPromoteDemoteInRoom($user, $isPromotion, $participant, $identifier, $statusCode, $apiVersion = 'v1') {
		$requestParameters = [['participant', $participant]];

		if (substr($participant, 0, strlen('guest')) === 'guest') {
			$sessionId = self::$userToSessionId[$participant];
			$requestParameters = [['sessionId', $sessionId]];
		}

		$this->setCurrentUser($user);
		$this->sendRequest(
			$isPromotion === 'promotes' ? 'POST' : 'DELETE',
			'/apps/spreed/api/' . $apiVersion . '/room/' . self::$identifierToToken[$identifier] . '/moderators',
			new TableNode($requestParameters)
		);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" joins call "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $statusCode
	 * @param string $apiVersion
	 * @param TableNode|null $formData
	 */
	public function userJoinsCall($user, $identifier, $statusCode, $apiVersion = 'v1', TableNode $formData = null) {
		$this->setCurrentUser($user);
		$this->sendRequest(
			'POST', '/apps/spreed/api/' . $apiVersion . '/call/' . self::$identifierToToken[$identifier],
			$formData
		);
		$this->assertStatusCode($this->response, $statusCode);

		$response = $this->getDataFromResponse($this->response);
		if (array_key_exists('sessionId', $response)) {
			// In the chat guest users are identified by their sessionId. The
			// sessionId is larger than the size of the actorId column in the
			// database, though, so the ID stored in the database and returned
			// in chat messages is a hashed version instead.
			self::$sessionIdToUser[sha1($response['sessionId'])] = $user;
			self::$userToSessionId[$user] = $response['sessionId'];
		}
	}

	/**
	 * @Then /^user "([^"]*)" leaves call "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $statusCode
	 * @param string $apiVersion
	 */
	public function userLeavesCall($user, $identifier, $statusCode, $apiVersion = 'v1') {
		$this->setCurrentUser($user);
		$this->sendRequest('DELETE', '/apps/spreed/api/' . $apiVersion . '/call/' . self::$identifierToToken[$identifier]);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" sees (\d+) peers in call "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $numPeers
	 * @param string $identifier
	 * @param string $statusCode
	 * @param string $apiVersion
	 */
	public function userSeesPeersInCall($user, $numPeers, $identifier, $statusCode, $apiVersion = 'v1') {
		$this->setCurrentUser($user);
		$this->sendRequest('GET', '/apps/spreed/api/' . $apiVersion . '/call/' . self::$identifierToToken[$identifier]);
		$this->assertStatusCode($this->response, $statusCode);

		if ($statusCode === '200') {
			$response = $this->getDataFromResponse($this->response);
			Assert::assertCount((int) $numPeers, $response);
		} else {
			Assert::assertEquals((int) $numPeers, 0);
		}
	}

	/**
	 * @Then /^user "([^"]*)" sends message "([^"]*)" to room "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $message
	 * @param string $identifier
	 * @param string $statusCode
	 * @param string $apiVersion
	 */
	public function userSendsMessageToRoom($user, $message, $identifier, $statusCode, $apiVersion = 'v1') {
		$this->setCurrentUser($user);
		$this->sendRequest(
			'POST', '/apps/spreed/api/' . $apiVersion . '/chat/' . self::$identifierToToken[$identifier],
			new TableNode([['message', $message]])
		);
		$this->assertStatusCode($this->response, $statusCode);
		sleep(1); // make sure Postgres manages the order of the messages

		$response = $this->getDataFromResponse($this->response);
		if (isset($response['id'])) {
			self::$messages[$message] = $response['id'];
		}
	}

	/**
	 * @Then /^user "([^"]*)" reads message "([^"]*)" in room "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $message
	 * @param string $identifier
	 * @param string $statusCode
	 * @param string $apiVersion
	 */
	public function userReadsMessageInRoom($user, $message, $identifier, $statusCode, $apiVersion = 'v1') {
		$this->setCurrentUser($user);
		$this->sendRequest(
			'POST', '/apps/spreed/api/' . $apiVersion . '/chat/' . self::$identifierToToken[$identifier] . '/read',
			new TableNode([['lastReadMessage', self::$messages[$message]]])
		);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" sends message "([^"]*)" with reference id "([^"]*)" to room "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $message
	 * @param string $referenceId
	 * @param string $identifier
	 * @param string $statusCode
	 * @param string $apiVersion
	 */
	public function userSendsMessageWithReferenceIdToRoom($user, $message, $referenceId, $identifier, $statusCode, $apiVersion = 'v1') {
		$this->setCurrentUser($user);
		$this->sendRequest(
			'POST', '/apps/spreed/api/' . $apiVersion . '/chat/' . self::$identifierToToken[$identifier],
			new TableNode([['message', $message], ['referenceId', $referenceId]])
		);
		$this->assertStatusCode($this->response, $statusCode);
		sleep(1); // make sure Postgres manages the order of the messages

		$response = $this->getDataFromResponse($this->response);
		if (isset($response['id'])) {
			self::$messages[$message] = $response['id'];
		}

		Assert::assertStringStartsWith($response['referenceId'], $referenceId);
	}

	/**
	 * @Then /^user "([^"]*)" sends reply "([^"]*)" on message "([^"]*)" to room "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $reply
	 * @param string $message
	 * @param string $identifier
	 * @param string $statusCode
	 * @param string $apiVersion
	 */
	public function userSendsReplyToRoom($user, $reply, $message, $identifier, $statusCode, $apiVersion = 'v1') {
		$replyTo = self::$messages[$message];

		$this->setCurrentUser($user);
		$this->sendRequest(
			'POST', '/apps/spreed/api/' . $apiVersion . '/chat/' . self::$identifierToToken[$identifier],
			new TableNode([['message', $reply], ['replyTo', $replyTo]])
		);
		$this->assertStatusCode($this->response, $statusCode);
		sleep(1); // make sure Postgres manages the order of the messages

		$response = $this->getDataFromResponse($this->response);
		if (isset($response['id'])) {
			self::$messages[$reply] = $response['id'];
		}
	}

	/**
	 * @Then /^user "([^"]*)" sees the following messages in room "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $statusCode
	 * @param string $apiVersion
	 */
	public function userSeesTheFollowingMessagesInRoom($user, $identifier, $statusCode, $apiVersion = 'v1', TableNode $formData = null) {
		$this->setCurrentUser($user);
		$this->sendRequest('GET', '/apps/spreed/api/' . $apiVersion . '/chat/' . self::$identifierToToken[$identifier] . '?lookIntoFuture=0');
		$this->assertStatusCode($this->response, $statusCode);

		$this->compareDataResponse($formData);
	}

	/**
	 * @Then /^user "([^"]*)" sees the following messages in room "([^"]*)" starting with "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $knwonMessage
	 * @param string $statusCode
	 * @param string $apiVersion
	 * @param TableNode|null $formData
	 */
	public function userAwaitsTheFollowingMessagesInRoom($user, $identifier, $knwonMessage, $statusCode, $apiVersion = 'v1', TableNode $formData = null) {
		$this->setCurrentUser($user);
		$this->sendRequest('GET', '/apps/spreed/api/' . $apiVersion . '/chat/' . self::$identifierToToken[$identifier] . '?lookIntoFuture=1&includeLastKnown=1&lastKnownMessageId=' . self::$messages[$knwonMessage]);
		$this->assertStatusCode($this->response, $statusCode);

		$this->compareDataResponse($formData);
	}

	/**
	 * @param TableNode|null $formData
	 */
	protected function compareDataResponse(TableNode $formData = null) {
		$actual = $this->getDataFromResponse($this->response);
		$messages = [];
		array_map(function (array $message) use (&$messages) {
			// Filter out system messages
			if ($message['systemMessage'] === '') {
				$messages[] = $message;
			}
		}, $actual);

		foreach ($messages as $message) {
			// Include the received messages in the list of messages used for
			// replies; this is needed to get special messages not explicitly
			// sent like those for shared files.
			self::$messages[$message['message']] = $message['id'];
		}

		if ($formData === null) {
			Assert::assertEmpty($messages);
			return;
		}
		$includeParents = in_array('parentMessage', $formData->getRow(0), true);
		$includeReferenceId = in_array('referenceId', $formData->getRow(0), true);

		$count = count($formData->getHash());
		Assert::assertCount($count, $messages, 'Message count does not match');
		for ($i = 0; $i < $count; $i++) {
			if ($formData->getHash()[$i]['messageParameters'] === '"IGNORE"') {
				$messages[$i]['messageParameters'] = 'IGNORE';
			}
		}
		Assert::assertEquals($formData->getHash(), array_map(function ($message) use ($includeParents, $includeReferenceId) {
			$data = [
				'room' => self::$tokenToIdentifier[$message['token']],
				'actorType' => $message['actorType'],
				'actorId' => ($message['actorType'] === 'guests')? self::$sessionIdToUser[$message['actorId']]: $message['actorId'],
				'actorDisplayName' => $message['actorDisplayName'],
				// TODO test timestamp; it may require using Runkit, php-timecop
				// or something like that.
				'message' => $message['message'],
				'messageParameters' => json_encode($message['messageParameters']),
			];
			if ($includeParents) {
				$data['parentMessage'] = $message['parent']['message'] ?? '';
			}
			if ($includeReferenceId) {
				$data['referenceId'] = $message['referenceId'];
			}
			return $data;
		}, $messages));
	}

	/**
	 * @Then /^user "([^"]*)" sees the following system messages in room "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $statusCode
	 * @param string $apiVersion
	 */
	public function userSeesTheFollowingSystemMessagesInRoom($user, $identifier, $statusCode, $apiVersion = 'v1', TableNode $formData = null) {
		$this->setCurrentUser($user);
		$this->sendRequest('GET', '/apps/spreed/api/' . $apiVersion . '/chat/' . self::$identifierToToken[$identifier] . '?lookIntoFuture=0');
		$this->assertStatusCode($this->response, $statusCode);

		$messages = $this->getDataFromResponse($this->response);
		$messages = array_filter($messages, function (array $message) {
			return $message['systemMessage'] !== '';
		});

		foreach ($messages as $systemMessage) {
			// Include the received system messages in the list of messages used
			// for replies.
			self::$messages[$systemMessage['systemMessage']] = $systemMessage['id'];
		}

		if ($formData === null) {
			Assert::assertEmpty($messages);
			return;
		}

		Assert::assertCount(count($formData->getHash()), $messages, 'Message count does not match');
		Assert::assertEquals($formData->getHash(), array_map(function ($message) {
			return [
				'room' => self::$tokenToIdentifier[$message['token']],
				'actorType' => (string) $message['actorType'],
				'actorId' => ($message['actorType'] === 'guests')? self::$sessionIdToUser[$message['actorId']]: (string) $message['actorId'],
				'actorDisplayName' => (string) $message['actorDisplayName'],
				'systemMessage' => (string) $message['systemMessage'],
			];
		}, $messages));
	}

	/**
	 * @Then /^user "([^"]*)" gets the following candidate mentions in room "([^"]*)" for "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $search
	 * @param string $statusCode
	 * @param string $apiVersion
	 * @param TableNode|null $formData
	 */
	public function userGetsTheFollowingCandidateMentionsInRoomFor($user, $identifier, $search, $statusCode, $apiVersion = 'v1', TableNode $formData = null) {
		$this->setCurrentUser($user);
		$this->sendRequest('GET', '/apps/spreed/api/' . $apiVersion . '/chat/' . self::$identifierToToken[$identifier] . '/mentions?search=' . $search);
		$this->assertStatusCode($this->response, $statusCode);

		$mentions = $this->getDataFromResponse($this->response);

		if ($formData === null) {
			Assert::assertEmpty($mentions);
			return;
		}

		Assert::assertCount(count($formData->getHash()), $mentions, 'Mentions count does not match');

		usort($mentions, function ($a, $b) {
			if ($a['source'] === $b['source']) {
				return $a['label'] <=> $b['label'];
			}
			return $a['source'] <=> $b['source'];
		});

		$expected = $formData->getHash();
		usort($expected, function ($a, $b) {
			if ($a['source'] === $b['source']) {
				return $a['label'] <=> $b['label'];
			}
			return $a['source'] <=> $b['source'];
		});

		foreach ($expected as $key => $row) {
			if ($row['id'] === 'GUEST_ID') {
				Assert::assertRegExp('/^guest\/[0-9a-f]{40}$/', $mentions[$key]['id']);
				$mentions[$key]['id'] = 'GUEST_ID';
			}
			Assert::assertEquals($row, $mentions[$key]);
		}
	}

	/**
	 * @Then /^guest "([^"]*)" sets name to "([^"]*)" in room "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $name
	 * @param string $identifier
	 * @param string $statusCode
	 * @param string $apiVersion
	 */
	public function guestSetsName($user, $name, $identifier, $statusCode, $apiVersion = 'v1') {
		$this->setCurrentUser($user);
		$this->sendRequest(
			'POST', '/apps/spreed/api/' . $apiVersion . '/guest/' . self::$identifierToToken[$identifier] . '/name',
			new TableNode([['displayName', $name]])
		);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^last response has (no) last common read message header$/
	 *
	 * @param string $no
	 */
	public function hasNoChatLastCommonReadHeader($no) {
		Assert::assertArrayNotHasKey('X-Chat-Last-Common-Read', $this->response->getHeaders(), 'X-Chat-Last-Common-Read is set to ' . ($this->response->getHeader('X-Chat-Last-Common-Read')[0] ?? '0'));
	}

	/**
	 * @Then /^last response has last common read message header (set to|less than) "([^"]*)"$/
	 *
	 * @param string $setOrLower
	 * @param string $message
	 */
	public function hasChatLastCommonReadHeader($setOrLower, $message) {
		Assert::assertArrayHasKey('X-Chat-Last-Common-Read', $this->response->getHeaders());
		if ($setOrLower === 'set to') {
			Assert::assertEquals(self::$messages[$message], $this->response->getHeader('X-Chat-Last-Common-Read')[0]);
		} else {
			// Less than might be required for the first message, because the last read message before is the join/room creation message and we don't know that ID
			Assert::assertLessThan(self::$messages[$message], $this->response->getHeader('X-Chat-Last-Common-Read')[0]);
		}
	}

	/**
	 * @Then /^user "([^"]*)" sets setting "([^"]*)" to "([^"]*)" with (\d+)(?: \((v(1|2|3))\))?$/
	 *
	 * @param string $user
	 * @param string $setting
	 * @param string $value
	 * @param string $statusCode
	 * @param string $apiVersion
	 */
	public function userSetting($user, $setting, $value, $statusCode, $apiVersion = 'v1') {
		$this->setCurrentUser($user);
		$this->sendRequest(
			'POST', '/apps/spreed/api/' . $apiVersion . '/settings/user',
			new TableNode([['key', $setting], ['value', $value]])
		);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" has capability "([^"]*)" set to "([^"]*)"$/
	 *
	 * @param string $user
	 * @param string $capability
	 * @param string $value
	 */
	public function userCheckCapability($user, $capability, $value) {
		$this->setCurrentUser($user);
		$this->sendRequest(
			'GET', '/cloud/capabilities'
		);


		$data = $this->getDataFromResponse($this->response);
		$capabilities = $data['capabilities'];

		$keys = explode('=>', $capability);
		$finalKey = array_pop($keys);
		$cur = $capabilities;

		foreach ($keys as $key) {
			Assert::assertArrayHasKey($key, $cur);
			$cur = $cur[$key];
		}
		Assert::assertEquals($value, $cur[$finalKey]);
	}

	/**
	 * Parses the xml answer to get the array of users returned.
	 * @param ResponseInterface $response
	 * @return array
	 */
	protected function getDataFromResponse(ResponseInterface $response) {
		$jsonBody = json_decode($response->getBody()->getContents(), true);
		return $jsonBody['ocs']['data'];
	}

	/**
	 * @Then /^status code is ([0-9]*)$/
	 *
	 * @param int $statusCode
	 */
	public function isStatusCode($statusCode) {
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Given /^the following app config is set$/
	 *
	 * @param TableNode $formData
	 */
	public function setAppConfig(TableNode $formData): void {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		foreach ($formData->getRows() as $row) {
			$this->sendRequest('POST', '/apps/provisioning_api/api/v1/config/apps/spreed/' . $row[0], [
				'value' => $row[1],
			]);
			$this->changedConfigs[] = $row[0];
		}
		$this->setCurrentUser($currentUser);
	}

	/**
	 * @Given /^guest accounts can be created$/
	 *
	 * @param TableNode $formData
	 */
	public function allowGuestAccountsCreation(): void {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');

		// save old state and restore at the end
		$this->sendRequest('GET', '/cloud/apps?filter=enabled');
		$this->assertStatusCode($this->response, 200);
		$data = $this->getDataFromResponse($this->response);
		$this->guestsAppWasEnabled = in_array('guests', $data['apps'], true);

		if (!$this->guestsAppWasEnabled) {
			// enable guests app
			/*
			$this->sendRequest('POST', '/cloud/apps/guests');
			$this->assertStatusCode($this->response, 200);
			 */
			// seems using provisioning API doesn't create tables...
			$this->runOcc(['app:enable', 'guests']);
		}

		// save previously set whitelist
		$this->sendRequest('GET', '/apps/provisioning_api/api/v1/config/apps/guests/whitelist');
		$this->assertStatusCode($this->response, 200);
		$this->guestsOldWhitelist = $this->getDataFromResponse($this->response)['data'];

		// set whitelist to allow spreed only
		$this->sendRequest('POST', '/apps/provisioning_api/api/v1/config/apps/guests/whitelist', [
			'value' => 'spreed',
		]);

		$this->setCurrentUser($currentUser);
	}

	/**
	 * @BeforeScenario
	 * @AfterScenario
	 */
	public function resetSpreedAppData() {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		$this->sendRequest('DELETE', '/apps/spreedcheats/');
		foreach ($this->changedConfigs as $config) {
			$this->sendRequest('DELETE', '/apps/provisioning_api/api/v1/config/apps/spreed/' . $config);
		}

		$this->setCurrentUser($currentUser);
	}

	/**
	 * @AfterScenario
	 */
	public function resetGuestsAppState() {
		if ($this->guestsAppWasEnabled === null) {
			// guests app was not touched
			return;
		}

		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');

		if ($this->guestsOldWhitelist) {
			// restore old whitelist
			$this->sendRequest('POST', '/apps/provisioning_api/api/v1/config/apps/guests/whitelist', [
				'value' => $this->guestsOldWhitelist,
			]);
		} else {
			// restore to default
			$this->sendRequest('DELETE', '/apps/provisioning_api/api/v1/config/apps/guests/whitelist');
		}

		// restore app's enabled state
		$this->sendRequest($this->guestsAppWasEnabled ? 'POST' : 'DELETE', '/cloud/apps/guests');

		$this->setCurrentUser($currentUser);
		$this->guestsAppWasEnabled = null;
	}

	/*
	 * User management
	 */

	/**
	 * @Given /^as user "([^"]*)"$/
	 * @param string $user
	 */
	public function setCurrentUser($user) {
		$this->currentUser = $user;
	}

	/**
	 * @Given /^user "([^"]*)" exists$/
	 * @param string $user
	 */
	public function assureUserExists($user) {
		$response = $this->userExists($user);
		if ($response->getStatusCode() !== 200) {
			$this->createUser($user);
			// Set a display name different than the user ID to be able to
			// ensure in the tests that the right value was returned.
			$this->setUserDisplayName($user);
			$response = $this->userExists($user);
			$this->assertStatusCode($response, 200);
		}
	}

	/**
	 * @Given /^user "([^"]*)" is a guest account user/
	 * @param string $email email address
	 */
	public function createGuestUser($email) {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		// in case it exists
		$this->deleteUser($email);

		$lastCode = $this->runOcc([
			'guests:add',
			// creator user
			'admin',
			// email
			$email,
			'--display-name',
			$email . '-displayname',
			'--password-from-env',
		], [
			'OC_PASS' => self::TEST_PASSWORD,
		]);
		Assert::assertEquals(0, $lastCode, 'Guest creation succeeded for ' . $email);

		$this->createdGuestAccountUsers[] = $email;
		$this->setCurrentUser($currentUser);
	}

	private function userExists($user) {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		$this->sendRequest('GET', '/cloud/users/' . $user);
		$this->setCurrentUser($currentUser);
		return $this->response;
	}

	private function createUser($user) {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		$this->sendRequest('POST', '/cloud/users', [
			'userid' => $user,
			'password' => self::TEST_PASSWORD,
		]);
		$this->assertStatusCode($this->response, 200, 'Failed to create user');

		//Quick hack to login once with the current user
		$this->setCurrentUser($user);
		$this->sendRequest('GET', '/cloud/users' . '/' . $user);
		$this->assertStatusCode($this->response, 200, 'Failed to do first login');

		$this->createdUsers[] = $user;

		$this->setCurrentUser($currentUser);
	}

	/**
	 * @Given /^user "([^"]*)" is deleted$/
	 * @param string $user
	 */
	public function userIsDeleted($user) {
		$deleted = false;

		$this->deleteUser($user);

		$response = $this->userExists($user);
		$deleted = $response->getStatusCode() === 404;

		if (!$deleted) {
			Assert::fail("User $user exists");
		}
	}

	private function deleteUser($user) {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		$this->sendRequest('DELETE', '/cloud/users/' . $user);
		$this->setCurrentUser($currentUser);

		unset($this->createdUsers[array_search($user, $this->createdUsers, true)]);

		return $this->response;
	}

	private function setUserDisplayName($user) {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		$this->sendRequest('PUT', '/cloud/users/' . $user, [
			'key' => 'displayname',
			'value' => $user . '-displayname'
		]);
		$this->setCurrentUser($currentUser);
	}

	/**
	 * @Given /^group "([^"]*)" exists$/
	 * @param string $group
	 */
	public function assureGroupExists($group) {
		$response = $this->groupExists($group);
		if ($response->getStatusCode() !== 200) {
			$this->createGroup($group);
			$response = $this->groupExists($group);
			$this->assertStatusCode($response, 200);
		}
	}

	private function groupExists($group) {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		$this->sendRequest('GET', '/cloud/groups/' . $group);
		$this->setCurrentUser($currentUser);
		return $this->response;
	}

	private function createGroup($group) {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		$this->sendRequest('POST', '/cloud/groups', [
			'groupid' => $group,
		]);
		$this->setCurrentUser($currentUser);

		$this->createdGroups[] = $group;
	}

	private function deleteGroup($group) {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		$this->sendRequest('DELETE', '/cloud/groups/' . $group);
		$this->setCurrentUser($currentUser);

		unset($this->createdGroups[array_search($group, $this->createdGroups, true)]);
	}

	/**
	 * @When /^user "([^"]*)" is member of group "([^"]*)"$/
	 * @param string $user
	 * @param string $group
	 */
	public function addingUserToGroup($user, $group) {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		$this->sendRequest('POST', "/cloud/users/$user/groups", [
			'groupid' => $group,
		]);
		$this->assertStatusCode($this->response, 200);
		$this->setCurrentUser($currentUser);
	}

	/*
	 * Requests
	 */

	/**
	 * @Given /^user "([^"]*)" logs in$/
	 * @param string $user
	 */
	public function userLogsIn(string $user) {
		$loginUrl = $this->baseUrl . '/login';

		$cookieJar = $this->getUserCookieJar($user);

		// Request a new session and extract CSRF token
		$client = new Client();
		$this->response = $client->get(
			$loginUrl,
			[
				'cookies' => $cookieJar,
			]
		);

		$this->extractRequestTokenFromResponse($this->response);

		// Login and extract new token
		$password = ($user === 'admin') ? 'admin' : self::TEST_PASSWORD;
		$client = new Client();
		$this->response = $client->post(
			$loginUrl,
			[
				'form_params' => [
					'user' => $user,
					'password' => $password,
					'requesttoken' => $this->requestToken,
				],
				'cookies' => $cookieJar,
			]
		);
		$this->extractRequestTokenFromResponse($this->response);

		$this->assertStatusCode($this->response, 200);

		$this->loggedInUser = $user;
	}

	/**
	 * @param ResponseInterface $response
	 */
	private function extractRequestTokenFromResponse(ResponseInterface $response): void {
		$this->requestToken = substr(preg_replace('/(.*)data-requesttoken="(.*)">(.*)/sm', '\2', $response->getBody()->getContents()), 0, 89);
	}

	/**
	 * @When /^sending "([^"]*)" to "([^"]*)" with request token$/
	 * @param string $verb
	 * @param string $url
	 * @param TableNode|array|null $body
	 */
	public function sendingToWithRequestToken(string $verb, string $url, $body = null) {
		$fullUrl = $this->baseUrl . $url;

		$options = [
			'cookies' => $this->getUserCookieJar($this->loggedInUser),
			'headers' => [
				'requesttoken' => $this->requestToken
			],
		];

		if ($body instanceof TableNode) {
			$fd = $body->getRowsHash();
			$options['form_params'] = $fd;
		} elseif ($body) {
			$options = array_merge($options, $body);
		}

		$client = new Client();
		try {
			$this->response = $client->request(
				$verb,
				$fullUrl,
				$options
			);
		} catch (ClientException $e) {
			$this->response = $e->getResponse();
		}
	}

	/**
	 * @When /^sending "([^"]*)" to "([^"]*)" with$/
	 * @param string $verb
	 * @param string $url
	 * @param TableNode|array|null $body
	 * @param array $headers
	 */
	public function sendRequest($verb, $url, $body = null, array $headers = []) {
		$fullUrl = $this->baseUrl . 'ocs/v2.php' . $url;
		$client = new Client();
		$options = ['cookies' => $this->getUserCookieJar($this->currentUser)];
		if ($this->currentUser === 'admin') {
			$options['auth'] = ['admin', 'admin'];
		} elseif (strpos($this->currentUser, 'guest') !== 0) {
			$options['auth'] = [$this->currentUser, self::TEST_PASSWORD];
		}
		if ($body instanceof TableNode) {
			$fd = $body->getRowsHash();
			$options['form_params'] = $fd;
		} elseif (is_array($body) && array_key_exists('multipart', $body)) {
			$options = array_merge($options, $body);
		} elseif (is_array($body)) {
			$options['form_params'] = $body;
		}

		$options['headers'] = array_merge($headers, [
			'OCS-ApiRequest' => 'true',
			'Accept' => 'application/json',
		]);

		try {
			$this->response = $client->{$verb}($fullUrl, $options);
		} catch (ClientException $ex) {
			$this->response = $ex->getResponse();
		}
	}

	protected function getUserCookieJar($user) {
		if (!isset($this->cookieJars[$user])) {
			$this->cookieJars[$user] = new CookieJar();
		}
		return $this->cookieJars[$user];
	}

	/**
	 * @param ResponseInterface $response
	 * @param int $statusCode
	 * @param string $message
	 */
	protected function assertStatusCode(ResponseInterface $response, int $statusCode, string $message = '') {
		Assert::assertEquals($statusCode, $response->getStatusCode(), $message);
	}

	/**
	 * @Then /^the following headers should be set$/
	 * @param TableNode $table
	 * @throws \Exception
	 */
	public function theFollowingHeadersShouldBeSet(TableNode $table) {
		foreach ($table->getTable() as $header) {
			$headerName = $header[0];
			$expectedHeaderValue = $header[1];
			$returnedHeader = $this->response->getHeader($headerName)[0];
			if ($returnedHeader !== $expectedHeaderValue) {
				throw new \Exception(
					sprintf(
						"Expected value '%s' for header '%s', got '%s'",
						$expectedHeaderValue,
						$headerName,
						$returnedHeader
					)
				);
			}
		}
	}
}
