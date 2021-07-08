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
import Vue from 'vue'
import {
	promoteToModerator,
	demoteFromModerator,
	removeAttendeeFromConversation,
	resendInvitations,
	joinConversation,
	leaveConversation,
	removeCurrentUserFromConversation,
} from '../services/participantsService'
import {
	generateUrl,
} from '@nextcloud/router'
import {
	joinCall,
	leaveCall,
} from '../services/callsService'
import SessionStorage from '../services/SessionStorage'
import { PARTICIPANT } from '../constants'
import { EventBus } from '../services/EventBus'
import { showError } from '@nextcloud/dialogs'

const state = {
	participants: {
	},
	peers: {
	},
	inCall: {
	},
	connecting: {
	},
}

const getters = {
	isInCall: (state) => (token) => {
		return !!(state.inCall[token] && Object.keys(state.inCall[token]).length > 0)
	},
	isConnecting: (state) => (token) => {
		return !!(state.connecting[token] && Object.keys(state.connecting[token]).length > 0)
	},
	/**
	 * Gets the participants array
	 * @param {object} state the state object.
	 * @returns {array} the participants array (if there are participants in the store)
	 */
	participantsList: (state) => (token) => {
		if (state.participants[token]) {
			return state.participants[token]
		}
		return []
	},
	getParticipant: (state) => (token, index) => {
		if (state.participants[token] && state.participants[token][index]) {
			return state.participants[token][index]
		}
		return {}
	},
	getParticipantIndex: (state) => (token, participantIdentifier) => {
		if (!state.participants[token]) {
			return -1
		}

		let index

		if (Object.prototype.hasOwnProperty.call(participantIdentifier, 'attendeeId')) {
			index = state.participants[token].findIndex(participant => participant.attendeeId === participantIdentifier.attendeeId)
		} else if (Object.prototype.hasOwnProperty.call(participantIdentifier, 'actorId') && Object.prototype.hasOwnProperty.call(participantIdentifier, 'actorType')) {
			index = state.participants[token].findIndex(participant => participant.actorId === participantIdentifier.actorId && participant.actorType === participantIdentifier.actorType)
		} else {
			index = state.participants[token].findIndex(participant => participant.sessionId === participantIdentifier.sessionId)
		}

		return index
	},
	getPeer: (state) => (token, sessionId) => {
		if (!state.peers[token]) {
			return {}
		}

		if (Object.prototype.hasOwnProperty.call(state.peers[token], sessionId)) {
			return state.peers[token][sessionId]
		}

		return {}
	},
}

const mutations = {
	/**
	 * Adds a message to the store.
	 * @param {object} state current store state;
	 * @param {object} token the token of the conversation;
	 * @param {object} participant the participant;
	 */
	addParticipant(state, { token, participant }) {
		if (!state.participants[token]) {
			Vue.set(state.participants, token, [])
		}
		state.participants[token].push(participant)
	},
	updateParticipant(state, { token, index, updatedData }) {
		if (state.participants[token] && state.participants[token][index]) {
			state.participants[token][index] = Object.assign(state.participants[token][index], updatedData)
		} else {
			console.error('Error while updating the participant')
		}
	},
	deleteParticipant(state, { token, index }) {
		if (state.participants[token] && state.participants[token][index]) {
			Vue.delete(state.participants[token], index)
		} else {
			console.error('The conversation you are trying to purge doesn\'t exist')
		}
	},
	setInCall(state, { token, sessionId, flags }) {
		if (flags === PARTICIPANT.CALL_FLAG.DISCONNECTED) {
			if (state.inCall[token] && state.inCall[token][sessionId]) {
				Vue.delete(state.inCall[token], sessionId)
			}

			if (state.connecting[token] && state.connecting[token][sessionId]) {
				Vue.delete(state.connecting[token], sessionId)
			}
		} else {
			if (!state.inCall[token]) {
				Vue.set(state.inCall, token, {})
			}
			Vue.set(state.inCall[token], sessionId, flags)

			if (!state.connecting[token]) {
				Vue.set(state.connecting, token, {})
			}
			Vue.set(state.connecting[token], sessionId, flags)
		}
	},
	finishedConnecting(state, { token, sessionId }) {
		if (state.connecting[token] && state.connecting[token][sessionId]) {
			Vue.delete(state.connecting[token], sessionId)
		}
	},
	/**
	 * Purges a given conversation from the previously added participants
	 * @param {object} state current store state;
	 * @param {string} token the conversation to purge;
	 */
	purgeParticipantsStore(state, token) {
		if (state.participants[token]) {
			Vue.delete(state.participants, token)
		}
	},

	addPeer(state, { token, peer }) {
		if (!state.peers[token]) {
			Vue.set(state.peers, token, [])
		}
		Vue.set(state.peers[token], peer.sessionId, peer)
	},
	purgePeersStore(state, token) {
		if (state.peers[token]) {
			Vue.delete(state.peers, token)
		}
	},
}

const actions = {

	/**
	 * Adds participant to the store.
	 *
	 * Only call this after purgeParticipantsStore, otherwise use addParticipantOnce
	 *
	 * @param {object} context default store context;
	 * @param {string} token the conversation to add the participant;
	 * @param {object} participant the participant;
	 */
	addParticipant({ commit }, { token, participant }) {
		commit('addParticipant', { token, participant })
	},
	/**
	 * Only add a participant when they are not there yet
	 *
	 * @param {object} context default store context;
	 * @param {string} token the conversation to add the participant;
	 * @param {object} participant the participant;
	 */
	addParticipantOnce({ commit, getters }, { token, participant }) {
		const index = getters.getParticipantIndex(token, participant)
		if (index === -1) {
			commit('addParticipant', { token, participant })
		}
	},
	async promoteToModerator({ commit, getters }, { token, attendeeId }) {
		const index = getters.getParticipantIndex(token, { attendeeId })
		if (index === -1) {
			return
		}

		await promoteToModerator(token, {
			attendeeId,
		})

		const participant = getters.getParticipant(token, index)
		// FIXME: don't promote already promoted or read resulting type from server response
		const updatedData = {
			participantType: participant.participantType === PARTICIPANT.TYPE.GUEST ? PARTICIPANT.TYPE.GUEST_MODERATOR : PARTICIPANT.TYPE.MODERATOR,
		}
		commit('updateParticipant', { token, index, updatedData })
	},
	async demoteFromModerator({ commit, getters }, { token, attendeeId }) {
		const index = getters.getParticipantIndex(token, { attendeeId })
		if (index === -1) {
			return
		}

		await demoteFromModerator(token, {
			attendeeId,
		})

		const participant = getters.getParticipant(token, index)
		// FIXME: don't demote already demoted, use server response instead
		const updatedData = {
			participantType: participant.participantType === PARTICIPANT.TYPE.GUEST_MODERATOR ? PARTICIPANT.TYPE.GUEST : PARTICIPANT.TYPE.USER,
		}
		commit('updateParticipant', { token, index, updatedData })
	},
	async removeParticipant({ commit, getters }, { token, attendeeId }) {
		const index = getters.getParticipantIndex(token, { attendeeId })
		if (index === -1) {
			return
		}

		await removeAttendeeFromConversation(token, attendeeId)
		commit('deleteParticipant', { token, index })
	},
	/**
	 * Purges a given conversation from the previously added participants
	 * @param {object} context default store context;
	 * @param {string} token the conversation to purge;
	 */
	purgeParticipantsStore({ commit }, token) {
		commit('purgeParticipantsStore', token)
	},

	addPeer({ commit }, { token, peer }) {
		commit('addPeer', { token, peer })
	},
	purgePeersStore({ commit }, token) {
		commit('purgePeersStore', token)
	},

	updateSessionId({ commit, getters }, { token, participantIdentifier, sessionId }) {
		const index = getters.getParticipantIndex(token, participantIdentifier)
		if (index === -1) {
			console.error('Participant not found', participantIdentifier)
			return
		}

		const updatedData = {
			sessionId,
			inCall: PARTICIPANT.CALL_FLAG.DISCONNECTED,
		}
		commit('updateParticipant', { token, index, updatedData })
	},

	updateUser({ commit, getters }, { token, participantIdentifier, updatedData }) {
		const index = getters.getParticipantIndex(token, participantIdentifier)
		if (index === -1) {
			console.error('Participant not found', participantIdentifier)
			return
		}

		commit('updateParticipant', { token, index, updatedData })
	},

	async joinCall({ commit, getters }, { token, participantIdentifier, flags }) {
		if (!participantIdentifier?.sessionId) {
			console.error('Trying to join call without sessionId')
			return
		}

		const index = getters.getParticipantIndex(token, participantIdentifier)
		if (index === -1) {
			console.error('Participant not found', participantIdentifier)
			return
		}

		commit('setInCall', {
			token,
			sessionId: participantIdentifier.sessionId,
			flags,
		})

		const actualFlags = await joinCall(token, flags)

		const updatedData = {
			inCall: actualFlags,
		}
		commit('updateParticipant', { token, index, updatedData })

		EventBus.$once('Signaling::usersInRoom', () => {
			commit('finishedConnecting', { token, sessionId: participantIdentifier.sessionId })
		})

		setTimeout(() => {
			// If by accident we never receive a users list, just switch to
			// "Waiting for others to join the call …" after some seconds.
			commit('finishedConnecting', { token, sessionId: participantIdentifier.sessionId })
		}, 10000)
	},

	async leaveCall({ commit, getters }, { token, participantIdentifier, all = false }) {
		if (!participantIdentifier?.sessionId) {
			console.error('Trying to leave call without sessionId')
		}

		const index = getters.getParticipantIndex(token, participantIdentifier)
		if (index === -1) {
			console.error('Participant not found', participantIdentifier)
			return
		}

		await leaveCall(token, all)

		const updatedData = {
			inCall: PARTICIPANT.CALL_FLAG.DISCONNECTED,
		}
		commit('updateParticipant', { token, index, updatedData })

		commit('setInCall', {
			token,
			sessionId: participantIdentifier.sessionId,
			flags: PARTICIPANT.CALL_FLAG.DISCONNECTED,
		})
	},

	/**
	 * Resends email invitations for the given conversation.
	 * If no userId is set, send to all applicable participants.
	 *
	 * @param {Object} _ unused
	 * @param {string} token conversation token
	 * @param {int} attendeeId attendee id to target, or null for all
	 */
	async resendInvitations(_, { token, attendeeId }) {
		await resendInvitations(token, { attendeeId })
	},

	/**
	 * Makes the current user active in the given conversation.
	 *
	 * @param {Object} context unused
	 * @param {string} token conversation token
	 */
	async joinConversation(context, { token }) {
		const forceJoin = SessionStorage.getItem('joined_conversation') === token

		try {
			const response = await joinConversation({ token, forceJoin })

			// Update the participant and actor session after a force join
			context.dispatch('setCurrentParticipant', response.data.ocs.data)
			context.dispatch('addConversation', response.data.ocs.data)
			context.dispatch('updateSessionId', {
				token,
				participantIdentifier: context.getters.getParticipantIdentifier(),
				sessionId: response.data.ocs.data.sessionId,
			})

			SessionStorage.setItem('joined_conversation', token)
			EventBus.$emit('joinedConversation', { token })
			return response
		} catch (error) {
			if (error?.response?.status === 409 && error?.response?.data?.ocs?.data) {
				const responseData = error.response.data.ocs.data
				let maxLastPingAge = new Date().getTime() / 1000 - 40
				if (responseData.inCall !== PARTICIPANT.CALL_FLAG.DISCONNECTED) {
					// When the user is/was in a call, we accept 20 seconds more delay
					maxLastPingAge -= 20
				}
				if (maxLastPingAge > responseData.lastPing) {
					console.debug('Force joining automatically because the old session didn\'t ping for 40 seconds')
					await context.dispatch('forceJoinConversation', { token })
				} else {
					await context.dispatch('confirmForceJoinConversation', { token })
				}
			} else {
				console.debug(error)
				showError(t('spreed', 'Failed to join the conversation. Try to reload the page.'))
			}
		}
	},

	async confirmForceJoinConversation(context, { token }) {
		// FIXME: UI stuff doesn't belong here, should rather
		// be triggered using a store flag and a dedicated Vue component

		// Little hack to check if the close button was used which we can't disable,
		// not listen to when it was used.
		const interval = setInterval(function() {
			// eslint-disable-next-line no-undef
			if ($('.oc-dialog-dim').length === 0) {
				clearInterval(interval)
				EventBus.$emit('duplicateSessionDetected')
				window.location = generateUrl('/apps/spreed')
			}
		}, 3000)

		await OC.dialogs.confirmDestructive(
			t('spreed', 'You are trying to join a conversation while having an active session in another window or device. This is currently not supported by Nextcloud Talk. What do you want to do?'),
			t('spreed', 'Duplicate session'),
			{
				type: OC.dialogs.YES_NO_BUTTONS,
				confirm: t('spreed', 'Join here'),
				confirmClasses: 'error',
				cancel: t('spreed', 'Leave this page'),
			},
			decision => {
				clearInterval(interval)
				if (!decision) {
					// Cancel
					EventBus.$emit('duplicateSessionDetected')
					window.location = generateUrl('/apps/spreed')
				} else {
					// Confirm
					context.dispatch('forceJoinConversation', { token })
				}
			}
		)
	},

	async forceJoinConversation(context, { token }) {
		SessionStorage.setItem('joined_conversation', token)
		await context.dispatch('joinConversation', { token })
	},

	/**
	 * Makes the current user inactive in the given conversation.
	 *
	 * @param {Object} context unused
	 * @param {string} token conversation token
	 */
	async leaveConversation(context, { token }) {
		await leaveConversation(token)
	},

	/**
	 * Removes the current user from the conversation, which
	 * means the user is not a participant any more.
	 *
	 * @param {Object} context unused
	 * @param {string} token conversation token
	 */
	async removeCurrentUserFromConversation(context, { token }) {
		await removeCurrentUserFromConversation(token)
		// If successful, deletes the conversation from the store
		await context.dispatch('deleteConversation', token)
	},
}

export default { state, mutations, getters, actions }
