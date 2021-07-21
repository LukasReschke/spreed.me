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
	<div class="forwarder">
		<!-- First step of the flow: selection of the room to which forward the
		message to -->
		<RoomSelector
			v-if="!showForwardedConfirmation"
			container="#content-vue"
			:show-postable-only="true"
			:dialog-title="dialogTitle"
			:dialog-subtitle="dialogSubtitle"
			@select="setselectedConversationToken"
			@close="handleClose" />

		<!-- Second step of the flow: confirmation modal that gives the user
		the possibility to direclty route to the conversation to which the
		message has been forwarded -->
		<Modal v-else
			@close="handleClose">
			<EmptyContent icon="icon-checkmark" class="forwarded-confirmation__emptycontent">
				<template #desc>
					{{ t('spreed', 'The message has been forwarded to {selectedConversationName}', { selectedConversationName }) }}
				</template>
			</EmptyContent>
			<div class="forwarded-confirmation__navigation">
				<button @click="handleClose">
					{{ t('spreed', 'Dismiss') }}
				</button>
				<button class="primary" @click="openConversation">
					{{ t('spreed', 'Go to conversation') }}
				</button>
			</div>
		</Modal>
	</div>
</template>

<script>
import RoomSelector from '../../../../../views/RoomSelector.vue'
import EmptyContent from '@nextcloud/vue/dist/Components/EmptyContent'
import Modal from '@nextcloud/vue/dist/Components/Modal'

export default {
	name: 'Forwarder',

	components: {
		RoomSelector,
		EmptyContent,
		Modal,
	},

	props: {
		/**
		 * The message to be forwarded
		 */
		messageObject: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			selectedConversationToken: null,
			showForwardedConfirmation: false,
			forwardedMessageID: '',
		}
	},

	computed: {
		dialogTitle() {
			return t('spreed', 'Forward message')
		},

		dialogSubtitle() {
			return t('spreed', 'Choose a conversation to forward the selected message.')
		},

		selectedConversationName() {
			return this.$store.getters?.conversation(this.selectedConversationToken).name
		},
	},

	methods: {
		async setselectedConversationToken(token) {
			this.selectedConversationToken = token
			const response = await this.$store.dispatch('forwardMessage', {
				token,
				message: this.messageObject,
			})
			this.showForwardedConfirmation = true
			this.forwardedMessageID = response.data.ocs.data.id
		},

		openConversation() {

			this.$router.push({
				name: 'conversation',
				hash: `#message_${this.forwardedMessageID}`,
				params: {
					token: `${this.selectedConversationToken}`,
			 },
			})
				.catch(err => console.debug(`Error while pushing the new conversation's route: ${err}`))
			this.showForwardedConfirmation = false
			this.forwardedMessageID = ''
		},

		handleClose() {
			this.$emit('close')
		},
	},
}
</script>

<style lang="scss" scoped>

.forwarded-confirmation {
	&__emptycontent {
		width: 280px;
		text-align: center;
		margin: 20px !important;
	}
	&__navigation {
		display: flex;
		justify-content: space-between;
		padding: 12px;
		button {
			height: 44px;
		}
	}
}

</style>
