/**
 * @copyright Copyright (c) 2020 Marco Ambrosini <marcoambrosini@pm.me>
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
import BrowserStorage from '../services/BrowserStorage'
import {
	CONVERSATION,
} from '../constants'

const state = {
	isGrid: false,
	isStripeOpen: true,
	lastIsGrid: null,
	lastIsStripeOpen: null,
	presentationStarted: false,
	selectedVideoPeerId: null,
	qualityWarningTooltipDismissed: false,
	participantRaisedHands: {},
	backgroundImageAverageColorCache: {},
	videoEffectsEnabled: false,
}

const getters = {
	isGrid: (state) => state.isGrid,
	isStripeOpen: (state) => state.isStripeOpen,
	lastIsGrid: (state) => state.lastIsGrid,
	lastIsStripeOpen: (state) => state.lastIsStripeOpen,
	presentationStarted: (state) => state.presentationStarted,
	selectedVideoPeerId: (state) => {
		return state.selectedVideoPeerId
	},
	isQualityWarningTooltipDismissed: (state) => state.qualityWarningTooltipDismissed,
	getParticipantRaisedHand: (state) => (sessionIds) => {
		for (let i = 0; i < sessionIds.length; i++) {
			if (state.participantRaisedHands[sessionIds[i]]) {
				// note: only the raised states are stored, so no need to confirm
				return state.participantRaisedHands[sessionIds[i]]
			}
		}

		return { state: false, timestamp: null }
	},
	getCachedBackgroundImageAverageColor: (state) => (videoBackgroundId) => {
		return state.backgroundImageAverageColorCache[videoBackgroundId]
	},
	isVideoEffectsEnabled: (state) => state.videoEffectsEnabled,
}

const mutations = {

	isGrid(state, value) {
		state.isGrid = value
	},
	isStripeOpen(state, value) {
		state.isStripeOpen = value
	},
	lastIsGrid(state, value) {
		state.lastIsGrid = value
	},
	lastIsStripeOpen(state, value) {
		state.lastIsStripeOpen = value
	},
	selectedVideoPeerId(state, value) {
		state.selectedVideoPeerId = value
	},
	presentationStarted(state, value) {
		state.presentationStarted = value
	},
	setQualityWarningTooltipDismissed(state, { qualityWarningTooltipDismissed }) {
		state.qualityWarningTooltipDismissed = qualityWarningTooltipDismissed
	},
	setParticipantHandRaised(state, { sessionId, raisedHand }) {
		if (!sessionId) {
			throw new Error('Missing or empty sessionId argument in call to setParticipantHandRaised')
		}
		if (raisedHand && raisedHand.state) {
			Vue.set(state.participantRaisedHands, sessionId, raisedHand)
		} else {
			Vue.delete(state.participantRaisedHands, sessionId)
		}
	},
	clearParticipantHandRaised(state) {
		state.participantRaisedHands = {}
	},
	setCachedBackgroundImageAverageColor(state, { videoBackgroundId, backgroundImageAverageColor }) {
		Vue.set(state.backgroundImageAverageColorCache, videoBackgroundId, backgroundImageAverageColor)
	},
	clearBackgroundImageAverageColorCache(state) {
		state.backgroundImageAverageColorCache = {}
	},
	setVideoEffectsEnabled(state, enabled) {
		state.videoEffectsEnabled = enabled
	},
}

const actions = {
	selectedVideoPeerId(context, value) {
		context.commit('selectedVideoPeerId', value)
	},

	joinCall(context, { token }) {
		let isGrid = BrowserStorage.getItem('callprefs-' + token + '-isgrid')
		if (isGrid === null) {
			const conversationType = context.getters.conversations[token].type
			// default to grid view for group/public calls, otherwise speaker view
			isGrid = (conversationType === CONVERSATION.TYPE.GROUP
				|| conversationType === CONVERSATION.TYPE.PUBLIC)
		} else {
			// BrowserStorage.getItem returns a string instead of a boolean
			isGrid = (isGrid === 'true')
		}
		context.dispatch('setCallViewMode', { isGrid, isStripeOpen: true })

		context.commit('setQualityWarningTooltipDismissed', { qualityWarningTooltipDismissed: false })

		const enableVideoEffects = BrowserStorage.getItem('callprefs-' + token + '-videoeffectsenabled') === 'true'
		context.dispatch('setVideoEffectsEnabled', enableVideoEffects)
	},

	leaveCall(context) {
		// clear raised hands as they were specific to the call
		context.commit('clearParticipantHandRaised')

		context.commit('clearBackgroundImageAverageColorCache')
	},

	/**
	 * Sets the current call view mode and saves it in preferences.
	 * If clearLast is false, also remembers it in separate properties.
	 *
	 * @param {object} context default store context;
	 * @param {bool} isGrid=null true for enabled grid mode, false for speaker view;
	 * @param {bool} isStripeOpen=null true for visible stripel mode, false for speaker view;
	 * @param {bool} clearLast=true set to false to not reset last temporary remembered state;
	 */
	setCallViewMode(context, { isGrid = null, isStripeOpen = null, clearLast = true }) {
		if (clearLast) {
			context.commit('lastIsGrid', null)
			context.commit('lastIsStripeOpen', null)
		} else {
			context.commit('lastIsGrid', context.getters.isGrid)
			context.commit('lastIsStripeOpen', context.getters.isStripeOpen)
		}

		if (isGrid !== null) {
			BrowserStorage.setItem('callprefs-' + context.getters.getToken() + '-isgrid', isGrid)
			context.commit('isGrid', isGrid)
		}

		if (isStripeOpen !== null) {
			context.commit('isStripeOpen', isStripeOpen)
		}
	},

	setParticipantHandRaised(context, { sessionId, raisedHand }) {
		context.commit('setParticipantHandRaised', { sessionId, raisedHand })
	},

	setCachedBackgroundImageAverageColor(context, { videoBackgroundId, backgroundImageAverageColor }) {
		context.commit('setCachedBackgroundImageAverageColor', { videoBackgroundId, backgroundImageAverageColor })
	},

	/**
	 * Starts presentation mode.
	 *
	 * Switches off grid mode and closes the stripe.
	 * Remembers the call view state for after the end of the presentation.
	 *
	 * @param {object} context default store context;
	 */
	startPresentation(context) {
		// don't start twice, this would prevent multiple
		// screen shares to clear the last call view state
		if (context.getters.presentationStarted) {
			return
		}

		context.commit('presentationStarted', true)
		// switch off grid mode during presentation and collapse
		// the stripe to focus on the screen share, but continue remembering
		// the last state
		context.dispatch('setCallViewMode', {
			isGrid: false,
			isStripeOpen: false,
			clearLast: false,
		})
	},

	/**
	 * Stops presentation mode.
	 *
	 * Restores call view state from before starting the presentation, given
	 * that the last state was not cleared manually.
	 *
	 * @param {object} context default store context;
	 */
	stopPresentation(context) {
		if (!context.getters.presentationStarted) {
			return
		}

		// restore previous state
		context.dispatch('setCallViewMode', {
			isGrid: context.getters.lastIsGrid,
			isStripeOpen: context.getters.lastIsStripeOpen,
		})
		context.commit('presentationStarted', false)
	},

	dismissQualityWarningTooltip(context) {
		context.commit('setQualityWarningTooltipDismissed', { qualityWarningTooltipDismissed: true })
	},

	setVideoEffectsEnabled(context, enabled) {
		BrowserStorage.setItem('callprefs-' + context.getters.getToken() + '-videoeffectsenabled', enabled)
		context.commit('setVideoEffectsEnabled', enabled)
	},
}

export default { state, mutations, getters, actions }
