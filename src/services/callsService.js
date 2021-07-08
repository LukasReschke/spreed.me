/**
 * @copyright Copyright (c) 2019 Joas Schilling <coding@schilljs.com>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import {
	signalingJoinCall,
	signalingLeaveCall,
} from '../utils/webrtc/index'

/**
 * Join a call as participant
 *
 * The flags constrain the media to send when joining the call. If no flags are
 * provided both audio and video are available. Otherwise only the specified
 * media will be allowed to be sent.
 *
 * Note that the flags are constraints, but not requirements. Only the specified
 * media is allowed to be sent, but it is not guaranteed to be sent. For
 * example, if WITH_VIDEO is provided but the device does not have a camera.
 *
 * @param {string} token The token of the call to be joined.
 * @param {int} flags The available PARTICIPANT.CALL_FLAG for this participants
 * @returns {int} The actual flags based on the available media
 */
const joinCall = async function(token, flags) {
	try {
		return await signalingJoinCall(token, flags)
	} catch (error) {
		console.debug('Error while joining call: ', error)
	}
}

/**
 * Leave a call as participant
 * @param {string} token The token of the call to be left
 * @param {bool} all Whether to end the meeting for all
 */
const leaveCall = async function(token, all = false) {
	try {
		await signalingLeaveCall(token, all)
	} catch (error) {
		console.debug('Error while leaving call: ', error)
	}
}

const fetchPeers = async function(token, options) {
	const response = await axios.get(generateOcsUrl('apps/spreed/api/v4/call/{token}', { token }), options)
	return response
}

export {
	joinCall,
	leaveCall,
	fetchPeers,
}
