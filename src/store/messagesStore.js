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
import {
	deleteMessage,
	updateLastReadMessage,
	fetchMessages,
	lookForNewMessages,
	postNewMessage,
} from '../services/messagesService'

import SHA1 from 'crypto-js/sha1'
import Hex from 'crypto-js/enc-hex'
import CancelableRequest from '../utils/cancelableRequest'
import { showError } from '@nextcloud/dialogs'
import {
	ATTENDEE,
} from '../constants'
import { cloneDeep } from 'lodash'

/**
 * Returns whether the given message contains a mention to self, directly
 * or indirectly through a global mention.
 *
 * @param {Object} context store context
 * @param {Object} message message object
 * @returns {bool} true if the message contains a mention to self or all,
 * false otherwise
 */
function hasMentionToSelf(context, message) {
	if (!message.messageParameters) {
		return false
	}

	for (const key in message.messageParameters) {
		const param = message.messageParameters[key]

		if (param.type === 'call') {
			return true
		}
		if (param.type === 'guest'
			&& context.getters.getActorType() === ATTENDEE.ACTOR_TYPE.GUESTS
			&& param.id === ('guest/' + context.getters.getActorId())
		) {
			return true
		}
		if (param.type === 'user'
			&& context.getters.getActorType() === ATTENDEE.ACTOR_TYPE.USERS
			&& param.id === context.getters.getUserId()
		) {
			return true
		}
	}

	return false
}

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

	/**
	 * Cached last read message id for display.
	 */
	visualLastReadMessageId: {},

	/**
	 * Stores the cancel function returned by `cancelableFetchMessages`,
	 * which allows to cancel the previous request for old messages
	 * when quickly switching to a new conversation.
	 */
	cancelFetchMessages: null,
	/**
	 * Stores the cancel function returned by `cancelableLookForNewMessages`,
	 * which allows to cancel the previous long polling request for new
	 * messages before making another one.
	 */
	cancelLookForNewMessages: {},
	/**
	 * Array of temporary message id to cancel function for the "postNewMessage" action
	 */
	cancelPostNewMessage: {},
}

const getters = {
	/**
	 * Returns whether more messages can be loaded, which means that the current
	 * message list doesn't yet contain all future messages.
	 * If false, the next call to "lookForNewMessages" will be blocking/long-polling.
	 *
	 * @param {object} state the state object.
	 * @param {object} getters the getters object.
	 * @returns {bool} true if more messages exist that needs loading, false otherwise
	 */
	hasMoreMessagesToLoad: (state, getters) => (token) => {
		const conversation = getters.conversation(token)
		if (!conversation) {
			return false
		}

		return getters.getLastKnownMessageId(token) < conversation.lastMessage.id
	},

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

	getVisualLastReadMessageId: (state) => (token) => {
		if (state.visualLastReadMessageId[token]) {
			return state.visualLastReadMessageId[token]
		}
		return null
	},

	isSendingMessages: (state) => {
		// the cancel handler only exists when a message is being sent
		return Object.keys(state.cancelPostNewMessage).length !== 0
	},
}

const mutations = {
	setCancelFetchMessages(state, cancelFunction) {
		state.cancelFetchMessages = cancelFunction
	},

	setCancelLookForNewMessages(state, { requestId, cancelFunction }) {
		if (cancelFunction) {
			Vue.set(state.cancelLookForNewMessages, requestId, cancelFunction)
		} else {
			Vue.delete(state.cancelLookForNewMessages, requestId)
		}
	},

	setCancelPostNewMessage(state, { messageId, cancelFunction }) {
		if (cancelFunction) {
			Vue.set(state.cancelPostNewMessage, messageId, cancelFunction)
		} else {
			Vue.delete(state.cancelPostNewMessage, messageId)
		}
	},

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
	 * @param {object} state current store state;
	 * @param {string} token Token of the conversation
	 * @param {string} id Id of the last read chat message
	 */
	setVisualLastReadMessageId(state, { token, id }) {
		Vue.set(state.visualLastReadMessageId, token, id)
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
		if (state.visualLastReadMessageId[token]) {
			Vue.delete(state.visualLastReadMessageId, token)
		}
		if (state.messages[token]) {
			Vue.delete(state.messages, token)
		}
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
	 * Creates a temporary message ready to be posted, based
	 * on the message to be replied and the current actor
	 *
	 * @param {object} context default store context;
	 * @param {string} text message string;
	 * @param {string} token conversation token;
	 * @param {string} uploadId upload id;
	 * @param {int} index index;
	 * @param {object} file file to upload;
	 * @param {string} localUrl local URL of file to upload;
	 * @returns {object} temporary message
	 */
	createTemporaryMessage(context, { text, token, uploadId, index, file, localUrl, isVoiceMessage }) {
		const messageToBeReplied = context.getters.getMessageToBeReplied(token)
		const date = new Date()
		let tempId = 'temp-' + date.getTime()
		const messageParameters = {}
		if (file) {
			tempId += '-' + uploadId + '-' + Math.random()
			messageParameters.file = {
				type: 'file',
				file,
				mimetype: file.type,
				id: tempId,
				name: file.newName || file.name,
				// index, will be the id from now on
				uploadId,
				localUrl,
				index,
			}
		}
		const message = Object.assign({}, {
			id: tempId,
			actorId: context.getters.getActorId(),
			actorType: context.getters.getActorType(),
			actorDisplayName: context.getters.getDisplayName(),
			timestamp: 0,
			systemMessage: '',
			messageType: isVoiceMessage ? 'voice-message' : '',
			message: text,
			messageParameters,
			token,
			isReplyable: false,
			sendingFailure: '',
			referenceId: Hex.stringify(SHA1(tempId)),
		})

		/**
		 * If the current message is a quote-reply message, add the parent key to the
		 * temporary message object.
		 */
		if (messageToBeReplied) {
			message.parent = messageToBeReplied.id
		}
		return message
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
	 * @param {object} context default store context;
	 * @param {string} token Token of the conversation
	 * @param {string} id Id of the last read chat message
	 */
	setVisualLastReadMessageId(context, { token, id }) {
		context.commit('setVisualLastReadMessageId', { token, id })
	},

	/**
	 * Deletes all messages of a conversation from the store only.
	 *
	 * @param {object} context default store context;
	 * @param {object} token the token of the conversation to be deleted;
	 */
	deleteMessages(context, token) {
		context.commit('deleteMessages', token)
	},

	/**
	 * Clears the last read message marker by moving it to the last message
	 * in the conversation.
	 *
	 * @param {object} context default store context;
	 * @param {object} token the token of the conversation to be updated;
	 * @param {bool} updateVisually whether to also clear the marker visually in the UI;
	 */
	async clearLastReadMessage(context, { token, updateVisually = false }) {
		const conversation = context.getters.conversations[token]
		if (!conversation || !conversation.lastMessage) {
			return
		}
		// set the id to the last message
		context.dispatch('updateLastReadMessage', { token, id: conversation.lastMessage.id, updateVisually })
		context.dispatch('markConversationRead', token)
	},

	/**
	 * Updates the last read message in the store and also in the backend.
	 * Optionally also updated the marker visually in the UI if specified.
	 *
	 * @param {object} context default store context;
	 * @param {object} token the token of the conversation to be updated;
	 * @param {number} id the id of the message on which to set the read marker;
	 * @param {bool} updateVisually whether to also update the marker visually in the UI;
	 */
	async updateLastReadMessage(context, { token, id = 0, updateVisually = false }) {
		const conversation = context.getters.conversations[token]
		if (!conversation || conversation.lastReadMessage === id) {
			return
		}

		if (id === 0) {
			console.warn('updateLastReadMessage: should not set read marker with id=0')
		}

		// optimistic early commit to avoid indicator flickering
		context.dispatch('updateConversationLastReadMessage', { token, lastReadMessage: id })
		if (updateVisually) {
			context.commit('setVisualLastReadMessageId', { token, id })
		}

		if (context.getters.getUserId()) {
			// only update on server side if there's an actual user, not guest
			await updateLastReadMessage(token, id)
		}
	},

	/**
	 * Fetches messages that belong to a particular conversation
	 * specified with its token.
	 *
	 * @param {object} context default store context;
	 * @param {string} token the conversation token;
	 * @param {object} requestOptions request options;
	 * @param {string} lastKnownMessageId last known message id;
	 * @param {bool} includeLastKnown whether to include the last known message in the response;
	 */
	async fetchMessages(context, { token, lastKnownMessageId, includeLastKnown, requestOptions }) {
		context.dispatch('cancelFetchMessages')

		// Get a new cancelable request function and cancel function pair
		const { request, cancel } = CancelableRequest(fetchMessages)
		// Assign the new cancel function to our data value
		context.commit('setCancelFetchMessages', cancel)

		const response = await request({
			token,
			lastKnownMessageId,
			includeLastKnown,
		}, requestOptions)

		let newestKnownMessageId = 0

		if ('x-chat-last-common-read' in response.headers) {
			const lastCommonReadMessage = parseInt(response.headers['x-chat-last-common-read'], 10)
			context.dispatch('updateLastCommonReadMessage', {
				token,
				lastCommonReadMessage,
			})
		}

		// Process each messages and adds it to the store
		response.data.ocs.data.forEach(message => {
			if (message.actorType === ATTENDEE.ACTOR_TYPE.GUESTS) {
				// update guest display names cache
				context.dispatch('setGuestNameIfEmpty', message)
			}
			context.dispatch('processMessage', message)
			newestKnownMessageId = Math.max(newestKnownMessageId, message.id)
		})

		if (response.headers['x-chat-last-given']) {
			context.dispatch('setFirstKnownMessageId', {
				token,
				id: parseInt(response.headers['x-chat-last-given'], 10),
			})
		}

		// For guests we also need to set the last known message id
		// after the first grab of the history, otherwise they start loading
		// the full history with fetchMessages().
		if (includeLastKnown && newestKnownMessageId
			&& !context.getters.getLastKnownMessageId(token)) {
			context.dispatch('setLastKnownMessageId', {
				token,
				id: newestKnownMessageId,
			})
		}

		return response
	},

	/**
	 * Cancels a previously running "fetchMessages" action if applicable.
	 *
	 * @param {object} context default store context;
	 * @returns {bool} true if a request got cancelled, false otherwise
	 */
	cancelFetchMessages(context) {
		if (context.state.cancelFetchMessages) {
			context.state.cancelFetchMessages('canceled')
			context.commit('setCancelFetchMessages', null)
			return true
		}
		return false
	},

	/**
	 * Fetches newly created messages that belong to a particular conversation
	 * specified with its token.
	 *
	 * This call will long-poll when hasMoreMessagesToLoad() returns false.
	 *
	 * @param {object} context default store context;
	 * @param {string} token The conversation token;
	 * @param {string} requestId id to identify request uniquely
	 * @param {object} requestOptions request options;
	 * @param {int} lastKnownMessageId The id of the last message in the store.
	 */
	async lookForNewMessages(context, { token, lastKnownMessageId, requestId, requestOptions }) {
		context.dispatch('cancelLookForNewMessages', { requestId })

		// Get a new cancelable request function and cancel function pair
		const { request, cancel } = CancelableRequest(lookForNewMessages)

		// Assign the new cancel function to our data value
		context.commit('setCancelLookForNewMessages', { cancelFunction: cancel, requestId })

		const response = await request({ token, lastKnownMessageId }, requestOptions)
		context.commit('setCancelLookForNewMessages', { requestId })

		if ('x-chat-last-common-read' in response.headers) {
			const lastCommonReadMessage = parseInt(response.headers['x-chat-last-common-read'], 10)
			context.dispatch('updateLastCommonReadMessage', {
				token,
				lastCommonReadMessage,
			})
		}

		const conversation = context.getters.conversation(token)
		let countNewMessages = 0
		let hasNewMention = conversation.unreadMention
		let lastMessage = null
		// Process each messages and adds it to the store
		response.data.ocs.data.forEach(message => {
			if (message.actorType === ATTENDEE.ACTOR_TYPE.GUESTS) {
				// update guest display names cache,
				// force in case the display name has changed since
				// the last fetch
				context.dispatch('forceGuestName', message)
			}
			context.dispatch('processMessage', message)
			if (!lastMessage || message.id > lastMessage.id) {
				if (!message.systemMessage) {
					countNewMessages++

					// parse mentions data to update "conversation.unreadMention",
					// if needed
					if (!hasNewMention && hasMentionToSelf(context, message)) {
						hasNewMention = true
					}
				}
				lastMessage = message
			}

			// in case we encounter an already read message, reset the counter
			// this is probably unlikely to happen unless one starts browsing from
			// an earlier page and scrolls down
			if (conversation.lastReadMessage === message.id) {
				// discard counters
				countNewMessages = 0
				hasNewMention = conversation.unreadMention
			}
		})

		context.dispatch('setLastKnownMessageId', {
			token,
			id: parseInt(response.headers['x-chat-last-given'], 10),
		})

		if (conversation && conversation.lastMessage && lastMessage.id > conversation.lastMessage.id) {
			context.dispatch('updateConversationLastMessage', {
				token,
				lastMessage,
			})

			// only increase the counter if the conversation store was out of sync with the message list
			if (countNewMessages > 0) {
				context.commit('updateUnreadMessages', {
					token,
					unreadMessages: conversation.unreadMessages + countNewMessages,
					// only update the value if it's been changed to true
					unreadMention: conversation.unreadMention !== hasNewMention ? hasNewMention : undefined,
				})
			}
		}

		return response
	},

	/**
	 * Cancels a previously running "lookForNewMessages" action if applicable.
	 *
	 * @param {object} context default store context;
	 * @param {string} requestId request id
	 * @returns {bool} true if a request got cancelled, false otherwise
	 */
	cancelLookForNewMessages(context, { requestId }) {
		if (context.state.cancelLookForNewMessages[requestId]) {
			context.state.cancelLookForNewMessages[requestId]('canceled')
			context.commit('setCancelLookForNewMessages', { requestId })
			return true
		}
		return false
	},

	/**
	 * Sends the given temporary message to the server.
	 *
	 * @param {object} context default store context;
	 * @param {object} temporaryMessage temporary message, must already have been added to messages list.
	 */
	async postNewMessage(context, temporaryMessage) {
		const { request, cancel } = CancelableRequest(postNewMessage)
		context.commit('setCancelPostNewMessage', { messageId: temporaryMessage.id, cancelFunction: cancel })

		const timeout = setTimeout(() => {
			context.dispatch('cancelPostNewMessage', { messageId: temporaryMessage.id })
			context.dispatch('markTemporaryMessageAsFailed', {
				message: temporaryMessage,
				reason: 'timeout',
			})
		}, 30000)

		try {
			const response = await request(temporaryMessage)
			clearTimeout(timeout)
			context.commit('setCancelPostNewMessage', { messageId: temporaryMessage.id, cancelFunction: null })

			if ('x-chat-last-common-read' in response.headers) {
				const lastCommonReadMessage = parseInt(response.headers['x-chat-last-common-read'], 10)
				context.dispatch('updateLastCommonReadMessage', {
					token: temporaryMessage.token,
					lastCommonReadMessage,
				})
			}

			// If successful, deletes the temporary message from the store
			context.dispatch('removeTemporaryMessageFromStore', temporaryMessage)

			const message = response.data.ocs.data
			// And adds the complete version of the message received
			// by the server
			context.dispatch('processMessage', message)

			const conversation = context.getters.conversation(temporaryMessage.token)

			// update lastMessage and lastReadMessage
			// do it conditionally because there could have been more messages appearing concurrently
			if (conversation && conversation.lastMessage && message.id > conversation.lastMessage.id) {
				context.dispatch('updateConversationLastMessage', {
					token: conversation.token,
					lastMessage: message,
				})
			}
			if (conversation && message.id > conversation.lastReadMessage) {
				// no await to make it async
				context.dispatch('updateLastReadMessage', {
					token: conversation.token,
					id: message.id,
					updateVisually: true,
				})
			}

			return response
		} catch (error) {
			if (timeout) {
				clearTimeout(timeout)
			}
			context.commit('setCancelPostNewMessage', { messageId: temporaryMessage.id, cancelFunction: null })

			let statusCode = null
			console.error(`error while submitting message ${error}`, error)
			if (error.isAxiosError) {
				statusCode = error?.response?.status
			}

			// FIXME: don't use showError here but set a flag
			// somewhere that makes Vue trigger the error message

			// 403 when room is read-only, 412 when switched to lobby mode
			if (statusCode === 403) {
				showError(t('spreed', 'No permission to post messages in this conversation'))
				context.dispatch('markTemporaryMessageAsFailed', {
					message: temporaryMessage,
					reason: 'read-only',
				})
			} else if (statusCode === 412) {
				showError(t('spreed', 'No permission to post messages in this conversation'))
				context.dispatch('markTemporaryMessageAsFailed', {
					message: temporaryMessage,
					reason: 'lobby',
				})
			} else {
				showError(t('spreed', 'Could not post message: {errorMessage}', { errorMessage: error.message || error }))
				context.dispatch('markTemporaryMessageAsFailed', {
					message: temporaryMessage,
					reason: 'other',
				})
			}
			throw error
		}
	},

	/**
	 * Cancels a previously running "postNewMessage" action if applicable.
	 *
	 * @param {object} context default store context;
	 * @param {string} messageId the message id for which to cancel;
	 * @returns {bool} true if a request got cancelled, false otherwise
	 */
	cancelPostNewMessage(context, { messageId }) {
		if (context.state.cancelPostNewMessage[messageId]) {
			context.state.cancelPostNewMessage[messageId]('canceled')
			context.commit('setCancelPostNewMessage', { messageId, cancelFunction: null })
			return true
		}
		return false
	},

	/**
	 * Posts a simple text message to a room
	 * @param {object} context default store context;
	 * @param {strinf} token the token of the conversation to wich the message
	 * will be forwarded;
	 * @param {object} message the message object;
	 */
	async forwardMessage(context, { token, message }) {
		const messageToBeForwarded = cloneDeep(message)
		messageToBeForwarded.token = token
		try {
			const response = await postNewMessage(messageToBeForwarded)
			return response
		} catch (error) {
			console.error('Error while forwarding message')
			showError(t('spreed', 'Error while forwarding message'))
		}
	},
}

export default { state, mutations, getters, actions }
