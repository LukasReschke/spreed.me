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
import Vue from 'vue'
import { deleteMessage } from '../services/messagesService'

const state = {
	/**
	 * Map of conversation token to message list
	 */
	messages: {},
	/**
	 * Map of conversation token to first known message id
	 */
	firstKnown: {},
	/**
	 * Map of conversation token to last known message id
	 */
	lastKnown: {},
}

const getters = {
	/**
	 * Gets the messages array
	 * @param {object} state the state object.
	 * @returns {array} the messages array (if there are messages in the store)
	 */
	messagesList: (state) => (token) => {
		if (state.messages[token]) {
			return Object.values(state.messages[token])
		}
		return []
	},
	/**
	 * Gets the messages object
	 * @param {object} state the state object.
	 * @param {string} token the conversation token.
	 * @returns {object} the messages object (if there are messages in the store)
	 */
	messages: (state) => (token) => {
		if (state.messages[token]) {
			return state.messages[token]
		}
		return {}
	},
	/**
	 * Gets a single message object
	 * @param {object} state the state object.
	 * @param {string} token the conversation token.
	 * @param {string} id the message id.
	 * @returns {object} the message object (if the message is found in the store)
	 */
	message: (state) => (token, id) => {
		if (state.messages[token][id]) {
			return state.messages[token][id]
		}
		return {}
	},

	getTemporaryReferences: (state) => (token, referenceId) => {
		if (!state.messages[token]) {
			return []
		}

		return Object.values(state.messages[token]).filter(message => {
			return message.referenceId === referenceId
				&& ('' + message.id).startsWith('temp-')
		})
	},

	getFirstKnownMessageId: (state) => (token) => {
		if (state.firstKnown[token]) {
			return state.firstKnown[token]
		}
		return null
	},

	getLastKnownMessageId: (state) => (token) => {
		if (state.lastKnown[token]) {
			return state.lastKnown[token]
		}
		return null
	},
}

const mutations = {
	/**
	 * Adds a message to the store.
	 * @param {object} state current store state;
	 * @param {object} message the message;
	 */
	addMessage(state, message) {
		if (!state.messages[message.token]) {
			Vue.set(state.messages, message.token, {})
		}
		if (state.messages[message.token][message.id]) {
			Vue.set(state.messages[message.token], message.id,
				Object.assign(state.messages[message.token][message.id], message)
			)
		} else {
			Vue.set(state.messages[message.token], message.id, message)
		}
	},
	/**
	 * Deletes a message from the store.
	 * @param {object} state current store state;
	 * @param {object} message the message;
	 */
	deleteMessage(state, message) {
		if (state.messages[message.token][message.id]) {
			Vue.delete(state.messages[message.token], message.id)
		}
	},

	/**
	 * Deletes a message from the store.
	 * @param {object} state current store state;
	 * @param {object} message the message;
	 * @param {string} placeholder Placeholder message until deleting finished
	 */
	markMessageAsDeleting(state, { message, placeholder }) {
		Vue.set(state.messages[message.token][message.id], 'messageType', 'comment_deleted')
		Vue.set(state.messages[message.token][message.id], 'message', placeholder)
	},
	/**
	 * Adds a temporary message to the store.
	 * @param {object} state current store state;
	 * @param {object} message the temporary message;
	 */
	addTemporaryMessage(state, message) {
		if (!state.messages[message.token]) {
			Vue.set(state.messages, message.token, {})
		}
		Vue.set(state.messages[message.token], message.id, message)
	},

	/**
	 * Adds a temporary message to the store.
	 * @param {object} state current store state;
	 * @param {object} message the temporary message;
	 * @param {string} reason the reason the temporary message failed;
	 */
	markTemporaryMessageAsFailed(state, { message, reason }) {
		if (state.messages[message.token][message.id]) {
			Vue.set(state.messages[message.token][message.id], 'sendingFailure', reason)
		}
	},

	/**
	 * @param {object} state current store state;
	 * @param {string} token Token of the conversation
	 * @param {string} id Id of the first known chat message
	 */
	setFirstKnownMessageId(state, { token, id }) {
		Vue.set(state.firstKnown, token, id)
	},

	/**
	 * @param {object} state current store state;
	 * @param {string} token Token of the conversation
	 * @param {string} id Id of the last known chat message
	 */
	setLastKnownMessageId(state, { token, id }) {
		Vue.set(state.lastKnown, token, id)
	},

	/**
	 * Deletes the messages entry from the store for the given conversation token.
	 *
	 * @param {object} state current store state
	 * @param {string} token Token of the conversation
	 */
	deleteMessages(state, token) {
		if (state.firstKnown[token]) {
			Vue.delete(state.firstKnown, token)
		}
		if (state.lastKnown[token]) {
			Vue.delete(state.lastKnown, token)
		}
		if (state.messages[token]) {
			Vue.delete(state.messages, token)
		}
	},

	/**
	 * @param {object} state current store state;
	 * @param {string} token Token of the conversation
	 * @param {string} id Id of the last known chat message
	 */
	togglePinned(state, { token, id }) {
		Vue.set(state.messages[token][id].isPinned = !state.messages[token][id].isPinned)
	},
}

const actions = {

	/**
	 * Adds message to the store.
	 *
	 * If the message has a parent message object,
	 * first it adds the parent to the store.
	 *
	 * @param {object} context default store context;
	 * @param {object} message the message;
	 */
	processMessage(context, message) {
		if (message.parent) {
			context.commit('addMessage', message.parent)
			message.parent = message.parent.id
		}

		if (message.referenceId) {
			const tempMessages = context.getters.getTemporaryReferences(message.token, message.referenceId)
			tempMessages.forEach(tempMessage => {
				context.commit('deleteMessage', tempMessage)
			})
		}

		context.commit('addMessage', message)
	},

	/**
	 * Delete a message
	 *
	 * @param {object} context default store context;
	 * @param {object} message the message to be deleted;
	 * @param {string} placeholder Placeholder message until deleting finished
	 */
	async deleteMessage(context, { message, placeholder }) {
		const messageObject = Object.assign({}, context.getters.message(message.token, message.id))
		context.commit('markMessageAsDeleting', { message, placeholder })

		let response
		try {
			response = await deleteMessage(message)
		} catch (e) {
			// Restore the previous message state
			context.commit('addMessage', messageObject)
			throw e
		}

		const systemMessage = response.data.ocs.data
		if (systemMessage.parent) {
			context.commit('addMessage', systemMessage.parent)
			systemMessage.parent = systemMessage.parent.id
		}

		context.commit('addMessage', systemMessage)

		return response.status
	},

	/**
	 * Add a temporary message generated in the client to
	 * the store, these messages are deleted once the full
	 * message object is received from the server.
	 *
	 * @param {object} context default store context;
	 * @param {object} message the temporary message;
	 */
	addTemporaryMessage(context, message) {
		context.commit('addTemporaryMessage', message)
		// Update conversations list order
		context.dispatch('updateConversationLastActive', message.token)
	},

	/**
	 * Mark a temporary message as failed to allow retrying it again
	 *
	 * @param {object} context default store context;
	 * @param {object} message the temporary message;
	 * @param {string} reason the reason the temporary message failed;
	 */
	markTemporaryMessageAsFailed(context, { message, reason }) {
		context.commit('markTemporaryMessageAsFailed', { message, reason })
	},

	/**
	 * Remove temporary message from store after receiving the parsed one from server
	 *
	 * @param {object} context default store context;
	 * @param {object} message the temporary message;
	 */
	removeTemporaryMessageFromStore(context, message) {
		context.commit('deleteMessage', message)
	},

	/**
	 * @param {object} context default store context;
	 * @param {string} token Token of the conversation
	 * @param {string} id Id of the first known chat message
	 */
	setFirstKnownMessageId(context, { token, id }) {
		context.commit('setFirstKnownMessageId', { token, id })
	},

	/**
	 * @param {object} context default store context;
	 * @param {string} token Token of the conversation
	 * @param {string} id Id of the last known chat message
	 */
	setLastKnownMessageId(context, { token, id }) {
		context.commit('setLastKnownMessageId', { token, id })
	},

	/**
	 * Deletes the messages of a conversation
	 *
	 * @param {object} context default store context;
	 * @param {object} token the token of the conversation to be deleted;
	 */
	deleteMessages(context, token) {
		context.commit('deleteMessages', token)

	},

	/**
	 * @param {object} context default store context;
	 * @param {string} token Token of the conversation
	 * @param {string} id Id of the last known chat message
	 */
	togglePinned(context, { token, id }) {
		context.commit('togglePinned', { token, id })
	},
}

export default { state, mutations, getters, actions }
