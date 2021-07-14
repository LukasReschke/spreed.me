<!--
  - @copyright Copyright (c) 2019 Joas Schilling <coding@schilljs.com>
  -
  - @author Joas Schilling <coding@schilljs.com>
  -
  - @license GNU AGPL version 3 or any later version
  -
  - This program is free software: you can redistribute it and/or modify
  - it under the terms of the GNU Affero General Public License as
  - published by the Free Software Foundation, either version 3 of the
  - License, or (at your option) any later version.
  -
  - This program is distributed in the hope that it will be useful,
  - but WITHOUT ANY WARRANTY; without even the implied warranty of
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program. If not, see <http://www.gnu.org/licenses/>.
-->

<template>
	<div>
		<ParticipantsList
			v-if="participantsList.length"
			:items="participantsList"
			:loading="!participantsInitialised" />
		<Hint v-else :hint="t('spreed', 'No search results')" />
	</div>
</template>

<script>

import ParticipantsList from '../ParticipantsList/ParticipantsList'
import { ATTENDEE, PARTICIPANT } from '../../../../constants'
import UserStatus from '../../../../mixins/userStatus'
import Hint from '../../../Hint'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'

export default {
	name: 'CurrentParticipants',

	components: {
		ParticipantsList,
		Hint,
	},

	mixins: [
		UserStatus,
	],

	props: {
		searchText: {
			type: String,
			default: '',
		},
		participantsInitialised: {
			type: Boolean,
			default: true,
		},
	},

	computed: {
		token() {
			return this.$store.getters.getToken()
		},

		/**
		 * Gets the participants array.
		 *
		 * @returns {array}
		 */
		participantsList() {
			let participants = this.$store.getters.participantsList(this.token)

			if (this.searchText !== '') {
				const lowerSearchText = this.searchText.toLowerCase()
				participants = participants.filter(participant => {
					return participant.displayName.toLowerCase().indexOf(lowerSearchText) !== -1
						|| (participant.actorType !== 'guests'
							&& participant.actorId.toLowerCase().indexOf(lowerSearchText) !== -1)
				})
			}

			return participants.slice().sort(this.sortParticipants)
		},
	},

	mounted() {
		subscribe('user_status:status.updated', this.userStatusUpdated)
	},

	beforeDestroyed() {
		unsubscribe('user_status:status.updated', this.userStatusUpdated)
	},

	methods: {
		userStatusUpdated(state) {
			this.$store.dispatch('updateUser', {
				token: this.token,
				participantIdentifier: {
					actorType: 'users',
					actorId: state.userId,
				},
				updatedData: {
					status: state.status,
					statusIcon: state.icon,
					statusMessage: state.message,
				},
			})
		},

		/**
		 * Sort two participants by:
		 * - participants before groups
		 * - online status
		 * - in call
		 * - who raised hand first
		 * - type (moderators before normal participants)
		 * - user status (dnd at the end)
		 * - display name
		 *
		 * @param {object} participant1 First participant
		 * @param {int} participant1.participantType First participant type
		 * @param {string} participant1.sessionId First participant session
		 * @param {string} participant1.displayName First participant display name
		 * @param {string} participant1.status First participant user status
		 * @param {string} participant1.actorType First participant actor type
		 * @param {int} participant1.inCall First participant in call flag
		 * @param {object} participant2 Second participant
		 * @param {int} participant2.participantType Second participant type
		 * @param {string} participant2.sessionId Second participant session
		 * @param {string} participant2.displayName Second participant display name
		 * @param {string} participant2.actorType Second participant actor type
		 * @param {string} participant2.status Second participant user status
		 * @param {int} participant2.inCall Second participant in call flag
		 * @returns {number}
		 */
		sortParticipants(participant1, participant2) {
			const p1IsCircle = participant1.actorType === ATTENDEE.ACTOR_TYPE.CIRCLES
			const p2IsCircle = participant2.actorType === ATTENDEE.ACTOR_TYPE.CIRCLES

			if (p1IsCircle !== p2IsCircle) {
				// Circles below participants and groups
				return p2IsCircle ? -1 : 1
			}

			const p1IsGroup = participant1.actorType === ATTENDEE.ACTOR_TYPE.GROUPS
			const p2IsGroup = participant2.actorType === ATTENDEE.ACTOR_TYPE.GROUPS

			if (p1IsGroup !== p2IsGroup) {
				// Groups below participants
				return p2IsGroup ? -1 : 1
			}

			const hasSessions1 = !!participant1.sessionIds.length
			const hasSessions2 = !!participant2.sessionIds.length
			/**
			 * For now the user status is not overwriting the online-offline status anymore
			 * It felt too weird having users appear as offline but they are in the call or chat actively
			if (participant1.status === 'offline') {
				hasSessions1 = false
			}
			if (participant2.status === 'offline') {
				hasSessions2 = false
			}
			 */

			if (!hasSessions1) {
				if (hasSessions2) {
					return 1
				}
			} else if (!hasSessions2) {
				return -1
			}

			const p1inCall = participant1.inCall !== PARTICIPANT.CALL_FLAG.DISCONNECTED
			const p2inCall = participant2.inCall !== PARTICIPANT.CALL_FLAG.DISCONNECTED
			if (p1inCall !== p2inCall) {
				return p1inCall ? -1 : 1
			}

			const p1HandRaised = this.$store.getters.getParticipantRaisedHand(participant1.sessionIds)
			const p2HandRaised = this.$store.getters.getParticipantRaisedHand(participant2.sessionIds)
			if (p1HandRaised.state !== p2HandRaised.state) {
				return p1HandRaised.state ? -1 : 1
			}
			// both had raised hands, then pick whoever raised hand first
			if (p1HandRaised) {
				// use MAX_VALUE if not defined to avoid zeroes making it look like
				// one raised their hands at the birth of time...
				const t1 = p1HandRaised.timestamp || Number.MAX_VALUE
				const t2 = p2HandRaised.timestamp || Number.MAX_VALUE
				if (t1 !== t2) {
					return t1 - t2
				}
			}

			const moderatorTypes = [PARTICIPANT.TYPE.OWNER, PARTICIPANT.TYPE.MODERATOR, PARTICIPANT.TYPE.GUEST_MODERATOR]
			const moderator1 = moderatorTypes.indexOf(participant1.participantType) !== -1
			const moderator2 = moderatorTypes.indexOf(participant2.participantType) !== -1

			if (moderator1 !== moderator2) {
				return moderator1 ? -1 : 1
			}

			const participant1Away = this.isNotAvailable(participant1)
			const participant2Away = this.isNotAvailable(participant2)
			if (participant1Away !== participant2Away) {
				return participant1Away ? 1 : -1
			}

			return participant1.displayName.localeCompare(participant2.displayName)
		},
	},
}
</script>
