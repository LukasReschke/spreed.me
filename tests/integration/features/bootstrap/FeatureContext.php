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

	/** @var string[] */
	protected static $identifierToToken;
	/** @var string[] */
	protected static $tokenToIdentifier;
	/** @var array[] */
	protected static $sessionIdToUser;
	/** @var array[] */
	protected static $userToSessionId;
	/** @var array[] */
	protected static $messages;

	/** @var string */
	protected $currentUser;

	/** @var ResponseInterface */
	private $response;

	/** @var CookieJar[] */
	private $cookieJars;

	/** @var string */
	protected $baseUrl;

	/** @var string */
	protected $lastEtag;

	/** @var array */
	protected $createdUsers = [];

	/** @var array */
	protected $createdGroups = [];

	/** @var array */
	protected $changedConfigs = [];

	/** @var SharingContext */
	private $sharingContext;

	public static function getTokenForIdentifier(string $identifier) {
		return self::$identifierToToken[$identifier];
	}

	/**
	 * FeatureContext constructor.
	 */
	public function __construct() {
		$this->cookieJars = [];
		$this->baseUrl = getenv('TEST_SERVER_URL');
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
	}

	/**
	 * @Then /^user "([^"]*)" is participant of the following rooms$/
	 *
	 * @param string $user
	 * @param TableNode|null $formData
	 */
	public function userIsParticipantOfRooms($user, TableNode $formData = null) {
		$this->setCurrentUser($user);
		$this->sendRequest('GET', '/apps/spreed/api/v1/room');
		$this->assertStatusCode($this->response, 200);

		$rooms = $this->getDataFromResponse($this->response);

		$rooms = array_filter($rooms, function($room) {
			return $room['type'] !== 4 && $room['type'] !== 5;
		});

		if ($formData === null) {
			Assert::assertEmpty($rooms);
			return;
		}

		Assert::assertCount(count($formData->getHash()), $rooms, 'Room count does not match');
		Assert::assertEquals($formData->getHash(), array_map(function($room, $expectedRoom) {
			$participantNames = array_map(function($participant) {
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

			return [
				'id' => self::$tokenToIdentifier[$room['token']],
				'type' => (string) $room['type'],
				'participantType' => (string) $room['participantType'],
				'participants' => implode(', ', $participantNames),
			];
		}, $rooms, $formData->getHash()));
	}

	/**
	 * @Then /^user "([^"]*)" has (the|no) notes conversation$/
	 *
	 * @param string $user
	 * @param string $hasConversation
	 */
	public function userHasNotesConversation(string $user, string $hasConversation): void {
		$this->setCurrentUser($user);
		$this->sendRequest('GET', '/apps/spreed/api/v1/room');
		$this->assertStatusCode($this->response, 200);

		$rooms = $this->getDataFromResponse($this->response);

		$rooms = array_filter($rooms, function($room) {
			return $room['type'] === 5;
		});

		$notesIndex = $user . '-notes';
		if ($hasConversation === 'the') {
			Assert::assertCount(1, $rooms);
			$room = array_pop($rooms);

			self::$identifierToToken[$notesIndex] = $room['token'];
			self::$tokenToIdentifier[$room['token']] = $notesIndex;
		} else {
			Assert::assertEmpty($rooms);

			if (isset(self::$identifierToToken[$notesIndex])) {
				$notesToken = self::$identifierToToken[$notesIndex];
				unset(
					self::$tokenToIdentifier[$notesToken],
					self::$identifierToToken[$notesIndex]
				);
			}
		}
	}

	/**
	 * @Then /^user "([^"]*)" (is|is not) participant of room "([^"]*)"$/
	 *
	 * @param string $user
	 * @param string $isOrNotParticipant
	 * @param string $identifier
	 */
	public function userIsParticipantOfRoom($user, $isOrNotParticipant, $identifier) {
		if (strpos($user, 'guest') === 0) {
			$this->guestIsParticipantOfRoom($user, $isOrNotParticipant, $identifier);

			return;
		}

		$this->setCurrentUser($user);
		$this->sendRequest('GET', '/apps/spreed/api/v1/room');
		$this->assertStatusCode($this->response, 200);

		$isParticipant = $isOrNotParticipant === 'is';

		$rooms = $this->getDataFromResponse($this->response);

		$rooms = array_filter($rooms, function($room) {
			return $room['type'] !== 4 && $room['type'] !== 5;
		});

		if ($isParticipant) {
			Assert::assertNotEmpty($rooms);
		}

		foreach ($rooms as $room) {
			if (self::$tokenToIdentifier[$room['token']] === $identifier) {
				Assert::assertEquals($isParticipant, true, 'Room ' . $identifier . ' found in user´s room list');
				return;
			}
		}

		Assert::assertEquals($isParticipant, false, 'Room ' . $identifier . ' not found in user´s room list');
	}

	/**
	 * @param string $guest
	 * @param string $isOrNotParticipant
	 * @param string $identifier
	 */
	private function guestIsParticipantOfRoom($guest, $isOrNotParticipant, $identifier) {
		$this->setCurrentUser($guest);
		$this->sendRequest('GET', '/apps/spreed/api/v1/room/' . self::$identifierToToken[$identifier]);

		$response = $this->getDataFromResponse($this->response);

		$isParticipant = $isOrNotParticipant === 'is';

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
	 * @Then /^user "([^"]*)" sets setting "([^"]*)" to "([^"]*)" with (\d+)$/
	 *
	 * @param string $user
	 * @param string $setting
	 * @param string $value
	 * @param int $statusCode
	 */
	public function userSetsSetting(string $user, string $setting, string $value, int $statusCode): void {
		$this->setCurrentUser($user);
		$this->sendRequest('POST', '/apps/spreed/api/v1/settings/user', [
			'key' => $setting,
			'value' => $value,
		]);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" creates room "([^"]*)"$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param TableNode|null $formData
	 */
	public function userCreatesRoom($user, $identifier, TableNode $formData = null) {
		$this->userCreatesRoomWith($user, $identifier, 201, $formData);
	}

	/**
	 * @Then /^user "([^"]*)" creates room "([^"]*)" with (\d+)$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param int $statusCode
	 * @param TableNode|null $formData
	 */
	public function userCreatesRoomWith($user, $identifier, $statusCode, TableNode $formData = null) {
		$this->setCurrentUser($user);
		$this->sendRequest('POST', '/apps/spreed/api/v1/room', $formData);
		$this->assertStatusCode($this->response, $statusCode);

		$response = $this->getDataFromResponse($this->response);

		if ($statusCode === 201) {
			self::$identifierToToken[$identifier] = $response['token'];
			self::$tokenToIdentifier[$response['token']] = $identifier;
		}
	}

	/**
	 * @Then /^user "([^"]*)" tries to create room with (\d+)$/
	 *
	 * @param string $user
	 * @param int $statusCode
	 * @param TableNode|null $formData
	 */
	public function userTriesToCreateRoom($user, $statusCode, TableNode $formData = null) {
		$this->setCurrentUser($user);
		$this->sendRequest('POST', '/apps/spreed/api/v1/room', $formData);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" gets the room for path "([^"]*)" with (\d+)$/
	 *
	 * @param string $user
	 * @param string $path
	 * @param int $statusCode
	 */
	public function userGetsTheRoomForPath($user, $path, $statusCode) {
		$fileId = $this->getFileIdForPath($user, $path);

		$this->setCurrentUser($user);
		$this->sendRequest('GET', '/apps/spreed/api/v1/file/' . $fileId);
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
			$options['auth'] = [$this->currentUser, '123456'];
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
	 * @Then /^user "([^"]*)" gets the room for last share with (\d+)$/
	 *
	 * @param string $user
	 * @param int $statusCode
	 */
	public function userGetsTheRoomForLastShare($user, $statusCode) {
		$shareToken = $this->sharingContext->getLastShareToken();

		$this->setCurrentUser($user);
		$this->sendRequest('GET', '/apps/spreed/api/v1/publicshare/' . $shareToken);
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
	 * @Then /^user "([^"]*)" joins room "([^"]*)" with (\d+)$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $statusCode
	 * @param TableNode|null $formData
	 */
	public function userJoinsRoom($user, $identifier, $statusCode, TableNode $formData = null) {
		$this->setCurrentUser($user);
		$this->sendRequest(
			'POST', '/apps/spreed/api/v1/room/' . self::$identifierToToken[$identifier] . '/participants/active',
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
	 * @Then /^user "([^"]*)" leaves room "([^"]*)" with (\d+)$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $statusCode
	 */
	public function userExitsRoom($user, $identifier, $statusCode) {
		$this->setCurrentUser($user);
		$this->sendRequest('DELETE', '/apps/spreed/api/v1/room/' . self::$identifierToToken[$identifier] . '/participants/active');
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" removes themselves from room "([^"]*)" with (\d+)$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $statusCode
	 */
	public function userLeavesRoom($user, $identifier, $statusCode) {
		$this->setCurrentUser($user);
		$this->sendRequest('DELETE', '/apps/spreed/api/v1/room/' . self::$identifierToToken[$identifier] . '/participants/self');
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" removes "([^"]*)" from room "([^"]*)" with (\d+)$/
	 *
	 * @param string $user
	 * @param string $toRemove
	 * @param string $identifier
	 * @param string $statusCode
	 */
	public function userRemovesUserFromRoom($user, $toRemove, $identifier, $statusCode) {
		$this->setCurrentUser($user);
		$this->sendRequest(
			'DELETE', '/apps/spreed/api/v1/room/' . self::$identifierToToken[$identifier] . '/participants',
			new TableNode([['participant', $toRemove]])
		);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" deletes room "([^"]*)" with (\d+)$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $statusCode
	 */
	public function userDeletesRoom($user, $identifier, $statusCode) {
		$this->setCurrentUser($user);
		$this->sendRequest('DELETE', '/apps/spreed/api/v1/room/' . self::$identifierToToken[$identifier]);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" renames room "([^"]*)" to "([^"]*)" with (\d+)$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $newName
	 * @param string $statusCode
	 */
	public function userRenamesRoom($user, $identifier, $newName, $statusCode) {
		$this->setCurrentUser($user);
		$this->sendRequest(
			'PUT', '/apps/spreed/api/v1/room/' . self::$identifierToToken[$identifier],
			new TableNode([['roomName', $newName]])
		);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @When /^user "([^"]*)" sets password "([^"]*)" for room "([^"]*)" with (\d+)$/
	 *
	 * @param string $user
	 * @param string $password
	 * @param string $identifier
	 * @param string $statusCode
	 * @param TableNode
	 */
	public function userSetsTheRoomPassword($user, $password, $identifier, $statusCode) {
		$this->setCurrentUser($user);
		$this->sendRequest(
			'PUT', '/apps/spreed/api/v1/room/' . self::$identifierToToken[$identifier] . '/password',
			new TableNode([['password', $password]])
		);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @When /^user "([^"]*)" sets lobby state for room "([^"]*)" to "([^"]*)" with (\d+)$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $lobbyState
	 * @param string $statusCode
	 * @param TableNode
	 */
	public function userSetsLobbyStateForRoomTo($user, $identifier, $lobbyStateString, $statusCode) {
		if ($lobbyStateString === 'no lobby') {
			$lobbyState = 0;
		} else if ($lobbyStateString === 'non moderators') {
			$lobbyState = 1;
		} else {
			Assert::fail('Invalid lobby state');
			return;
		}

		$this->setCurrentUser($user);
		$this->sendRequest(
			'PUT', '/apps/spreed/api/v1/room/' . self::$identifierToToken[$identifier] . '/webinary/lobby',
			new TableNode([['state', $lobbyState]])
		);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" makes room "([^"]*)" (public|private) with (\d+)$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $newType
	 * @param string $statusCode
	 */
	public function userChangesTypeOfTheRoom($user, $identifier, $newType, $statusCode) {
		$this->setCurrentUser($user);
		$this->sendRequest(
			$newType === 'public' ? 'POST' : 'DELETE',
			'/apps/spreed/api/v1/room/' . self::$identifierToToken[$identifier] . '/public'
		);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" (locks|unlocks) room "([^"]*)" with (\d+)$/
	 *
	 * @param string $user
	 * @param string $newState
	 * @param string $identifier
	 * @param string $statusCode
	 */
	public function userChangesReadOnlyStateOfTheRoom($user, $newState, $identifier, $statusCode) {
		$this->setCurrentUser($user);
		$this->sendRequest(
			'PUT', '/apps/spreed/api/v1/room/' . self::$identifierToToken[$identifier] . '/read-only',
			new TableNode([['state', $newState === 'unlocks' ? 0 : 1]])
		);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" adds "([^"]*)" to room "([^"]*)" with (\d+)$/
	 *
	 * @param string $user
	 * @param string $newUser
	 * @param string $identifier
	 * @param string $statusCode
	 */
	public function userAddUserToRoom($user, $newUser, $identifier, $statusCode) {
		$this->setCurrentUser($user);
		$this->sendRequest(
			'POST', '/apps/spreed/api/v1/room/' . self::$identifierToToken[$identifier] . '/participants',
			new TableNode([['newParticipant', $newUser]])
		);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" (promotes|demotes) "([^"]*)" in room "([^"]*)" with (\d+)$/
	 *
	 * @param string $user
	 * @param string $isPromotion
	 * @param string $participant
	 * @param string $identifier
	 * @param string $statusCode
	 */
	public function userPromoteDemoteInRoom($user, $isPromotion, $participant, $identifier, $statusCode) {
		$requestParameters = [['participant', $participant]];

		if (substr($participant, 0, strlen('guest')) === 'guest') {
			$sessionId = self::$userToSessionId[$participant];
			$requestParameters = [['sessionId', $sessionId]];
		}

		$this->setCurrentUser($user);
		$this->sendRequest(
			$isPromotion === 'promotes' ? 'POST' : 'DELETE',
			'/apps/spreed/api/v1/room/' . self::$identifierToToken[$identifier] . '/moderators',
			new TableNode($requestParameters)
		);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" joins call "([^"]*)" with (\d+)$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $statusCode
	 * @param TableNode|null $formData
	 */
	public function userJoinsCall($user, $identifier, $statusCode, TableNode $formData = null) {
		$this->setCurrentUser($user);
		$this->sendRequest(
			'POST', '/apps/spreed/api/v1/call/' . self::$identifierToToken[$identifier],
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
	 * @Then /^user "([^"]*)" leaves call "([^"]*)" with (\d+)$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $statusCode
	 */
	public function userLeavesCall($user, $identifier, $statusCode) {
		$this->setCurrentUser($user);
		$this->sendRequest('DELETE', '/apps/spreed/api/v1/call/' . self::$identifierToToken[$identifier]);
		$this->assertStatusCode($this->response, $statusCode);
	}

	/**
	 * @Then /^user "([^"]*)" sees (\d+) peers in call "([^"]*)" with (\d+)$/
	 *
	 * @param string $user
	 * @param string $numPeers
	 * @param string $identifier
	 * @param string $statusCode
	 */
	public function userSeesPeersInCall($user, $numPeers, $identifier, $statusCode) {
		$this->setCurrentUser($user);
		$this->sendRequest('GET', '/apps/spreed/api/v1/call/' . self::$identifierToToken[$identifier]);
		$this->assertStatusCode($this->response, $statusCode);

		if ($statusCode === '200') {
			$response = $this->getDataFromResponse($this->response);
			Assert::assertCount((int) $numPeers, $response);
		} else {
			Assert::assertEquals((int) $numPeers, 0);
		}
	}

	/**
	 * @Then /^user "([^"]*)" sends message "([^"]*)" to room "([^"]*)" with (\d+)$/
	 *
	 * @param string $user
	 * @param string $message
	 * @param string $identifier
	 * @param string $statusCode
	 */
	public function userSendsMessageToRoom($user, $message, $identifier, $statusCode) {
		$this->setCurrentUser($user);
		$this->sendRequest(
			'POST', '/apps/spreed/api/v1/chat/' . self::$identifierToToken[$identifier],
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
	 * @Then /^user "([^"]*)" sends message "([^"]*)" with reference id "([^"]*)" to room "([^"]*)" with (\d+)$/
	 *
	 * @param string $user
	 * @param string $message
	 * @param string $referenceId
	 * @param string $identifier
	 * @param string $statusCode
	 */
	public function userSendsMessageWithReferenceIdToRoom($user, $message, $referenceId, $identifier, $statusCode) {
		$this->setCurrentUser($user);
		$this->sendRequest(
			'POST', '/apps/spreed/api/v1/chat/' . self::$identifierToToken[$identifier],
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
	 * @Then /^user "([^"]*)" sends reply "([^"]*)" on message "([^"]*)" to room "([^"]*)" with (\d+)$/
	 *
	 * @param string $user
	 * @param string $reply
	 * @param string $message
	 * @param string $identifier
	 * @param string $statusCode
	 */
	public function userSendsReplyToRoom($user, $reply, $message, $identifier, $statusCode) {
		$replyTo = self::$messages[$message];

		$this->setCurrentUser($user);
		$this->sendRequest(
			'POST', '/apps/spreed/api/v1/chat/' . self::$identifierToToken[$identifier],
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
	 * @Then /^user "([^"]*)" sees the following messages in room "([^"]*)" with (\d+)$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $statusCode
	 */
	public function userSeesTheFollowingMessagesInRoom($user, $identifier, $statusCode, TableNode $formData = null) {
		$this->setCurrentUser($user);
		$this->sendRequest('GET', '/apps/spreed/api/v1/chat/' . self::$identifierToToken[$identifier] . '?lookIntoFuture=0');
		$this->assertStatusCode($this->response, $statusCode);

		$actual = $this->getDataFromResponse($this->response);
		$messages = [];
		array_map(function(array $message) use (&$messages) {
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
		Assert::assertEquals($formData->getHash(), array_map(function($message) use($includeParents, $includeReferenceId) {
			$data = [
				'room' => self::$tokenToIdentifier[$message['token']],
				'actorType' => $message['actorType'],
				'actorId' => ($message['actorType'] === 'guests')? self::$sessionIdToUser[$message['actorId']]: $message['actorId'],
				'actorDisplayName' => $message['actorDisplayName'],
				// TODO test timestamp; it may require using Runkit, php-timecop
				// or something like that.
				'message' => str_replace("\n", '\n', $message['message']),
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
	 * @Then /^user "([^"]*)" sees the following system messages in room "([^"]*)" with (\d+)$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $statusCode
	 */
	public function userSeesTheFollowingSystemMessagesInRoom($user, $identifier, $statusCode, TableNode $formData = null) {
		$this->setCurrentUser($user);
		$this->sendRequest('GET', '/apps/spreed/api/v1/chat/' . self::$identifierToToken[$identifier] . '?lookIntoFuture=0');
		$this->assertStatusCode($this->response, $statusCode);

		$messages = $this->getDataFromResponse($this->response);
		$messages = array_filter($messages, function(array $message) {
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
		Assert::assertEquals($formData->getHash(), array_map(function($message) {
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
	 * @Then /^user "([^"]*)" gets the following candidate mentions in room "([^"]*)" for "([^"]*)" with (\d+)$/
	 *
	 * @param string $user
	 * @param string $identifier
	 * @param string $search
	 * @param string $statusCode
	 * @param TableNode|null $formData
	 */
	public function userGetsTheFollowingCandidateMentionsInRoomFor($user, $identifier, $search, $statusCode, TableNode $formData = null) {
		$this->setCurrentUser($user);
		$this->sendRequest('GET', '/apps/spreed/api/v1/chat/' . self::$identifierToToken[$identifier] . '/mentions?search=' . $search);
		$this->assertStatusCode($this->response, $statusCode);

		$mentions = $this->getDataFromResponse($this->response);

		if ($formData === null) {
			Assert::assertEmpty($mentions);
			return;
		}

		Assert::assertCount(count($formData->getHash()), $mentions, 'Mentions count does not match');

		foreach ($formData->getHash() as $key => $row) {
			if ($row['id'] === 'GUEST_ID') {
				Assert::assertRegExp('/^guest\/[0-9a-f]{40}$/', $mentions[$key]['id']);
				$mentions[$key]['id'] = 'GUEST_ID';
			}
			Assert::assertEquals($row, $mentions[$key]);
		}
	}

	/**
	 * @Then /^guest "([^"]*)" sets name to "([^"]*)" in room "([^"]*)" with (\d+)$/
	 *
	 * @param string $user
	 * @param string $name
	 * @param string $identifier
	 * @param string $statusCode
	 */
	public function guestSetsName($user, $name, $identifier, $statusCode) {
		$this->setCurrentUser($user);
		$this->sendRequest(
			'POST', '/apps/spreed/api/v1/guest/' . self::$identifierToToken[$identifier] . '/name',
			new TableNode([['displayName', $name]])
		);
		$this->assertStatusCode($this->response, $statusCode);
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
		}

		// Set a display name different than the user ID to be able to
		// ensure in the tests that the right value was returned.
		$this->setUserDisplayName($user);
		$response = $this->userExists($user);
		$this->assertStatusCode($response, 200);
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
			'password' => '123456'
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
		$this->response = $this->sendRequest('POST', "/cloud/users/$user/groups", [
			'groupid' => $group,
		]);
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

		$requestToken = $this->extractRequestTokenFromResponse($this->response);

		// Login and extract new token
		$password = ($user === 'admin') ? 'admin' : '123456';
		$client = new Client();
		$this->response = $client->post(
			$loginUrl,
			[
				'form_params' => [
					'user' => $user,
					'password' => $password,
					'requesttoken' => $requestToken,
				],
				'cookies' => $cookieJar,
			]
		);

		$this->assertStatusCode($this->response, 200);
	}

	/**
	 * @param ResponseInterface $response
	 * @return string
	 */
	private function extractRequestTokenFromResponse(ResponseInterface $response): string {
		return substr(preg_replace('/(.*)data-requesttoken="(.*)">(.*)/sm', '\2', $response->getBody()->getContents()), 0, 89);
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
		$options = ['cookies'  => $this->getUserCookieJar($this->currentUser)];
		if ($this->currentUser === 'admin') {
			$options['auth'] = ['admin', 'admin'];
		} else if (strpos($this->currentUser, 'guest') !== 0) {
			$options['auth'] = [$this->currentUser, '123456'];
		}
		if ($body instanceof TableNode) {
			$fd = $body->getRowsHash();
			$options['form_params'] = $fd;
		} else if (is_array($body)) {
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
}
