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
import fromStateOr from './helper'

const state = {
	guestNames: {
	},
	askGuestUsername: fromStateOr('spreed', 'ask_guest_username', 0),
}

const getters = {
	/**
	 * Gets the participants array
	 * @param {object} state the state object.
	 * @returns {array} the participants array (if there are participants in the store)
	 */
	getGuestName: (state) => (token, actorId) => {
		if (state.guestNames[token] && state.guestNames[token][actorId]) {
			return state.guestNames[token][actorId]
		}
		return t('spreed', 'Guest')
	},

	getAskGuestUsername: (state) => () => {
		return state.askGuestUsername
	},
}

const mutations = {
	/**
	 * Adds a guest name to the store
	 * @param {object} state current store state
	 * @param {boolean} noUpdate Only set the guest name if it was not set before
	 * @param {string} token the token of the conversation
	 * @param {string} actorId the guest
	 * @param {string} actorDisplayName the display name to set
	 */
	addGuestName(state, { noUpdate, token, actorId, actorDisplayName }) {
		if (!state.guestNames[token]) {
			Vue.set(state.guestNames, token, [])
		}
		if (!state.guestNames[token][actorId]) {
			Vue.set(state.guestNames[token], actorId, t('spreed', 'Guest'))
		} else if (noUpdate) {
			return
		}
		state.guestNames[token][actorId] = actorDisplayName
	},
}

const actions = {

	/**
	 * Add guest name of a chat message to the store
	 *
	 * @param {object} context default store context
	 * @param {string} token the token of the conversation
	 * @param {string} actorId the guest
	 * @param {string} actorDisplayName the display name to set
	 */
	setGuestNameIfEmpty(context, { token, actorId, actorDisplayName }) {
		context.commit('addGuestName', { noUpdate: true, token, actorId, actorDisplayName })
	},
	/**
	 * Add guest name of a chat message to the store
	 *
	 * @param {object} context default store context
	 * @param {string} token the token of the conversation
	 * @param {string} actorId the guest
	 * @param {string} actorDisplayName the display name to set
	 */
	forceGuestName(context, { token, actorId, actorDisplayName }) {
		context.commit('addGuestName', { noUpdate: false, token, actorId, actorDisplayName })
	},
}

export default { state, mutations, getters, actions }
