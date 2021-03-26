<!--
  - @copyright Copyright (c) 2020 Vincent Petry <vincent@nextcloud.com>
  -
  - @author Vincent Petry <vincent@nextcloud.com>
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
	<div class="app-settings-subsection">
		<div id="moderation_settings_lock_conversation_hint" class="app-settings-section__hint">
			{{ t('spreed', 'Locking the conversation prevents anyone to post messages or start calls.') }}
		</div>
		<div v-if="hasCall" class="app-settings-section__hint">
			{{ t('spreed', 'This will also terminate the ongoing call.') }}
		</div>
		<div>
			<h3>{{ t('spreed', 'Limit writing to a conversation') }}</h3>
			<p>
				<Multiselect id="limit_writing"
					v-model="writingConversationSelected"
					:options="writingConversationOptions"
					:placeholder="t('spreed', 'Limit writing to conversations')"
					label="label"
					track-by="value"
					:disabled="isReadOnlyStateLoading"
					@input="saveWritingConversations" />
			</p>
		</div>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { CONVERSATION } from '../../constants'
import Multiselect from '@nextcloud/vue/dist/Components/Multiselect'

const writingConversationOptions = [
	{ value: 0, label: t('spreed', 'Everyone can write') },
	{ value: 2, label: t('spreed', 'Only moderators can write') },
	{ value: 1, label: t('spreed', 'Read only') },
]

export default {
	name: 'LockingSettings',

	components: {
		Multiselect,
	},

	props: {
		token: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			isReadOnlyStateLoading: false,
			writingConversationOptions,
			writingConversationSelected: [],
			loadingWritingConversations: false,
		}
	},

	computed: {
		hasCall() {
			return this.conversation.hasCall
		},

		conversation() {
			return this.$store.getters.conversation(this.token) || this.$store.getters.dummyConversation
		},

		isReadOnly() {
			return this.conversation.readOnly === CONVERSATION.STATE.READ_ONLY
		},
	},

	mounted() {
		this.writingConversationSelected = this.writingConversationOptions[this.conversation.readOnly]
	},

	methods: {
		async saveWritingConversations() {
			const newReadOnly = this.writingConversationSelected.value
			this.isReadOnlyStateLoading = true
			try {
				await this.$store.dispatch('setReadOnlyState', {
					token: this.token,
					readOnly: newReadOnly,
				})
				if (newReadOnly) {
					showSuccess(t('spreed', 'You locked the conversation'))
				} else {
					showSuccess(t('spreed', 'You unlocked the conversation'))
				}
			} catch (e) {
				if (newReadOnly) {
					console.error('Error occurred when locking the conversation', e)
					showError(t('spreed', 'Error occurred when locking the conversation'))
				} else {
					console.error('Error updating read-only state', e)
					showError(t('spreed', 'Error occurred when unlocking the conversation'))
				}
			}
			this.isReadOnlyStateLoading = false
		},
	},

}
</script>

<style lang="scss" scoped>
	.multiselect {
		width: 100%;
	}
</style>
