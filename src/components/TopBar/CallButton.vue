<!--
  - @copyright Copyright (c) 2019 Marco Ambrosini <marcoambrosini@pm.me>
  -
  - @author Marco Ambrosini <marcoambrosini@pm.me>
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
	<button v-if="showStartCallButton"
		v-tooltip="{
			placement: 'auto',
			trigger: 'hover',
			content: startCallToolTip,
			autoHide: false,
			html: true
		}"
		:disabled="startCallButtonDisabled || loading || blockCalls"
		class="top-bar__button"
		:class="startCallButtonClasses"
		@click="joinCall">
		<span
			class="icon"
			:class="startCallIcon" />
		{{ startCallLabel }}
	</button>
	<button v-else-if="showLeaveCallButton"
		class="top-bar__button error"
		:disabled="loading"
		@click="leaveCall">
		<span
			class="icon"
			:class="leaveCallIcon" />
		{{ leaveCallLabel }}
	</button>
</template>

<script>
import { CONVERSATION, PARTICIPANT, WEBINAR } from '../../constants'
import browserCheck from '../../mixins/browserCheck'
import isInCall from '../../mixins/isInCall'
import participant from '../../mixins/participant'
import Tooltip from '@nextcloud/vue/dist/Directives/Tooltip'
import { emit } from '@nextcloud/event-bus'

export default {
	name: 'CallButton',

	directives: {
		Tooltip,
	},

	mixins: [
		browserCheck,
		isInCall,
		participant,
	],

	data() {
		return {
			loading: false,
		}
	},

	computed: {
		token() {
			return this.$store.getters.getToken()
		},
		isNextcloudTalkHashDirty() {
			return this.$store.getters.isNextcloudTalkHashDirty
		},

		conversation() {
			return this.$store.getters.conversation(this.token) || this.$store.getters.dummyConversation
		},

		isBlockedByLobby() {
			return this.conversation.lobbyState === WEBINAR.LOBBY.NON_MODERATORS
				&& !this.isParticipantTypeModerator(this.conversation.participantType)
		},

		startCallButtonDisabled() {
			return (!this.conversation.canStartCall
					&& !this.conversation.hasCall)
				|| this.isBlockedByLobby
				|| this.conversation.readOnly
				|| this.isNextcloudTalkHashDirty
				|| !this.currentConversationIsJoined
		},

		leaveCallLabel() {
			return t('spreed', 'Leave call')
		},

		leaveCallIcon() {
			if (this.loading) {
				return 'icon-loading-small'
			}

			return 'icon-leave-call'
		},

		startCallLabel() {
			if (this.conversation.hasCall && !this.isBlockedByLobby) {
				return t('spreed', 'Join call')
			}

			return t('spreed', 'Start call')
		},

		startCallToolTip() {
			if (this.isNextcloudTalkHashDirty) {
				return t('spreed', 'Nextcloud Talk was updated, you need to reload the page before you can start or join a call.')
			}

			if (this.callButtonTooltipText) {
				return this.callButtonTooltipText
			}

			if (!this.conversation.canStartCall && !this.conversation.hasCall) {
				return t('spreed', 'You will be able to join the call only after a moderator starts it.')
			}

			return ''
		},

		startCallIcon() {
			if (this.loading) {
				return 'icon-loading-small'
			}

			if (this.conversation.hasCall && !this.isBlockedByLobby) {
				return 'icon-incoming-call'
			}

			return 'icon-start-call'
		},

		startCallButtonClasses() {
			return {
				primary: !this.conversation.hasCall && !this.isBlockedByLobby,
				success: this.conversation.hasCall && !this.isBlockedByLobby,
			}
		},

		showStartCallButton() {
			return this.conversation.readOnly === CONVERSATION.STATE.READ_WRITE
				&& !this.isInCall
		},

		showLeaveCallButton() {
			return this.conversation.readOnly === CONVERSATION.STATE.READ_WRITE
				&& this.isInCall
		},

		currentConversationIsJoined() {
			return this.$store.getters.currentConversationIsJoined
		},
	},

	methods: {
		isParticipantTypeModerator(participantType) {
			return [PARTICIPANT.TYPE.OWNER, PARTICIPANT.TYPE.MODERATOR, PARTICIPANT.TYPE.GUEST_MODERATOR].indexOf(participantType) !== -1
		},

		async joinCall() {
			let flags = PARTICIPANT.CALL_FLAG.IN_CALL
			if (this.conversation.publishingPermissions === PARTICIPANT.PUBLISHING_PERMISSIONS.ALL) {
				flags |= PARTICIPANT.CALL_FLAG.WITH_AUDIO | PARTICIPANT.CALL_FLAG.WITH_VIDEO
			}

			console.info('Joining call')
			this.loading = true
			// Close navigation
			emit('toggle-navigation', {
				open: false,
			})
			await this.$store.dispatch('joinCall', {
				token: this.token,
				participantIdentifier: this.$store.getters.getParticipantIdentifier(),
				flags: flags,
			})
			this.loading = false
		},

		async leaveCall() {
			console.info('Leaving call')
			// Remove selected participant
			this.$store.dispatch('selectedVideoPeerId', null)
			this.loading = true
			// Open navigation
			emit('toggle-navigation', {
				open: true,
			})
			await this.$store.dispatch('leaveCall', {
				token: this.token,
				participantIdentifier: this.$store.getters.getParticipantIdentifier(),
			})
			this.loading = false
		},
	},
}
</script>

<style lang="scss" scoped>
.top-bar__button {
	height: 44px;
	padding: 0 16px;

	.icon {
		opacity: 1;
		margin-right: 8px;
	}
}

.success {
	color: white;
	background-color: var(--color-success);
	border: 1px solid var(--color-success);

	&:hover,
	&:focus,
	&:active {
		border: 1px solid var(--color-success) !important;
	}
}
</style>
