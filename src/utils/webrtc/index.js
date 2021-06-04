/**
 *
 * @copyright Copyright (c) 2019, Daniel Calviño Sánchez (danxuliu@gmail.com)
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

import './shims/MediaStream'
import './shims/MediaStreamTrack'
import Axios from '@nextcloud/axios'
import CancelableRequest from '../cancelableRequest'
import Signaling from '../signaling'
import initWebRtc from './webrtc'
import CallAnalyzer from './analyzers/CallAnalyzer'
import CallParticipantCollection from './models/CallParticipantCollection'
import LocalCallParticipantModel from './models/LocalCallParticipantModel'
import LocalMediaModel from './models/LocalMediaModel'
import MediaDevicesManager from './MediaDevicesManager'
import SentVideoQualityThrottler from './SentVideoQualityThrottler'
import { PARTICIPANT } from '../../constants'
import { fetchSignalingSettings } from '../../services/signalingService'

let webRtc = null
const callParticipantCollection = new CallParticipantCollection()
const localCallParticipantModel = new LocalCallParticipantModel()
const localMediaModel = new LocalMediaModel()
const mediaDevicesManager = new MediaDevicesManager()
let callAnalyzer = null
let sentVideoQualityThrottler = null

let cancelFetchSignalingSettings = null
let signaling = null
let tokensInSignaling = {}

async function getSignalingSettings(token) {
	// If getSignalingSettings is called again while a previous one was still
	// being executed the previous one is cancelled.
	if (cancelFetchSignalingSettings) {
		cancelFetchSignalingSettings('canceled')
		cancelFetchSignalingSettings = null
	}

	const { request, cancel } = CancelableRequest(fetchSignalingSettings)
	cancelFetchSignalingSettings = cancel

	let settings = null
	try {
		const response = await request({ token })
		settings = response.data.ocs.data

		settings.token = token

		cancelFetchSignalingSettings = null
	} catch (exception) {
		if (Axios.isCancel(exception)) {
			console.debug('Getting the signaling settings for ' + token + ' was cancelled by a newer getSignalingSettings')
		} else {
			console.warn('Failed to get the signaling settings for ' + token)
		}
	}

	return settings
}

async function connectSignaling(token) {
	const settings = await getSignalingSettings(token)
	if (!settings) {
		return
	}

	if (signaling && signaling.settings.server !== settings.server) {
		if (webRtc) {
			webRtc.disconnect()
			webRtc = null
		}
		signaling.disconnect()
		signaling = null

		tokensInSignaling = {}
	}

	if (!signaling) {
		signaling = Signaling.createConnection(settings)
	}

	tokensInSignaling[token] = true
}

let pendingJoinCallToken = null
let startedCall = null
let failedToStartCall = null

function startCall(signaling, configuration) {
	let flags = PARTICIPANT.CALL_FLAG.IN_CALL
	if (configuration) {
		if (configuration.audio) {
			flags |= PARTICIPANT.CALL_FLAG.WITH_AUDIO
		}
		if (configuration.video && signaling.getSendVideoIfAvailable()) {
			flags |= PARTICIPANT.CALL_FLAG.WITH_VIDEO
		}
	}

	signaling.joinCall(pendingJoinCallToken, flags).then(() => {
		startedCall(flags)
	}).catch(error => {
		failedToStartCall(error)
	})
}

function setupWebRtc() {
	if (webRtc) {
		return
	}

	webRtc = initWebRtc(signaling, callParticipantCollection, localCallParticipantModel)
	localCallParticipantModel.setWebRtc(webRtc)
	localMediaModel.setWebRtc(webRtc)
}

/**
 * Join the given conversation on the respective signaling server with the given sessionId
 *
 * @param {string} token Conversation to join
 * @param {string} sessionId Session id to join with
 * @returns {Promise<void>}
 */
async function signalingJoinConversation(token, sessionId) {
	await connectSignaling(token)
	if (tokensInSignaling[token]) {
		await signaling.joinRoom(token, sessionId)
	}
}

/**
 * Join the call of the given conversation
 *
 * @param {string} token Conversation to join the call
 * @param {int} flags Bitwise combination of PARTICIPANT.CALL_FLAG
 * @returns {Promise<void>} Resolved with the actual flags based on the
 *          available media
 */
async function signalingJoinCall(token, flags) {
	if (tokensInSignaling[token]) {
		pendingJoinCallToken = token

		setupWebRtc()

		sentVideoQualityThrottler = new SentVideoQualityThrottler(localMediaModel, callParticipantCollection)

		if (signaling.hasFeature('mcu')) {
			callAnalyzer = new CallAnalyzer(localMediaModel, localCallParticipantModel, callParticipantCollection)
		} else {
			callAnalyzer = new CallAnalyzer(localMediaModel, null, callParticipantCollection)
		}

		const _signaling = signaling

		return new Promise((resolve, reject) => {
			startedCall = resolve
			failedToStartCall = reject

			// The previous state might be wiped after the media is started, so
			// it should be saved now.
			const enableAudio = !localStorage.getItem('audioDisabled_' + token)
			const enableVideo = !localStorage.getItem('videoDisabled_' + token)

			const startCallOnceLocalMediaStarted = (configuration) => {
				webRtc.off('localMediaStarted', startCallOnceLocalMediaStarted)
				webRtc.off('localMediaError', startCallOnceLocalMediaError)

				if (enableAudio) {
					localMediaModel.enableAudio()
				} else {
					localMediaModel.disableAudio()
				}
				if (enableVideo) {
					localMediaModel.enableVideo()
				} else {
					localMediaModel.disableVideo()
				}

				startCall(_signaling, configuration)
			}
			const startCallOnceLocalMediaError = () => {
				webRtc.off('localMediaStarted', startCallOnceLocalMediaStarted)
				webRtc.off('localMediaError', startCallOnceLocalMediaError)

				startCall(_signaling, null)
			}

			// ".once" can not be used, as both handlers need to be removed when
			// just one of them is executed.
			webRtc.on('localMediaStarted', startCallOnceLocalMediaStarted)
			webRtc.on('localMediaError', startCallOnceLocalMediaError)

			webRtc.startMedia(token, flags)
		})
	}
}

/**
 * Leave the call of the given conversation
 *
 * @param {string} token Conversation to leave the call
 * @returns {Promise<void>}
 */
async function signalingLeaveCall(token) {
	sentVideoQualityThrottler.destroy()
	sentVideoQualityThrottler = null

	callAnalyzer.destroy()
	callAnalyzer = null

	if (tokensInSignaling[token]) {
		await signaling.leaveCall(token)
	}
}

/**
 * Leave the given conversation on the respective signaling server
 *
 * @param {string} token Conversation to leave
 * @returns {Promise<void>}
 */
async function signalingLeaveConversation(token) {
	if (tokensInSignaling[token]) {
		await signaling.leaveRoom(token)
	}
}

/**
 * Immediately kill the signaling connection synchronously
 * This should be called only in the unload handler
 */
function signalingKill() {
	if (signaling) {
		signaling.disconnect()
	}
}

export {
	callParticipantCollection,
	localCallParticipantModel,
	localMediaModel,

	mediaDevicesManager,

	callAnalyzer,

	signalingJoinConversation,
	signalingJoinCall,
	signalingLeaveCall,
	signalingLeaveConversation,
	signalingKill,
}
