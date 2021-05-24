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

const state = {
	isGrid: false,
	selectedVideoPeerId: null,
	qualityWarningTooltipDismissed: false,
	backgroundImageAverageColorCache: {},
}

const getters = {
	isGrid: (state) => {
		return state.isGrid
	},
	selectedVideoPeerId: (state) => {
		return state.selectedVideoPeerId
	},
	isQualityWarningTooltipDismissed: (state) => state.qualityWarningTooltipDismissed,
	getCachedBackgroundImageAverageColor: (state) => (videoBackgroundId) => {
		return state.backgroundImageAverageColorCache[videoBackgroundId]
	},
}

const mutations = {

	isGrid(state, value) {
		state.isGrid = value
	},
	selectedVideoPeerId(state, value) {
		state.selectedVideoPeerId = value
	},
	setQualityWarningTooltipDismissed(state, { qualityWarningTooltipDismissed }) {
		state.qualityWarningTooltipDismissed = qualityWarningTooltipDismissed
	},
	setCachedBackgroundImageAverageColor(state, { videoBackgroundId, backgroundImageAverageColor }) {
		Vue.set(state.backgroundImageAverageColorCache, videoBackgroundId, backgroundImageAverageColor)
	},
	clearBackgroundImageAverageColorCache(state) {
		state.backgroundImageAverageColorCache = {}
	},
}

const actions = {
	isGrid(context, value) {
		context.commit('isGrid', value)
	},
	selectedVideoPeerId(context, value) {
		context.commit('selectedVideoPeerId', value)
	},

	joinCall(context, { token }) {
		context.commit('setQualityWarningTooltipDismissed', { qualityWarningTooltipDismissed: false })
	},

	leaveCall(context) {
		context.commit('clearBackgroundImageAverageColorCache')
	},

	setCachedBackgroundImageAverageColor(context, { videoBackgroundId, backgroundImageAverageColor }) {
		context.commit('setCachedBackgroundImageAverageColor', { videoBackgroundId, backgroundImageAverageColor })
	},

	dismissQualityWarningTooltip(context) {
		context.commit('setQualityWarningTooltipDismissed', { qualityWarningTooltipDismissed: true })
	},
}

export default { state, mutations, getters, actions }
