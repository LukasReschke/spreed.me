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
	<AppContentListItem
		:title="item.displayName"
		:to="{ name: 'conversation', params: { token: item.token }}"
		:class="{ 'has-unread-messages': item.unreadMessages }">
		<template v-slot:icon>
			<ConversationIcon
				:item="item"
				:hide-favorite="false"
				:hide-call="false" />
		</template>
		<template v-slot:subtitle>
			<strong v-if="item.unreadMessages">
				{{ conversationInformation }}
			</strong>
			<template v-else>
				{{ conversationInformation }}
			</template>
		</template>
		<AppNavigationCounter v-if="item.unreadMessages"
			slot="counter"
			class="counter"
			:highlighted="counterShouldBePrimary">
			<strong>{{ item.unreadMessages }}</strong>
		</AppNavigationCounter>
		<template slot="actions">
			<ActionButton v-if="canFavorite"
				:icon="iconFavorite"
				@click.prevent.exact="toggleFavoriteConversation">
				{{ labelFavorite }}
			</ActionButton>
			<ActionButton
				icon="icon-clippy"
				@click.stop.prevent="copyLinkToConversation">
				{{ t('spreed', 'Copy link') }}
			</ActionButton>

			<template v-if="!isMyNotesConversation">
				<ActionSeparator />

				<ActionText
					:title="t('spreed', 'Chat notifications')" />
				<ActionButton
					:class="{'forced-active': isNotifyAlways}"
					icon="icon-sound"
					@click.prevent.exact="setNotificationLevel(1)">
					{{ t('spreed', 'All messages') }}
				</ActionButton>
				<ActionButton
					:class="{'forced-active': isNotifyMention}"
					icon="icon-user"
					@click.prevent.exact="setNotificationLevel(2)">
					{{ t('spreed', '@-mentions only') }}
				</ActionButton>
				<ActionButton
					:class="{'forced-active': isNotifyNever}"
					icon="icon-sound-off"
					@click.prevent.exact="setNotificationLevel(3)">
					{{ t('spreed', 'Off') }}
				</ActionButton>
			</template>

			<ActionSeparator />

			<ActionButton v-if="canLeaveConversation"
				:icon="iconLeaveConversation"
				@click.prevent.exact="leaveConversation">
				{{ t('spreed', 'Leave conversation') }}
			</ActionButton>
			<ActionButton v-if="canDeleteConversation"
				icon="icon-delete-critical"
				class="critical"
				@click.prevent.exact="deleteConversation">
				{{ labelDeleteConversation }}
			</ActionButton>
		</template>
	</AppContentListItem>
</template>

<script>
import ActionButton from '@nextcloud/vue/dist/Components/ActionButton'
import ActionSeparator from '@nextcloud/vue/dist/Components/ActionSeparator'
import ActionText from '@nextcloud/vue/dist/Components/ActionText'
import AppContentListItem from './AppContentListItem/AppContentListItem'
import AppNavigationCounter from '@nextcloud/vue/dist/Components/AppNavigationCounter'
import ConversationIcon from './../../ConversationIcon'
import { joinConversation, removeCurrentUserFromConversation } from '../../../services/participantsService'
import {
	deleteConversation,
	setNotificationLevel,
} from '../../../services/conversationsService'
import { generateUrl } from '@nextcloud/router'
import { CONVERSATION, PARTICIPANT } from '../../../constants'

export default {
	name: 'Conversation',
	components: {
		ActionButton,
		ActionSeparator,
		ActionText,
		AppContentListItem,
		AppNavigationCounter,
		ConversationIcon,
	},
	props: {
		item: {
			type: Object,
			default: function() {
				return {
					token: '',
					participants: [],
					participantType: 0,
					unreadMessages: 0,
					unreadMention: false,
					objectType: '',
					type: 0,
					displayName: '',
					isFavorite: false,
					notificationLevel: 0,
					lastMessage: {},
				}
			},
		},
	},
	computed: {
		counterShouldBePrimary() {
			return this.item.unreadMention || (this.item.unreadMessages && this.item.type === CONVERSATION.TYPE.ONE_TO_ONE)
		},
		isMyNotesConversation() {
			return this.item.type === CONVERSATION.TYPE.NOTES
		},
		linkToConversation() {
			return window.location.protocol + '//' + window.location.host + generateUrl('/call/' + this.item.token)
		},
		canFavorite() {
			return this.item.participantType !== PARTICIPANT.TYPE.USER_SELF_JOINED
		},
		iconFavorite() {
			return this.item.isFavorite ? 'icon-star-dark' : 'icon-starred'
		},
		labelFavorite() {
			return this.item.isFavorite ? t('spreed', 'Remove from favorites') : t('spreed', 'Add to favorites')
		},
		isNotifyAlways() {
			return this.item.notificationLevel === PARTICIPANT.NOTIFY.ALWAYS
		},
		isNotifyMention() {
			return this.item.notificationLevel === PARTICIPANT.NOTIFY.MENTION
		},
		isNotifyNever() {
			return this.item.notificationLevel === PARTICIPANT.NOTIFY.NEVER
		},
		labelDeleteConversation() {
			if (this.item.type === CONVERSATION.TYPE.NOTES) {
				return t('spreed', 'Delete my notes')
			}
			return t('spreed', 'Delete conversation')
		},
		canDeleteConversation() {
			return this.item.canDeleteConversation
		},
		canLeaveConversation() {
			return this.item.canLeaveConversation
		},
		iconLeaveConversation() {
			if (this.canDeleteConversation) {
				return 'icon-close'
			}
			return 'icon-delete'
		},

		conversationInformation() {
			if (!Object.keys(this.lastChatMessage).length) {
				return ''
			}

			if (this.shortLastChatMessageAuthor === ''
				|| this.item.type === CONVERSATION.TYPE.NOTES) {
				return this.simpleLastChatMessage
			}

			if (this.lastChatMessage.actorId === this.$store.getters.getUserId()) {
				return t('spreed', 'You: {lastMessage}', {
					lastMessage: this.simpleLastChatMessage,
				}, undefined, {
					escape: false,
				})
			}

			if (this.item.type === CONVERSATION.TYPE.ONE_TO_ONE
				|| this.item.type === CONVERSATION.TYPE.CHANGELOG) {
				return this.simpleLastChatMessage
			}

			return t('spreed', '{actor}: {lastMessage}', {
				actor: this.shortLastChatMessageAuthor,
				lastMessage: this.simpleLastChatMessage,
			}, undefined, {
				escape: false,
			})
		},

		// The messages array for this conversation
		messages() {
			return this.$store.getters.messages(this.item.token)
		},

		// Get the last message for this conversation from the message store instead
		// of the conversations store. The message store is updated immediately,
		// while the conversations store is refreshed every 30 seconds. This allows
		// to display message previews in this component as soon as new messages are
		// received by the server.
		lastChatMessage() {
			const lastMessageTimestamp = this.item.lastMessage ? this.item.lastMessage.timestamp : 0

			if (Object.keys(this.messages).length > 0) {
				const messagesKeys = Object.keys(this.messages)
				const lastMessageId = messagesKeys[messagesKeys.length - 1]

				if (this.messages[lastMessageId].timestamp > lastMessageTimestamp) {
					return this.messages[lastMessageId]
				}
			}
			return this.item.lastMessage
		},

		/**
		 * This is a simplified version of the last chat message.
		 * Parameters are parsed without markup (just replaced with the name),
		 * e.g. no avatars on mentions.
		 * @returns {string} A simple message to show below the conversation name
		 */
		simpleLastChatMessage() {
			if (!Object.keys(this.lastChatMessage).length) {
				return ''
			}

			const params = this.lastChatMessage.messageParameters
			let subtitle = this.lastChatMessage.message.trim()

			// We don't really use rich objects in the subtitle, instead we fall back to the name of the item
			Object.keys(params).forEach((parameterKey) => {
				subtitle = subtitle.replace('{' + parameterKey + '}', params[parameterKey].name)
			})

			return subtitle
		},
		/**
		 * @returns {string} Part of the name until the first space
		 */
		shortLastChatMessageAuthor() {
			if (!Object.keys(this.lastChatMessage).length
				|| this.lastChatMessage.systemMessage.length) {
				return ''
			}

			let author = this.lastChatMessage.actorDisplayName.trim()
			const spacePosition = author.indexOf(' ')
			if (spacePosition !== -1) {
				author = author.substring(0, spacePosition)
			}

			if (author.length === 0 && this.lastChatMessage.actorType === 'guests') {
				return t('spreed', 'Guest')
			}

			return author
		},
	},
	methods: {
		async copyLinkToConversation() {
			try {
				await this.$copyText(this.linkToConversation)
				OCP.Toast.success(t('spreed', 'Conversation link copied to clipboard.'))
			} catch (error) {
				OCP.Toast.error(t('spreed', 'The link could not be copied.'))
			}
		},
		async joinConversation() {
			await joinConversation(this.item.token)
			this.$store.dispatch('markConversationRead', this.item.token)
		},
		/**
		 * Deletes the conversation.
		 */
		async deleteConversation() {
			OC.dialogs.confirm(
				t('spreed', 'Do you really want to delete "{displayName}"?', this.item),
				t('spreed', 'Delete conversation'),
				async function(decision) {
					if (!decision) {
						return
					}

					if (this.item.token === this.$store.getters.getToken()) {
						this.$router.push('/apps/spreed')
						this.$store.dispatch('updateToken', '')
					}

					try {
						await deleteConversation(this.item.token)
						// If successful, deletes the conversation from the store
						this.$store.dispatch('deleteConversation', this.item)
					} catch (error) {
						console.debug(`error while deleting conversation ${error}`)
					}
				}.bind(this)
			)
		},
		/**
		 * Deletes the current user from the conversation.
		 */
		async leaveConversation() {
			try {
				await removeCurrentUserFromConversation(this.item.token)
				// If successful, deletes the conversation from the store
				this.$store.dispatch('deleteConversation', this.item)
			} catch (error) {
				if (error.response && error.response.status === 400) {
					OCP.Toast.error(t('spreed', 'You need to promote a new moderator before you can leave the conversation.'))
				} else {
					console.debug(`error while removing yourself from conversation ${error}`)
				}
			}
		},
		async toggleFavoriteConversation() {
			this.$store.dispatch('toggleFavorite', this.item)
		},
		/**
		 * Set the notification level for the conversation
		 * @param {int} level The notification level to set.
		 */
		async setNotificationLevel(level) {
			await setNotificationLevel(this.item.token, level)
			this.item.notificationLevel = level
		},
	},
}
</script>

<style lang="scss" scoped>
::v-deep .counter {
	font-size: 12px;
	/*
	 * Always add the bubble
	 */
	padding: 4px 6px;
	border-radius: 10px;

	&:not(.app-navigation-entry__counter--highlighted) {
		background-color: var(--color-background-darker);
	}

	span {
		padding: 2px 6px;
	}
}

.has-unread-messages {
	::v-deep .acli__content__line-one__title {
		font-weight: bold;
	}
}

.critical {
	::v-deep .action-button__text {
		color: var(--color-error) !important;
	}
}

.scroller {
	flex: 1 0;
}

.ellipsis {
	text-overflow: ellipsis;
}

.forced-active {
	box-shadow: inset 4px 0 var(--color-primary-element);
}
</style>
