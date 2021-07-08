<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2016 Lukas Reschke <lukas@statuscode.ch>
 * @copyright Copyright (c) 2016 Joas Schilling <coding@schilljs.com>
 *
 * @author Lukas Reschke <lukas@statuscode.ch>
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

use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Session;
use OCA\Talk\Participant;
use OCA\Talk\Service\ParticipantService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;

class CallController extends AEnvironmentAwareController {

	/** @var ParticipantService */
	private $participantService;
	/** @var IUserManager */
	private $userManager;
	/** @var ITimeFactory */
	private $timeFactory;

	public function __construct(string $appName,
								IRequest $request,
								ParticipantService $participantService,
								IUserManager $userManager,
								ITimeFactory $timeFactory) {
		parent::__construct($appName, $request);
		$this->participantService = $participantService;
		$this->userManager = $userManager;
		$this->timeFactory = $timeFactory;
	}

	/**
	 * @PublicPage
	 * @RequireParticipant
	 * @RequireReadWriteConversation
	 * @RequireModeratorOrNoLobby
	 *
	 * @return DataResponse
	 */
	public function getPeersForCall(): DataResponse {
		$timeout = $this->timeFactory->getTime() - 30;
		$result = [];
		$participants = $this->participantService->getParticipantsInCall($this->room, $timeout);

		foreach ($participants as $participant) {
			$displayName = $participant->getAttendee()->getActorId();
			if ($participant->getAttendee()->getActorType() === Attendee::ACTOR_USERS) {
				if ($participant->getAttendee()->getDisplayName()) {
					$displayName = $participant->getAttendee()->getDisplayName();
				} else {
					$user = $this->userManager->get($participant->getAttendee()->getActorId());
					if ($user instanceof IUser) {
						$displayName = $user->getDisplayName();
					}
				}
			} else {
				$displayName = $participant->getAttendee()->getDisplayName();
			}

			$result[] = [
				'actorType' => $participant->getAttendee()->getActorType(),
				'actorId' => $participant->getAttendee()->getActorId(),
				'displayName' => $displayName,
				'token' => $this->room->getToken(),
				'lastPing' => $participant->getSession()->getLastPing(),
				'sessionId' => $participant->getSession()->getSessionId(),
			];
		}

		return new DataResponse($result);
	}

	/**
	 * @PublicPage
	 * @RequireParticipant
	 * @RequireReadWriteConversation
	 * @RequireModeratorOrNoLobby
	 *
	 * @param int|null $flags
	 * @return DataResponse
	 */
	public function joinCall(?int $flags): DataResponse {
		$this->participantService->ensureOneToOneRoomIsFilled($this->room);

		$session = $this->participant->getSession();
		if (!$session instanceof Session) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		if ($flags === null) {
			// Default flags: user is in room with audio/video.
			$flags = Participant::FLAG_IN_CALL | Participant::FLAG_WITH_AUDIO | Participant::FLAG_WITH_VIDEO;
		}

		$this->participantService->changeInCall($this->room, $this->participant, $flags);

		return new DataResponse();
	}

	/**
	 * @PublicPage
	 * @RequireParticipant
	 *
	 * @param int flags
	 * @return DataResponse
	 */
	public function updateCallFlags(int $flags): DataResponse {
		$session = $this->participant->getSession();
		if (!$session instanceof Session) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$this->participantService->updateCallFlags($this->room, $this->participant, $flags);
		} catch (\Exception $exception) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse();
	}

	/**
	 * @PublicPage
	 * @RequireParticipant
	 *
	 * @param bool $all whether to also terminate the call for all participants
	 * @return DataResponse
	 */
	public function leaveCall(bool $all): DataResponse {
		$session = $this->participant->getSession();
		if (!$session instanceof Session) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		if ($all) {
			$participants = $this->participantService->getParticipantsInCall($this->room);

			// kick out all participants out of the call
			foreach ($participants as $participant) {
				$this->participantService->changeInCall($this->room, $participant, Participant::FLAG_DISCONNECTED);
			}
		} else {
			$this->participantService->changeInCall($this->room, $this->participant, Participant::FLAG_DISCONNECTED);
		}

		return new DataResponse();
	}
}
