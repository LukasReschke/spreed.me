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
import { generateOcsUrl } from '@nextcloud/router'
import { CONVERSATION, SHARE } from '../constants'

/**
 * Fetches the conversations from the server.
 * @param {object} options options
 */
const fetchConversations = async function(options) {
	options = options || {}
	options.params = options.params || {}
	options.params.includeStatus = true
	return await axios.get(generateOcsUrl('apps/spreed/api/v4/room'), options)
}

/**
 * Fetches a conversation from the server.
 * @param {string} token The token of the conversation to be fetched.
 */
const fetchConversation = async function(token) {
	return axios.get(generateOcsUrl('apps/spreed/api/v4/room/{token}', { token }))
}

/**
 * Fetch listed conversations
 * @param {string} searchText The string that will be used in the search query.
 * @param {object} options options
 */
const searchListedConversations = async function({ searchText }, options) {
	return axios.get(generateOcsUrl('apps/spreed/api/v4/listed-room'), Object.assign(options, {
		params: {
			searchTerm: searchText,
		},
	}))
}

/**
 * Fetch possible conversations
 *
 * @param {string} searchText The string that will be used in the search query.
 * @param {object} options options
 * @param {string} [token] The token of the conversation (if any), or "new" for a new one
 * @param {boolean} [onlyUsers] Only return users
 */
const searchPossibleConversations = async function({ searchText, token, onlyUsers }, options) {
	token = token || 'new'
	onlyUsers = !!onlyUsers
	const shareTypes = [
		SHARE.TYPE.USER,
	]

	if (!onlyUsers) {
		shareTypes.push(SHARE.TYPE.GROUP)
		shareTypes.push(SHARE.TYPE.CIRCLE)
		if (token !== 'new') {
			shareTypes.push(SHARE.TYPE.EMAIL)
		}
	}

	return axios.get(generateOcsUrl('core/autocomplete/get'), Object.assign(options, {
		params: {
			search: searchText,
			itemType: 'call',
			itemId: token,
			shareTypes,
		},
	}))
}

/**
 * Create a new one to one conversation with the specified user.
 * @param {string} userId The ID of the user with wich the new conversation will be opened.
 */
const createOneToOneConversation = async function(userId) {
	try {
		const response = await axios.post(generateOcsUrl('apps/spreed/api/v4/room'), { roomType: CONVERSATION.TYPE.ONE_TO_ONE, invite: userId })
		return response
	} catch (error) {
		console.debug('Error creating new one to one conversation: ', error)
	}
}

/**
 * Create a new group conversation.
 * @param {string} invite The group/circle ID
 * @param {string} source The source of the invite ID (defaults to groups)
 */
const createGroupConversation = async function(invite, source) {
	try {
		const response = await axios.post(generateOcsUrl('apps/spreed/api/v4/room'), { roomType: CONVERSATION.TYPE.GROUP, invite, source: source || 'groups' })
		return response
	} catch (error) {
		console.debug('Error creating new group conversation: ', error)
	}
}

/**
 * Create a new private conversation.
 * @param {string} conversationName The name for the new conversation
 */
const createPrivateConversation = async function(conversationName) {
	try {
		const response = await axios.post(generateOcsUrl('apps/spreed/api/v4/room'), { roomType: CONVERSATION.TYPE.GROUP, roomName: conversationName })
		return response
	} catch (error) {
		console.debug('Error creating new private conversation: ', error)
	}
}

/**
 * Create a new private conversation.
 * @param {string} conversationName The name for the new conversation
 */
const createPublicConversation = async function(conversationName) {
	try {
		const response = await axios.post(generateOcsUrl('apps/spreed/api/v4/room'), { roomType: CONVERSATION.TYPE.PUBLIC, roomName: conversationName })
		return response
	} catch (error) {
		console.debug('Error creating new public conversation: ', error)
	}
}

/**
 * Set a conversation's password
 * @param {string} token the conversation's token
 * @param {string} password the password to be set
 */
const setConversationPassword = async function(token, password) {
	const response = await axios.put(generateOcsUrl('apps/spreed/api/v4/room/{token}/password', { token }), {
		password,
	})
	return response
}

/**
 * Set a conversation's name
 * @param {string} token the conversation's token
 * @param {string} name the name to be set
 */
const setConversationName = async function(token, name) {
	const response = await axios.put(generateOcsUrl('apps/spreed/api/v4/room/{token}', { token }), {
		roomName: name,
	})
	return response
}

/**
 * Delete a conversation.
 * @param {string} token The token of the conversation to be deleted.
 */
const deleteConversation = async function(token) {
	try {
		const response = await axios.delete(generateOcsUrl('apps/spreed/api/v4/room/{token}', { token }))
		return response
	} catch (error) {
		console.debug('Error while deleting the conversation: ', error)
	}
}

/**
 * Clears the conversation history
 * @param {string} token The token of the conversation to be deleted.
 */
const clearConversationHistory = async function(token) {
	const response = await axios.delete(generateOcsUrl('apps/spreed/api/v4/room/{token}', {
		name: 'Chat#clearHistory',
		token,
		 }))
	return response
}

/**
 * Add a conversation to the favorites
 * @param {string} token The token of the conversation to be favorites
 */
const addToFavorites = async function(token) {
	try {
		const response = await axios.post(generateOcsUrl('apps/spreed/api/v4/room/{token}/favorite', { token }))
		return response
	} catch (error) {
		console.debug('Error while adding the conversation to favorites: ', error)
	}
}

/**
 * Remove a conversation from the favorites
 * @param {string} token The token of the conversation to be removed from favorites
 */
const removeFromFavorites = async function(token) {
	try {
		const response = await axios.delete(generateOcsUrl('apps/spreed/api/v4/room/{token}/favorite', { token }))
		return response
	} catch (error) {
		console.debug('Error while removing the conversation from favorites: ', error)
	}
}

/**
 * Remove a conversation from the favorites
 * @param {string} token The token of the conversation to be removed from favorites
 * @param {int} level The notification level to set.
 */
const setNotificationLevel = async function(token, level) {
	try {
		const response = await axios.post(generateOcsUrl('apps/spreed/api/v4/room/{token}/notify', { token }), { level })
		return response
	} catch (error) {
		console.debug('Error while setting the notification level: ', error)
	}
}

/**
 * Make the conversation public
 * @param {string} token The token of the conversation to be removed from favorites
 */
const makePublic = async function(token) {
	try {
		const response = await axios.post(generateOcsUrl('apps/spreed/api/v4/room/{token}/public', { token }))
		return response
	} catch (error) {
		console.debug('Error while making the conversation public: ', error)
	}
}

/**
 * Make the conversation private
 * @param {string} token The token of the conversation to be removed from favorites
 */
const makePrivate = async function(token) {
	try {
		const response = await axios.delete(generateOcsUrl('apps/spreed/api/v4/room/{token}/public', { token }))
		return response
	} catch (error) {
		console.debug('Error while making the conversation private: ', error)
	}
}

/**
 * Change the SIP enabled
 * @param {string} token The token of the conversation to be modified
 * @param {int} newState The new SIP state to set
 */
const setSIPEnabled = async function(token, newState) {
	return axios.put(generateOcsUrl('apps/spreed/api/v4/room/{token}/webinar/sip', { token }), {
		state: newState,
	})
}

/**
 * Change the lobby state
 * @param {string} token The token of the conversation to be modified
 * @param {int} newState The new lobby state to set
 * @param {int} timestamp The UNIX timestamp (in seconds) to set, if any
 */
const changeLobbyState = async function(token, newState, timestamp) {
	try {
		const response = await axios.put(generateOcsUrl('apps/spreed/api/v4/room/{token}/webinar/lobby', { token }), {
			state: newState,
			timer: timestamp,
		})
		return response
	} catch (error) {
		console.debug('Error while updating webinar lobby: ', error)
	}
}

/**
 * Change the read-only state
 * @param {string} token The token of the conversation to be modified
 * @param {int} readOnly The new read-only state to set
 */
const changeReadOnlyState = async function(token, readOnly) {
	try {
		const response = await axios.put(generateOcsUrl('apps/spreed/api/v4/room/{token}/read-only', { token }), {
			state: readOnly,
		})
		return response
	} catch (error) {
		console.debug('Error while updating read-only state: ', error)
	}
}

/**
 * Change the listable scope
 * @param {string} token The token of the conversation to be modified
 * @param {int} listable The new listable scope to set
 */
const changeListable = async function(token, listable) {
	const response = await axios.put(generateOcsUrl('apps/spreed/api/v4/room/{token}/listable', { token }), {
		scope: listable,
	})
	return response
}

const setConversationDescription = async function(token, description) {
	const response = await axios.put(generateOcsUrl('apps/spreed/api/v4/room/{token}/description', { token }), {
		description,
	})
	return response
}

export {
	fetchConversations,
	fetchConversation,
	searchListedConversations,
	searchPossibleConversations,
	createOneToOneConversation,
	createGroupConversation,
	createPrivateConversation,
	createPublicConversation,
	deleteConversation,
	addToFavorites,
	removeFromFavorites,
	setNotificationLevel,
	makePublic,
	makePrivate,
	setSIPEnabled,
	changeLobbyState,
	changeReadOnlyState,
	changeListable,
	setConversationPassword,
	setConversationName,
	setConversationDescription,
	clearConversationHistory,
}
