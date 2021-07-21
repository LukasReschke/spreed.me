<!--
  - @copyright Copyright (c) 2021 Marco Ambrosini <marcoambrosini@pm.me>
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
	<div>
		<div class="app-settings-section__hint">
			{{ t('spreed', 'Be careful, these actions cannot be undone.') }}
		</div>
		<button v-if="canLeaveConversation"
			@click.prevent.exact="leaveConversation">
			{{ t('spreed', 'Leave conversation') }}
		</button>
		<button v-if="canDeleteConversation"
			class="critical error"
			@click.prevent.exact="deleteConversation">
			{{ t('spreed', 'Delete conversation') }}
		</button>
		<button
			class="critical error"
			@click.prevent.exact="clearChatHistory">
			{{ t('spreed', 'Delete conversation') }}
		</button>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'

export default {
	name: 'DangerZone',

	props: {
		conversation: {
			type: Object,
			required: true,
		},

		canLeaveConversation: {
			type: Boolean,
			required: true,
		},

		canDeleteConversation: {
			type: Boolean,
			required: true,
		},
	},

	computed: {
		token() {
			return this.conversation.token
		},
	},

	methods: {

		hideConversationSettings() {
			emit('hide-conversation-settings')
		},
		/**
		 * Deletes the current user from the conversation.
		 */
		async leaveConversation() {
			try {
				await this.$store.dispatch('removeCurrentUserFromConversation', { token: this.token })
				this.hideConversationSettings()
			} catch (error) {
				if (error?.response?.status === 400) {
					showError(t('spreed', 'You need to promote a new moderator before you can leave the conversation.'))
				} else {
					console.error(`error while removing yourself from conversation ${error}`)
				}
			}
		},

		/**
		 * Deletes the conversation.
		 */
		async deleteConversation() {
			OC.dialogs.confirm(
				t('spreed', 'Do you really want to delete "{displayName}"?', this.conversation),
				t('spreed', 'Delete conversation'),
				async function(decision) {
					if (!decision) {
						return
					}

					if (this.token === this.$store.getters.getToken()) {
						this.$router.push({ name: 'root' })
						this.$store.dispatch('updateToken', '')
					}

					try {
						await this.$store.dispatch('deleteConversationFromServer', { token: this.token })
						// Close the settings
						this.hideConversationSettings()
					} catch (error) {
						console.debug(`error while deleting conversation ${error}`)
						showError(t('spreed', 'Error while deleting conversation'))
					}
				}.bind(this)
			)
		},

		/**
		 * Clears the chat history
		 */
		async clearChatHistory() {
			OC.dialogs.confirm(
				t('spreed', 'Do you really want to delete all messages for "{displayName}"?', this.conversation),
				t('spreed', 'Delete all chat messages'),
				async function(decision) {
					if (!decision) {
						return
					}

					if (this.token === this.$store.getters.getToken()) {
						this.$router.push({ name: 'root' })
						this.$store.dispatch('updateToken', '')
					}

					try {
						await this.$store.dispatch('clearConversationHistory', { token: this.token })
						// Close the settings
						this.hideConversationSettings()
					} catch (error) {
						console.debug(`error while clearing chat history ${error}`)
						showError(t('spreed', 'Error while clearing chat history'))
					}
				}.bind(this)
			)
		},
	},
}
</script>

<style lang="scss" scoped>
button {
	height: 44px;
	border: none;
}

</style>
