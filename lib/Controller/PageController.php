<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2016 Lukas Reschke <lukas@statuscode.ch>
 *
 * @author Lukas Reschke <lukas@statuscode.ch>
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

use OC\HintException;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Config;
use OCA\Talk\Manager;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\TalkSession;
use OCA\Talk\TInitialState;
use OCA\Viewer\Event\LoadViewer;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

class PageController extends Controller {
	use TInitialState;

	/** @var string|null */
	private $userId;
	/** @var IEventDispatcher */
	private $eventDispatcher;
	/** @var RoomController */
	private $api;
	/** @var TalkSession */
	private $talkSession;
	/** @var IUserSession */
	private $userSession;
	/** @var LoggerInterface */
	private $logger;
	/** @var Manager */
	private $manager;
	/** @var IURLGenerator */
	private $url;
	/** @var INotificationManager */
	private $notificationManager;
	/** @var IAppManager */
	private $appManager;
	/** @var IRootFolder */
	private $rootFolder;

	public function __construct(string $appName,
								IRequest $request,
								IEventDispatcher $eventDispatcher,
								RoomController $api,
								TalkSession $session,
								IUserSession $userSession,
								?string $UserId,
								LoggerInterface $logger,
								Manager $manager,
								IURLGenerator $url,
								INotificationManager $notificationManager,
								IAppManager $appManager,
								IInitialState $initialState,
								ICacheFactory $memcacheFactory,
								IRootFolder $rootFolder,
								Config $talkConfig,
								IConfig $serverConfig) {
		parent::__construct($appName, $request);
		$this->eventDispatcher = $eventDispatcher;
		$this->api = $api;
		$this->talkSession = $session;
		$this->userSession = $userSession;
		$this->userId = $UserId;
		$this->logger = $logger;
		$this->manager = $manager;
		$this->url = $url;
		$this->notificationManager = $notificationManager;
		$this->appManager = $appManager;
		$this->initialState = $initialState;
		$this->memcacheFactory = $memcacheFactory;
		$this->rootFolder = $rootFolder;
		$this->talkConfig = $talkConfig;
		$this->serverConfig = $serverConfig;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 *
	 * @param string $token
	 * @return Response
	 * @throws HintException
	 */
	public function showCall(string $token): Response {
		// This is the entry point from the `/call/{token}` URL which is hardcoded in the server.
		return $this->index($token);
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 *
	 * @param string $token
	 * @param string $password
	 * @return Response
	 * @throws HintException
	 */
	public function authenticatePassword(string $token, string $password = ''): Response {
		// This is the entry point from the `/call/{token}` URL which is hardcoded in the server.
		return $this->index($token, '', $password);
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @return Response
	 */
	public function notFound(): Response {
		return $this->index();
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @return Response
	 */
	public function duplicateSession(): Response {
		return $this->index();
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 *
	 * @param string $token
	 * @param string $callUser
	 * @param string $password
	 * @return TemplateResponse|RedirectResponse
	 * @throws HintException
	 */
	public function index(string $token = '', string $callUser = '', string $password = ''): Response {
		$user = $this->userSession->getUser();
		if (!$user instanceof IUser) {
			return $this->guestEnterRoom($token, $password);
		}

		if ($token !== '') {
			$room = null;
			try {
				$room = $this->manager->getRoomByToken($token, $this->userId);
				$notification = $this->notificationManager->createNotification();
				$shouldFlush = $this->notificationManager->defer();
				try {
					$notification->setApp('spreed')
						->setUser($this->userId)
						->setObject('room', $room->getToken());
					$this->notificationManager->markProcessed($notification);
					$notification->setObject('call', $room->getToken());
					$this->notificationManager->markProcessed($notification);
				} catch (\InvalidArgumentException $e) {
					$this->logger->error($e->getMessage(), ['exception' => $e]);
				}

				if ($shouldFlush) {
					$this->notificationManager->flush();
				}

				// If the room is not a public room, check if the user is in the participants
				if ($room->getType() !== Room::PUBLIC_CALL) {
					$this->manager->getRoomForUser($room->getId(), $this->userId);
				}
			} catch (RoomNotFoundException $e) {
				// Room not found, redirect to main page
				$token = '';
			}

			if ($room instanceof Room && $room->hasPassword()) {
				// If the user joined themselves or is not found, they need the password.
				try {
					$participant = $room->getParticipant($this->userId, false);
					$requirePassword = $participant->getAttendee()->getParticipantType() === Participant::USER_SELF_JOINED;
				} catch (ParticipantNotFoundException $e) {
					$requirePassword = true;
				}

				if ($requirePassword) {
					$password = $password !== '' ? $password : (string) $this->talkSession->getPasswordForRoom($token);

					$passwordVerification = $room->verifyPassword($password);

					if ($passwordVerification['result']) {
						$this->talkSession->renewSessionId();
						$this->talkSession->setPasswordForRoom($token, $password);
					} else {
						$this->talkSession->removePasswordForRoom($token);
						if ($passwordVerification['url'] === '') {
							return new TemplateResponse($this->appName, 'authenticate', [
								'wrongpw' => $password !== '',
							], 'guest');
						}

						return new RedirectResponse($passwordVerification['url']);
					}
				}
			}
		} else {
			$response = $this->api->createRoom(Room::ONE_TO_ONE_CALL, $callUser);
			if ($response->getStatus() === Http::STATUS_OK
				|| $response->getStatus() === Http::STATUS_CREATED) {
				$data = $response->getData();
				return $this->redirectToConversation($data['token']);
			}
		}

		$this->publishInitialStateForUser($user, $this->rootFolder, $this->appManager);

		if (class_exists(LoadViewer::class)) {
			$this->eventDispatcher->dispatchTyped(new LoadViewer());
		}

		$this->eventDispatcher->dispatch('\OCP\Collaboration\Resources::loadAdditionalScripts', new GenericEvent());
		$response = new TemplateResponse($this->appName, 'index');
		$csp = new ContentSecurityPolicy();
		$csp->allowEvalScript(true); // FIXME: TEMP
		$csp->addAllowedConnectDomain('*');
		$csp->addAllowedMediaDomain('blob:');
		$csp->addAllowedWorkerSrcDomain('blob:');
		$csp->addAllowedWorkerSrcDomain("'self'");
		$csp->addAllowedChildSrcDomain('blob:');
		$csp->addAllowedChildSrcDomain("'self'");
		$csp->addAllowedScriptDomain('blob:');
		$csp->addAllowedScriptDomain("'self'");
		$csp->addAllowedConnectDomain('blob:');
		$csp->addAllowedConnectDomain("'self'");
		$csp->addAllowedImageDomain('https://*.tile.openstreetmap.org');
		$response->setContentSecurityPolicy($csp);
		return $response;
	}

	/**
	 * @param string $token
	 * @param string $password
	 * @return TemplateResponse|RedirectResponse
	 * @throws HintException
	 */
	protected function guestEnterRoom(string $token, string $password): Response {
		try {
			$room = $this->manager->getRoomByToken($token);
			if ($room->getType() !== Room::PUBLIC_CALL) {
				throw new RoomNotFoundException();
			}
		} catch (RoomNotFoundException $e) {
			$redirectUrl = $this->url->linkToRoute('spreed.Page.index');
			if ($token) {
				$redirectUrl = $this->url->linkToRoute('spreed.Page.showCall', ['token' => $token]);
			}
			return new RedirectResponse($this->url->linkToRoute('core.login.showLoginForm', [
				'redirect_url' => $redirectUrl,
			]));
		}

		if ($room->hasPassword()) {
			$password = $password !== '' ? $password : (string) $this->talkSession->getPasswordForRoom($token);

			$passwordVerification = $room->verifyPassword($password);
			if ($passwordVerification['result']) {
				$this->talkSession->renewSessionId();
				$this->talkSession->setPasswordForRoom($token, $password);
			} else {
				$this->talkSession->removePasswordForRoom($token);
				if ($passwordVerification['url'] === '') {
					return new TemplateResponse($this->appName, 'authenticate', [
						'wrongpw' => $password !== '',
					], 'guest');
				}

				return new RedirectResponse($passwordVerification['url']);
			}
		}

		$this->publishInitialStateForGuest();

		$response = new PublicTemplateResponse($this->appName, 'index');
		$response->setFooterVisible(false);
		$csp = new ContentSecurityPolicy();
		$csp->allowEvalScript(true); // FIXME: TEMP
		$csp->addAllowedConnectDomain('*');
		$csp->addAllowedMediaDomain('blob:');
		$csp->addAllowedWorkerSrcDomain('blob:');
		$csp->addAllowedWorkerSrcDomain("'self'");
		$csp->addAllowedChildSrcDomain('blob:');
		$csp->addAllowedChildSrcDomain("'self'");
		$csp->addAllowedScriptDomain('blob:');
		$csp->addAllowedScriptDomain("'self'");
		$csp->addAllowedConnectDomain('blob:');
		$csp->addAllowedConnectDomain("'self'");
		$csp->addAllowedImageDomain('https://*.tile.openstreetmap.org');
		$response->setContentSecurityPolicy($csp);
		return $response;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $token
	 * @return RedirectResponse
	 */
	protected function redirectToConversation(string $token): RedirectResponse {
		// These redirects are already done outside of this method
		if ($this->userId === null) {
			try {
				$room = $this->manager->getRoomByToken($token);
				if ($room->getType() !== Room::PUBLIC_CALL) {
					throw new RoomNotFoundException();
				}
				return new RedirectResponse($this->url->linkToRoute('spreed.Page.showCall', ['token' => $token]));
			} catch (RoomNotFoundException $e) {
				return new RedirectResponse($this->url->linkToRoute('core.login.showLoginForm', [
					'redirect_url' => $this->url->linkToRoute('spreed.Page.showCall', ['token' => $token]),
				]));
			}
		}
		return new RedirectResponse($this->url->linkToRoute('spreed.Page.showCall', ['token' => $token]));
	}
}
