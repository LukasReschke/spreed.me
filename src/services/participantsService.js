/**
 * @copyright Copyright (c) 2019 Marco Ambrosini <marcoambrosini@pm.me>
 *
 * @author Marco Ambrosini <marcoambrosini@pm.me>
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
import {
	generateOcsUrl,
} from '@nextcloud/router'
import {
	signalingJoinConversation,
	signalingLeaveConversation,
} from '../utils/webrtc/index'

/**
 * Joins the current user to a conversation specified with
 * the token.
 *
 * @param {string} token The conversation token;
 * @param {options} options request options;
 * @param {bool} forceJoin whether to force join;
 */
const joinConversation = async({ token, forceJoin = false }, options) => {
	const response = await axios.post(generateOcsUrl('apps/spreed/api/v4/room/{token}/participants/active', { token }), {
		force: forceJoin,
	}, options)

	// FIXME Signaling should not be synchronous
	await signalingJoinConversation(token, response.data.ocs.data.sessionId)

	return response
}

/**
 * Joins the current user to a conversation specified with
 * the token.
 *
 * @param {string} token The conversation token;
 */
const rejoinConversation = async(token) => {
	return axios.post(generateOcsUrl('apps/spreed/api/v4/room/{token}/participants/active', { token }))
}

/**
 * Leaves the conversation specified with the token.
 *
 * @param {string} token The conversation token;
 */
const leaveConversation = async function(token) {
	try {
		// FIXME Signaling should not be synchronous
		await signalingLeaveConversation(token)

		const response = await axios.delete(generateOcsUrl('apps/spreed/api/v4/room/{token}/participants/active', { token }))
		return response
	} catch (error) {
		console.debug(error)
		// FIXME: should throw
	}
}

/**
 * Leaves the conversation specified with the token.
 *
 * @param {string} token The conversation token;
 */
const leaveConversationSync = function(token) {
	axios.delete(generateOcsUrl('apps/spreed/api/v4/room/{token}/participants/active', { token }))
}

/**
 * Add a participant to a conversation.
 * @param {token} token the conversation token.
 * @param {string} newParticipant the id of the new participant
 * @param {string} source the source Source of the participant as returned by the autocomplete suggestion endpoint (default is users)
 */
const addParticipant = async function(token, newParticipant, source) {
	const response = await axios.post(generateOcsUrl('apps/spreed/api/v4/room/{token}/participants', { token }), {
		newParticipant,
		source,
	})
	return response
}

/**
 * Removes the the current user from the conversation specified with the token.
 *
 * @param {string} token The conversation token;
 */
const removeCurrentUserFromConversation = async function(token) {
	const response = await axios.delete(generateOcsUrl('apps/spreed/api/v4/room/{token}/participants/self', { token }))
	return response
}

const removeAttendeeFromConversation = async function(token, attendeeId) {
	const response = await axios.delete(generateOcsUrl('apps/spreed/api/v4/room/{token}/attendees', { token }), {
		params: {
			attendeeId,
		},
	})
	return response
}

const promoteToModerator = async(token, options) => {
	const response = await axios.post(generateOcsUrl('apps/spreed/api/v4/room/{token}/moderators', { token }), options)
	return response
}

const demoteFromModerator = async(token, options) => {
	const response = await axios.delete(generateOcsUrl('apps/spreed/api/v4/room/{token}/moderators', { token }), {
		params: options,
	})
	return response
}

const setPublishingPermissions = async(token, options) => {
	const response = await axios.put(generateOcsUrl('apps/spreed/api/v4/room/{token}/attendees/publishing-permissions', { token }), options)
	return response
}

const fetchParticipants = async(token, options) => {
	options = options || {}
	options.params = options.params || {}
	options.params.includeStatus = true
	const response = await axios.get(generateOcsUrl('apps/spreed/api/v4/room/{token}/participants', { token }), options)
	return response
}

const setGuestUserName = async(token, userName) => {
	const response = await axios.post(generateOcsUrl('apps/spreed/api/v1/guest/{token}/name', { token }), {
		displayName: userName,
	})
	return response
}

/**
 * Resends email invitations for the given conversation.
 * If no userId is set, send to all applicable participants.
 *
 * @param {string} token conversation token
 * @param {int} attendeeId attendee id to target, or null for all
 */
const resendInvitations = async(token, { attendeeId = null }) => {
	await axios.post(generateOcsUrl('apps/spreed/api/v4/room/{token}/participants/resend-invitations', { token }), {
		attendeeId,
	})
}

export {
	joinConversation,
	rejoinConversation,
	leaveConversation,
	leaveConversationSync,
	addParticipant,
	removeCurrentUserFromConversation,
	removeAttendeeFromConversation,
	promoteToModerator,
	demoteFromModerator,
	setPublishingPermissions,
	fetchParticipants,
	setGuestUserName,
	resendInvitations,
}
