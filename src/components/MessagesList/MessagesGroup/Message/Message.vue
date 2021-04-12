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

<docs>
This component displays the text inside the message component and can be used for
the main body of the message as well as a quote.
</docs>

<template>
	<li
		:id="`message_${id}`"
		ref="message"
		tabindex="-1"
		class="message"
		:data-message-id="id"
		:data-seen="seen"
		:data-next-message-id="nextMessageId"
		:data-previous-message-id="previousMessageId"
		@keydown.up.prevent="jumpToPrevious"
		@keydown.down.prevent="jumpToNext"
		@focus="isFocussed=true"
		@blur="handleBlur">
		<div
			:class="{'hover': showHover, 'system' : isSystemMessage}"
			class="message-body"
			@mouseover="handleMouseover"
			@mouseleave="handleMouseleave">
			<div v-if="isFirstMessage && showAuthor"
				class="message-body__author"
				role="heading"
				aria-level="4">
				{{ actorDisplayName }}
			</div>
			<div
				ref="messageMain"
				class="message-body__main">
				<div v-if="isSingleEmoji"
					class="message-body__main__text">
					<Quote v-if="parent" :parent-id="parent" v-bind="quote" />
					<div class="single-emoji">
						{{ message }}
					</div>
				</div>
				<div v-else-if="showJoinCallButton" class="message-body__main__text call-started">
					<RichText :text="message" :arguments="richParameters" :autolink="true" />
					<CallButton />
				</div>
				<div v-else-if="isDeletedMessage" class="message-body__main__text deleted-message">
					<RichText :text="message" :arguments="richParameters" :autolink="true" />
				</div>
				<div v-else class="message-body__main__text" :class="{'system-message': isSystemMessage}">
					<Quote v-if="parent" :parent-id="parent" v-bind="quote" />
					<RichText :text="message" :arguments="richParameters" :autolink="true" />
				</div>
				<div v-if="!isDeletedMessage" class="message-body__main__right">
					<span
						v-tooltip.auto="messageDate"
						class="date"
						:style="{'visibility': hasDate ? 'visible' : 'hidden'}"
						:class="{'date--self': showSentIcon}">{{ messageTime }}</span>
					<!-- Message delivery status indicators -->
					<div v-if="sendingFailure"
						v-tooltip.auto="sendingErrorIconTooltip"
						class="message-status sending-failed"
						:class="{'retry-option': sendingErrorCanRetry}"
						:aria-label="sendingErrorIconTooltip"
						tabindex="0"
						@mouseover="showReloadButton = true"
						@focus="showReloadButton = true"
						@mouseleave="showReloadButton = true"
						@blur="showReloadButton = true">
						<button
							v-if="sendingErrorCanRetry && showReloadButton"
							class="nc-button nc-button__main--dark"
							@click="handleRetry">
							<Reload
								decorative
								title=""
								:size="16" />
						</button>
						<AlertCircle v-else
							decorative
							title=""
							:size="16" />
					</div>
					<div v-else-if="isTemporary && !isTemporaryUpload || isDeleting"
						v-tooltip.auto="loadingIconTooltip"
						class="icon-loading-small message-status"
						:aria-label="loadingIconTooltip" />
					<div v-else-if="showCommonReadIcon"
						v-tooltip.auto="commonReadIconTooltip"
						class="message-status"
						:aria-label="commonReadIconTooltip">
						<CheckAll decorative
							title=""
							:size="16" />
					</div>
					<div v-else-if="showSentIcon"
						v-tooltip.auto="sentIconTooltip"
						class="message-status"
						:aria-label="sentIconTooltip">
						<Check decorative
							title=""
							:size="16" />
					</div>
					<!-- Message Actions -->
					<div
						v-if="hasActions"
						v-show="showActions"
						class="message-body__main__right__actions"
						:class="{ 'tall' : isTallEnough, 'hidden-visually': !showActions }">
						<Actions
							v-show="isReplyable"
							@focus="handleActionFocus"
							@blur="handleActionBlur">
							<ActionButton
								icon="icon-reply"
								@focus="handleActionFocus"
								@blur="handleActionBlur"
								@click.stop="handleReply">
								{{ t('spreed', 'Reply') }}
							</ActionButton>
						</Actions>
						<Actions
							:force-menu="true"
							container="#content-vue"
							@focus="handleActionFocus"
							@blur="handleActionBlur"
							@open="handleActionMenuOpen"
							@close="handleActionMenuClose">
							<ActionButton
								v-if="isPrivateReplyable"
								icon="icon-user"
								:close-after-click="true"
								@click.stop="handlePrivateReply">
								{{ t('spreed', 'Reply privately') }}
							</ActionButton>
							<ActionButton
								icon="icon-external"
								:close-after-click="true"
								@click.stop.prevent="handleCopyMessageLink">
								{{ t('spreed', 'Copy message link') }}
							</ActionButton>
							<ActionButton
								:close-after-click="true"
								@click.stop="handleMarkAsUnread">
								{{ t('spreed', 'Mark as unread') }}
							</ActionButton>
							<ActionSeparator v-if="messageActions.length > 0" />
							<template
								v-for="action in messageActions">
								<ActionButton
									:key="action.label"
									:icon="action.icon"
									:close-after-click="true"
									@click="action.callback(messageAPIData)">
									{{ action.label }}
								</ActionButton>
							</template>
							<template v-if="isDeleteable">
								<ActionSeparator />
								<ActionButton
									icon="icon-delete"
									:close-after-click="true"
									@click.stop="handleDelete">
									{{ t('spreed', 'Delete') }}
								</ActionButton>
							</template>
						</Actions>
					</div>
				</div>
			</div>
		</div>
		<div v-if="isLastReadMessage"
			v-observe-visibility="lastReadMessageVisibilityChanged">
			<div class="new-message-marker">
				<span>{{ t('spreed', 'Unread messages') }}</span>
			</div>
		</div>
	</li>
</template>

<script>
import ActionButton from '@nextcloud/vue/dist/Components/ActionButton'
import Actions from '@nextcloud/vue/dist/Components/Actions'
import ActionSeparator from '@nextcloud/vue/dist/Components/ActionSeparator'
import Tooltip from '@nextcloud/vue/dist/Directives/Tooltip'
import CallButton from '../../../TopBar/CallButton'
import DeckCard from './MessagePart/DeckCard'
import DefaultParameter from './MessagePart/DefaultParameter'
import FilePreview from './MessagePart/FilePreview'
import Mention from './MessagePart/Mention'
import RichText from '@juliushaertl/vue-richtext'
import AlertCircle from 'vue-material-design-icons/AlertCircle'
import Check from 'vue-material-design-icons/Check'
import CheckAll from 'vue-material-design-icons/CheckAll'
import Reload from 'vue-material-design-icons/Reload'
import Quote from '../../../Quote'
import isInCall from '../../../../mixins/isInCall'
import participant from '../../../../mixins/participant'
import { EventBus } from '../../../../services/EventBus'
import emojiRegex from 'emoji-regex'
import { PARTICIPANT, CONVERSATION, ATTENDEE } from '../../../../constants'
import moment from '@nextcloud/moment'
import {
	showError,
	showSuccess,
	showWarning,
	TOAST_DEFAULT_TIMEOUT,
} from '@nextcloud/dialogs'
import { createOneToOneConversation } from '../../../../services/conversationsService'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'Message',

	directives: {
		tooltip: Tooltip,
	},

	components: {
		Actions,
		ActionButton,
		CallButton,
		Quote,
		RichText,
		AlertCircle,
		Check,
		CheckAll,
		Reload,
		ActionSeparator,
	},

	mixins: [
		participant,
		isInCall,
	],

	inheritAttrs: false,

	props: {
		/**
		 * The actor type of the sender of the message.
		 */
		actorType: {
			type: String,
			required: true,
		},
		/**
		 * The actor id of the sender of the message.
		 */
		actorId: {
			type: String,
			required: true,
		},
		/**
		 * The display name of the sender of the message.
		 */
		actorDisplayName: {
			type: String,
			required: true,
		},
		/**
		 * The message or quote text.
		 */
		message: {
			type: String,
			required: true,
		},
		/**
		 * The parameters of the rich object message
		 */
		messageParameters: {
			type: [Array, Object],
			required: true,
		},
		/**
		 * The message timestamp.
		 */
		timestamp: {
			type: Number,
			default: 0,
		},
		/**
		 * The message id.
		 */
		id: {
			type: [String, Number],
			required: true,
		},
		/**
		 * If true, it displays the message author on top of the message.
		 */
		showAuthor: {
			type: Boolean,
			default: true,
		},
		/**
		 * Specifies if the message is temporary in order to display the spinner instead
		 * of the message time.
		 */
		isTemporary: {
			type: Boolean,
			required: true,
		},
		/**
		 * Specifies if the message is the first of a group of same-author messages.
		 */
		isFirstMessage: {
			type: Boolean,
			required: true,
		},
		/**
		 * Specifies if the message can be replied to.
		 */
		isReplyable: {
			type: Boolean,
			required: true,
		},
		/**
		 * The conversation token.
		 */
		token: {
			type: String,
			required: true,
		},
		/**
		 * The conversation token.
		 */
		systemMessage: {
			type: String,
			required: true,
		},
		/**
		 * The type of the message.
		 */
		messageType: {
			type: String,
			required: true,
		},
		/**
		 * The parent message's id.
		 */
		parent: {
			type: Number,
			default: 0,
		},
		sendingFailure: {
			type: String,
			default: '',
		},

		previousMessageId: {
			type: [String, Number],
			default: 0,
		},

		nextMessageId: {
			type: [String, Number],
			default: 0,
		},

		lastReadMessageId: {
			type: [String, Number],
			default: 0,
		},
	},

	data() {
		return {
			// Is tall enough for both actions and date upon hovering
			isTallEnough: false,
			showReloadButton: false,
			isDeleting: false,
			// whether the message was seen, only used if this was marked as last read message
			seen: false,
			isFocussed: false,
			isHovered: false,
			isActionFocussed: false,
			isActionMenuOpen: false,
		}
	},

	computed: {
		isLastReadMessage() {
			// note: not reading lastReadMessage from the conversation as we want to define it externally
			// to have closer control on marker's visibility behavior
			return this.id === this.lastReadMessageId
				&& (!this.conversation.lastMessage
				|| this.id !== this.conversation.lastMessage.id)
		},

		messageObject() {
			return this.$store.getters.message(this.token, this.id)
		},

		hasActionsMenu() {
			return (this.isPrivateReplyable || this.isReplyable || this.isDeleteable || this.messageActions.length > 0) && !this.isConversationReadOnly
		},

		showActions() {
			console.log('isFocussed: ', this.isFocussed, ' isHovered: ', this.isHovered, ' isActionFocussed: ', this.isActionFocussed, ' isActionMenuOpen: ', this.isActionMenuOpen)
			return (this.isFocussed || this.isHovered || this.isActionFocussed || this.isActionMenuOpen)
		},
		showHover() {
			if (this.isFocussed) {
				// keyboard focus also allowed on system messages
				return true
			}
			return this.showActions && !this.isSystemMessage && !this.isDeletedMessage
		},

		isConversationReadOnly() {
			return this.conversation.readOnly === CONVERSATION.STATE.READ_ONLY
		},

		isSystemMessage() {
			return this.systemMessage !== ''
		},

		isDeletedMessage() {
			return this.messageType === 'comment_deleted'
		},

		messageTime() {
			return moment(this.timestamp * 1000).format('LT')
		},

		messageDate() {
			return moment(this.timestamp * 1000).format('LL')
		},

		quote() {
			return this.parent && this.$store.getters.message(this.token, this.parent)
		},

		conversation() {
			return this.$store.getters.conversation(this.token)
		},

		showCommonReadIcon() {
			return this.conversation.lastCommonReadMessage >= this.id
				&& this.showSentIcon && !this.isDeletedMessage
		},

		showSentIcon() {
			return !this.isSystemMessage
				&& !this.isTemporary
				&& !this.isDeleting
				&& this.actorType === this.participant.actorType
				&& this.actorId === this.participant.actorId
				&& !this.isDeletedMessage
		},

		messagesList() {
			return this.$store.getters.messagesList(this.token)
		},

		isLastCallStartedMessage() {
			const messages = this.messagesList
			const lastCallStartedMessage = messages.reverse().find((message) => message.systemMessage === 'call_started')
			return lastCallStartedMessage ? (this.id === lastCallStartedMessage.id) : false
		},

		showJoinCallButton() {
			return this.systemMessage === 'call_started'
				&& this.conversation.hasCall
				&& this.isLastCallStartedMessage
				&& !this.isInCall
		},

		isSingleEmoji() {
			const regex = emojiRegex()
			let match
			let emojiStrings = ''
			let emojiCount = 0

			// eslint-disable-next-line no-cond-assign
			while (match = regex.exec(this.message)) {
				if (emojiCount > 2) {
					return false
				}

				emojiStrings += match[0]
				emojiCount++
			}

			return emojiStrings === this.message
		},

		richParameters() {
			const richParameters = {}
			Object.keys(this.messageParameters).forEach(function(p) {
				const type = this.messageParameters[p].type
				if (type === 'user' || type === 'call' || type === 'guest') {
					richParameters[p] = {
						component: Mention,
						props: this.messageParameters[p],
					}
				} else if (type === 'file') {
					richParameters[p] = {
						component: FilePreview,
						props: this.messageParameters[p],
					}
				} else if (type === 'deck-card') {
					richParameters[p] = {
						component: DeckCard,
						props: this.messageParameters[p],
					}
				} else {
					richParameters[p] = {
						component: DefaultParameter,
						props: this.messageParameters[p],
					}
				}
			}.bind(this))
			return richParameters
		},

		// Determines whether the date has to be displayed or not
		hasDate() {
			if (this.isTemporary || this.isDeleting || this.sendingFailure) {
				// Never on temporary or failed messages
				return false
			}

			return this.isSystemMessage || !this.showActions || this.isTallEnough
		},

		hasActions() {
			return !this.isSystemMessage
		},

		isTemporaryUpload() {
			return this.isTemporary && this.messageParameters.file
		},

		loadingIconTooltip() {
			return t('spreed', 'Sending message')
		},

		sentIconTooltip() {
			return t('spreed', 'Message sent')
		},

		commonReadIconTooltip() {
			return t('spreed', 'Message read by everyone who shares their reading status')
		},

		sendingErrorCanRetry() {
			return this.sendingFailure === 'timeout' || this.sendingFailure === 'other'
		},

		sendingErrorIconTooltip() {
			if (this.sendingErrorCanRetry) {
				return t('spreed', 'Failed to send the message. Click to try again')
			}
			if (this.sendingFailure === 'quota') {
				return t('spreed', 'Not enough free space to upload file')
			}
			return t('spreed', 'You cannot send messages to this conversation at the moment')
		},

		isMyMsg() {
			return this.actorId === this.$store.getters.getActorId()
				&& this.actorType === this.$store.getters.getActorType()
		},

		isDeleteable() {
			if (this.isConversationReadOnly) {
				return false
			}

			const isFileShare = this.message === '{file}'
				&& this.messageParameters?.file

			return (moment(this.timestamp * 1000).add(6, 'h')) > moment()
				&& this.messageType === 'comment'
				&& !this.isDeleting
				&& !isFileShare
				&& (this.isMyMsg
					|| (this.conversation.type !== CONVERSATION.TYPE.ONE_TO_ONE
						&& (this.participant.participantType === PARTICIPANT.TYPE.OWNER
							|| this.participant.participantType === PARTICIPANT.TYPE.MODERATOR)))
		},

		isPrivateReplyable() {
			return this.isReplyable
				&& (this.conversation.type === CONVERSATION.TYPE.PUBLIC
					|| this.conversation.type === CONVERSATION.TYPE.GROUP)
				&& !this.isMyMsg
				&& this.actorType === ATTENDEE.ACTOR_TYPE.USERS
				&& this.$store.getters.getActorType() === ATTENDEE.ACTOR_TYPE.USERS
		},

		messageActions() {
			return this.$store.getters.messageActions
		},

		messageAPIData() {
			return {
				message: this.messageObject,
				metadata: this.conversation,
				apiVersion: 'v3',
			}
		},
	},

	watch: {
		showJoinCallButton() {
			EventBus.$emit('scrollChatToBottom')
		},
	},

	mounted() {
		if (this.$refs.messageMain.clientHeight > 56) {
			this.isTallEnough = true
		}

		// define a function so it can be triggered directly on the DOM element
		// which can be found with document.getElementById()
		this.$refs.message.highlightAnimation = () => {
			this.highlightAnimation()
		}

		this.$refs.message.addEventListener('animationend', this.highlightAnimationStop)
	},

	beforeDestroy() {
		this.$refs.message.removeEventListener('animationend', this.highlightAnimationStop)
	},

	methods: {
		handleActionFocus() {
			console.log('handleActionFocus')
			this.isActionFocussed = true;
		},
		handleActionBlur() {
			console.log('handleActionBlur')
			this.isActionFocussed = false;
		},
		handleActionMenuOpen() {
			console.log('handleActionMenuOpen')
			this.isActionMenuOpen = true
		},
		handleActionMenuClose() {
			console.log('handleActionMenuClose')
			this.isActionMenuOpen = false
		},
		handleBlur() {
			// delay resetting the flag because we want the actions to stay visible
			// when tabbing, and if the tab lands onto an action it will set isFocussed to true again
			this.$nextTick(() => {
				this.isFocussed = false
			})
		},
		lastReadMessageVisibilityChanged(isVisible) {
			if (isVisible) {
				this.seen = true
			}
		},

		jumpToPrevious() {
			const el = document.getElementById('message_' + this.previousMessageId)
			if (el) {
				el.focus()
			}
		},
		jumpToNext() {
			const el = document.getElementById('message_' + this.nextMessageId)
			if (el) {
				el.focus()
			} else {
				// FIXME: emit a more generic event like "moving beyond bottom" and let the container(s) decide what control to focus
				EventBus.$emit('focusChatInput')
			}
		},

		highlightAnimation() {
			// trigger CSS highlight animation by setting a class
			this.$refs.message.classList.add('highlight-animation')
		},
		highlightAnimationStop() {
			// when the animation ended, remove the class so we can trigger it
			// again another time
			this.$refs.message.classList.remove('highlight-animation')
		},
		handleRetry() {
			if (this.sendingErrorCanRetry) {
				EventBus.$emit('retryMessage', this.id)
				EventBus.$emit('focusChatInput')
			}
		},
		handleReply() {
			this.$store.dispatch('addMessageToBeReplied', {
				id: this.id,
				actorId: this.actorId,
				actorType: this.actorType,
				actorDisplayName: this.actorDisplayName,
				timestamp: this.timestamp,
				systemMessage: this.systemMessage,
				messageType: this.messageType,
				message: this.message,
				messageParameters: this.messageParameters,
				token: this.token,
			})
			EventBus.$emit('focusChatInput')
		},
		async handleDelete() {
			this.isDeleting = true
			try {
				const statusCode = await this.$store.dispatch('deleteMessage', {
					message: {
						token: this.token,
						id: this.id,
					},
					placeholder: t('spreed', 'Deleting message'),
				})

				if (statusCode === 202) {
					showWarning(t('spreed', 'Message deleted successfully, but Matterbridge is configured and the message might already be distributed to other services'), {
						timeout: TOAST_DEFAULT_TIMEOUT * 2,
					})
				} else if (statusCode === 200) {
					showSuccess(t('spreed', 'Message deleted successfully'))
				}
			} catch (e) {
				if (e?.response?.status === 400) {
					showError(t('spreed', 'Message could not be deleted because it is too old'))
				} else if (e?.response?.status === 405) {
					showError(t('spreed', 'Only normal chat messages can be deleted'))
				} else {
					showError(t('spreed', 'An error occurred while deleting the message'))
					console.error(e)
				}
				this.isDeleting = false
				return
			}

			this.isDeleting = false
		},

		handleMouseover() {
			this.isHovered = true
		},

		handleMouseleave() {
			this.isHovered = false
		},
		async handlePrivateReply() {
			// open the 1:1 conversation
			const response = await createOneToOneConversation(this.actorId)
			const conversation = response.data.ocs.data
			this.$store.dispatch('addConversation', conversation)
			this.$router.push({ name: 'conversation', params: { token: conversation.token } }).catch(err => console.debug(`Error while pushing the new conversation's route: ${err}`))
		},

		async handleCopyMessageLink() {
			try {
				const link = window.location.protocol + '//' + window.location.host + generateUrl('/call/' + this.token) + '#message_' + this.id
				await this.$copyText(link)
				showSuccess(t('spreed', 'Message link copied to clipboard.'))
			} catch (error) {
				showError(t('spreed', 'The link could not be copied.'))
			}
		},

		async handleMarkAsUnread() {
			// update in backend + visually
			await this.$store.dispatch('updateLastReadMessage', {
				token: this.token,
				id: this.previousMessageId,
				updateVisually: true,
			})

			// reload conversation to update additional attributes that have computed values
			await this.$store.dispatch('fetchConversation', { token: this.token })
		},
	},
}
</script>

<style lang="scss" scoped>
@import '../../../../assets/variables';
@import '../../../../assets/buttons';

.message-body {
	padding: 4px;
	font-size: $chat-font-size;
	line-height: $chat-line-height;
	&:focus {
		background-color: var(--color-background-hover);
	}

	&__author {
		color: var(--color-text-maxcontrast);
	}
	&__main {
		display: flex;
		justify-content: space-between;
		min-width: 100%;
		&__text {
			flex: 0 1 600px;
			color: var(--color-text-light);
			.single-emoji {
				font-size: 250%;
				line-height: 100%;
			}

			&.call-started {
				background-color: var(--color-primary-light);
				padding: 10px;
				border-radius: var(--border-radius-large);
				text-align: center;
			}

			&.system-message {
				color: var(--color-text-maxcontrast);
				text-align: center;
				padding: 0 20px;
				width: 100%;
			}

			&.deleted-message {
				color: var(--color-text-lighter);
				display: flex;
				border-radius: var(--border-radius-large);
				align-items: center;
			}

			::v-deep .rich-text--wrapper {
				white-space: pre-wrap;
				word-break: break-word;
			}

			&--quote {
				border-left: 4px solid var(--color-primary);
				padding: 4px 0 0 8px;
			}
		}
		&__right {
			justify-self: flex-start;
			justify-content: flex-end;
			position: relative;
			user-select: none;
			display: flex;
			color: var(--color-text-maxcontrast);
			font-size: $chat-font-size;
			flex: 1 0 auto;
			padding: 0 8px 0 8px;
			&__actions {
				display: flex;
				position: absolute;
				top: -8px;
				right: 50px;
				&.tall {
					top: unset;
					right: 8px;
					bottom: -8px;
				}
			}
			& h6 {
				margin-left: auto;
			}
		}
	}
}

.date {
	margin-right: $clickable-area;
	&--self {
		margin-right: 0;
	}
}

// Increase the padding for regular messages to improve readability and
// allow some space for the reply button
.message-body:not(.system) {
	padding: 12px 4px 12px 8px;
	margin: -6px 0;
}

.hover, .highlight-animation {
	border-radius: 8px;
}

.hover {
	background-color: var(--color-background-hover);
}

.highlight-animation {
	animation: highlight-animation 5s 1;
}

@keyframes highlight-animation {
	0% { background-color: var(--color-background-hover); }
	50% { background-color: var(--color-background-hover); }
	100% { background-color: rgba(var(--color-background-hover), 0); }
}

.new-message-marker {
	position: relative;
	margin: 20px 15px 20px -45px;
	border-top: 1px solid var(--color-border);

	span {
		position: absolute;
		top: 0;
		left: 50%;
		transform: translateX(-50%) translateY(-50%);
		padding: 0 7px 0 7px;
		text-align: center;
		white-space: nowrap;

		border-radius: var(--border-radius);
		background-color: var(--color-main-background);
	}
}

.message-status {
	margin: -8px 0;
	width: $clickable-area;
	height: $clickable-area;
	display: flex;
	justify-content: center;
	align-items: center;

	&.retry-option {
		cursor: pointer;
	}
}
</style>
