/**
 * @copyright Copyright (c) 2020 Joas Schilling <coding@schilljs.com>
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

import { loadState } from '@nextcloud/initial-state'

const state = {
	sendMessageKey: loadState('talk', 'send_message_key'),
}

const getters = {
	/**
	 * Returns the preferred keyboard key for sending messages.
	 * See SEND_MESSAGE_KEY constants.
	 *
	 * @param {object} state state
	 * @returns {string} key key to use
	 */
	getSendMessageKey: (state) => () => {
		return state.sendMessageKey
	},
}

const mutations = {
	/**
	 * Set preferred keyboard key for sending messages
	 * See SEND_MESSAGE_KEY constants.
	 *
	 * @param {object} state current store state;
	 * @param {String} key key to use
	 */
	updateSendMessageKey(state, key) {
		state.sendMessageKey = key
	},
}

const actions = {
	/**
	 * Set preferred key for sending messages
	 *
	 * @param {object} context default store context;
	 * @param {String} key key to use
	 */
	updateSendMessageKey(context, key) {
		context.commit('updateSendMessageKey', key)
	},
}

export default { state, mutations, getters, actions }
