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
	<div
		class="wrapper"
		:class="{'wrapper--chatScrolledToBottom': isChatScrolledToBottom}">
		<!--native file picker, hidden -->
		<input id="file-upload"
			ref="fileUploadInput"
			multiple
			type="file"
			tabindex="-1"
			aria-hidden="true"
			class="hidden-visually"
			@change="handleFileInput">
		<div
			class="new-message">
			<form
				class="new-message-form"
				@submit.prevent>
				<div
					v-if="canUploadFiles || canShareFiles">
					<Actions
						ref="uploadMenu"
						container="#content-vue"
						:disabled="disabled"
						default-icon="icon-clip-add-file"
						:aria-label="t('spreed', 'Share files to the conversation')"
						:aria-haspopup="true">
						<ActionButton
							v-if="canUploadFiles"
							:close-after-click="true"
							icon="icon-upload"
							@click.prevent="clickImportInput">
							{{ t('spreed', 'Upload new files') }}
						</ActionButton>
						<ActionButton
							v-if="canShareFiles"
							:close-after-click="true"
							icon="icon-folder"
							@click.prevent="handleFileShare">
							{{ t('spreed', 'Share from Files') }}
						</ActionButton>
					</Actions>
				</div>
				<div>
					<EmojiPicker
						container="#content-vue"
						@select="addEmoji">
						<button
							type="button"
							:disabled="disabled"
							class="nc-button nc-button__main"
							:aria-label="t('spreed', 'Add emoji')"
							:aria-haspopup="true">
							<EmoticonOutline
								:size="16"
								decorative
								title="" />
						</button>
					</EmojiPicker>
				</div>
				<div class="new-message-form__input">
					<div v-if="messageToBeReplied" class="new-message-form__quote">
						<Quote
							:is-new-message-form-quote="true"
							:parent-id="messageToBeReplied.id"
							v-bind="messageToBeReplied" />
					</div>

					<AdvancedInput
						ref="advancedInput"
						v-model="text"
						:token="token"
						:active-input="!disabled"
						:placeholder-text="placeholderText"
						:aria-label="placeholderText"
						@update:contentEditable="contentEditableToParsed"
						@submit="handleSubmit"
						@files-pasted="handlePastedFiles" />
				</div>
				<button
					:disabled="disabled"
					type="submit"
					:aria-label="t('spreed', 'Send message')"
					class="nc-button nc-button__main"
					@click.prevent="handleSubmit">
					<Send
						title=""
						:size="16"
						decorative />
				</button>
			</form>
		</div>
	</div>
</template>

<script>
import AdvancedInput from './AdvancedInput/AdvancedInput'
import { getFilePickerBuilder, showError } from '@nextcloud/dialogs'
import { postNewMessage } from '../../services/messagesService'
import { getCapabilities } from '@nextcloud/capabilities'
import Quote from '../Quote'
import Actions from '@nextcloud/vue/dist/Components/Actions'
import ActionButton from '@nextcloud/vue/dist/Components/ActionButton'
import EmojiPicker from '@nextcloud/vue/dist/Components/EmojiPicker'
import { EventBus } from '../../services/EventBus'
import { shareFile } from '../../services/filesSharingServices'
import { processFiles } from '../../utils/fileUpload'
import { CONVERSATION, PARTICIPANT } from '../../constants'
import createTemporaryMessage from '../../utils/temporaryMessage'
import EmoticonOutline from 'vue-material-design-icons/EmoticonOutline'
import Send from 'vue-material-design-icons/Send'
import CancelableRequest from '../../utils/cancelableRequest'

const picker = getFilePickerBuilder(t('spreed', 'File to share'))
	.setMultiSelect(false)
	.setModal(true)
	.setType(1)
	.allowDirectories()
	.build()

export default {
	name: 'NewMessageForm',
	components: {
		AdvancedInput,
		Quote,
		Actions,
		ActionButton,
		EmojiPicker,
		EmoticonOutline,
		Send,
	},

	props: {
		isChatScrolledToBottom: {
			type: Boolean,
			required: true,
		},
	},
	data: function() {
		return {
			text: '',
			parsedText: '',
			conversationIsFirstInList: false,
		}
	},
	computed: {
		/**
		 * The current conversation token
		 *
		 * @returns {String}
		 */
		token() {
			return this.$store.getters.getToken()
		},

		conversation() {
			return this.$store.getters.conversation(this.token) || {
				readOnly: CONVERSATION.STATE.READ_WRITE,
			}
		},

		isReadOnly() {
			return (this.conversation.readOnly === CONVERSATION.STATE.READ_ONLY)
				|| (this.conversation.readOnly === CONVERSATION.STATE.WRITE_ONLY_MOD && !this.selfIsModerator)
		},

		currentParticipant() {
			return this.$store.getters.conversation(this.token) || {
				sessionId: '0',
				participantType: this.$store.getters.getUserId() !== null ? PARTICIPANT.TYPE.USER : PARTICIPANT.TYPE.GUEST,
			}
		},

		selfIsModerator() {
			return this.currentParticipant.participantType === PARTICIPANT.TYPE.OWNER
				|| this.currentParticipant.participantType === PARTICIPANT.TYPE.MODERATOR
		},

		disabled() {
			return this.isReadOnly || !this.currentConversationIsJoined
		},

		placeholderText() {
			if (this.isReadonly) {
				return t('spreed', 'This conversation has been locked')
			} else if (!this.currentConversationIsJoined) {
				return t('spreed', 'Joining conversation …')
			} else {
				return t('spreed', 'Write message, @ to mention someone …')
			}
		},

		messageToBeReplied() {
			return this.$store.getters.getMessageToBeReplied(this.token)
		},

		currentUserIsGuest() {
			return this.$store.getters.getUserId() === null
		},

		canShareFiles() {
			return !this.currentUserIsGuest
		},

		canUploadFiles() {
			const allowed = getCapabilities()?.spreed?.config?.attachments?.allowed
			return allowed
				&& this.attachmentFolderFreeSpace !== 0
				&& this.canShareFiles
		},

		attachmentFolderFreeSpace() {
			return this.$store.getters.getAttachmentFolderFreeSpace()
		},

		currentConversationIsJoined() {
			return this.$store.getters.currentConversationIsJoined
		},
	},

	watch: {
		disabled(newValue) {
			// the menu is not always available
			if (!this.$refs.uploadMenu) {
				return
			}
			this.$refs.uploadMenu.$refs.menuButton.disabled = newValue
		},

		text(newValue) {
			this.$store.dispatch('setCurrentMessageInput', { token: this.token, text: newValue })
		},

		token(token) {
			if (token) {
				this.text = this.$store.getters.currentMessageInput(token) || ''
			} else {
				this.text = ''
			}
		},
	},

	mounted() {
		EventBus.$on('uploadStart', this.handleUploadStart)
		EventBus.$on('retryMessage', this.handleRetryMessage)
		this.text = this.$store.getters.currentMessageInput(this.token) || ''
	},

	beforeDestroy() {
		EventBus.$off('uploadStart', this.handleUploadStart)
		EventBus.$off('retryMessage', this.handleRetryMessage)
	},

	methods: {
		handleUploadStart() {
			// refocus on upload start as the user might want to type again
			// while the upload is running
			this.$refs.advancedInput.focusInput()
		},

		contentEditableToParsed(contentEditable) {
			const mentions = contentEditable.querySelectorAll('span[data-at-embedded]')
			mentions.forEach(mention => {
				// FIXME Adding a space after the mention should be improved to
				// do it or not based on the next element instead of always
				// adding it.
				mention.replaceWith('@' + mention.firstElementChild.attributes['data-mention-id'].value + ' ')
			})

			this.parsedText = this.rawToParsed(contentEditable.innerHTML)
		},
		/**
		 * Returns a parsed version of the given raw text of the content
		 * editable div.
		 *
		 * The given raw text contains a plain text representation of HTML
		 * content (like "first&nbsp;line<br>second&nbsp;line"). The returned
		 * parsed text replaces the (known) HTML content with the format
		 * expected by the server (like "first line\nsecond line").
		 *
		 * The parsed text is also trimmed.
		 *
		 * @param {String} text the raw text
		 * @returns {String} the parsed text
		 */
		rawToParsed(text) {
			text = text.replace(/<br>/g, '\n')
			text = text.replace(/&nbsp;/g, ' ')

			// Since we used innerHTML to get the content of the div.contenteditable
			// it is escaped. With this little trick from https://stackoverflow.com/a/7394787
			// We unescape the code again, so if you write `<strong>` we can display
			// it again instead of `&lt;strong&gt;`
			const temp = document.createElement('textarea')
			temp.innerHTML = text
			text = temp.value

			// Although the text is fully trimmed, at the very least the last
			// "\n" occurrence should be always removed, as browsers add a
			// "<br>" element as soon as some rich text is written in a content
			// editable div (for example, if a new line is added the div content
			// will be "<br><br>").
			return text.trim()
		},

		/**
		 * Sends the new message
		 */
		async handleSubmit() {
			if (this.parsedText !== '') {
				const temporaryMessage = createTemporaryMessage(this.parsedText, this.token)
				this.$store.dispatch('addTemporaryMessage', temporaryMessage)
				this.text = ''
				this.parsedText = ''
				// Scrolls the message list to the last added message
				EventBus.$emit('smoothScrollChatToBottom')
				// Also remove the message to be replied for this conversation
				this.$store.dispatch('removeMessageToBeReplied', this.token)
				let timeout
				try {
					// Posts the message to the server
					const { request, cancel } = CancelableRequest(postNewMessage)

					timeout = setTimeout(() => {
						cancel('canceled')
						this.$store.dispatch('markTemporaryMessageAsFailed', {
							message: temporaryMessage,
							reason: 'timeout',
						})
					}, 30000)
					const response = await request(temporaryMessage)
					clearTimeout(timeout)

					// If successful, deletes the temporary message from the store
					this.$store.dispatch('removeTemporaryMessageFromStore', temporaryMessage)

					const message = response.data.ocs.data
					// And adds the complete version of the message received
					// by the server
					this.$store.dispatch('processMessage', message)

					// update lastMessage and lastReadMessage
					// do it conditionally because there could have been more messages appearing concurrently
					if (this.conversation.lastMessage && message.id > this.conversation.lastMessage.id) {
						this.$store.dispatch('updateConversationLastMessage', {
							token: this.token,
							lastMessage: message,
						})
					}
					if (message.id > this.conversation.lastReadMessage) {
						this.$store.dispatch('updateLastReadMessage', {
							token: this.token,
							id: message.id,
							updateVisually: true,
						})
					}
				} catch (error) {
					let statusCode = null
					console.debug(`error while submitting message ${error}`, error)
					if (error.isAxiosError) {
						statusCode = error?.response?.status
					}

					if (timeout) {
						clearTimeout(timeout)
					}

					// 403 when room is read-only, 412 when switched to lobby mode
					if (statusCode === 403) {
						showError(t('spreed', 'No permission to post messages in this conversation'))
						this.$store.dispatch('markTemporaryMessageAsFailed', {
							message: temporaryMessage,
							reason: 'read-only',
						})
					} else if (statusCode === 412) {
						showError(t('spreed', 'No permission to post messages in this conversation'))
						this.$store.dispatch('markTemporaryMessageAsFailed', {
							message: temporaryMessage,
							reason: 'lobby',
						})
					} else {
						showError(t('spreed', 'Could not post message: {errorMessage}', { errorMessage: error.message || error }))
						this.$store.dispatch('markTemporaryMessageAsFailed', {
							message: temporaryMessage,
							reason: 'other',
						})
					}
				}
			}
		},

		handleRetryMessage(temporaryMessageId) {
			if (this.parsedText === '') {
				const temporaryMessage = this.$store.getters.message(this.token, temporaryMessageId)
				if (temporaryMessage) {
					this.text = temporaryMessage.message || this.text
					this.parsedText = temporaryMessage.message || this.parsedText
					this.$store.dispatch('deleteMessage', temporaryMessage)
				}
			}
		},

		async handleFileShare() {
			picker.pick()
				.then(async(path) => {
					console.debug(`path ${path} selected for sharing`)
					if (!path.startsWith('/')) {
						throw new Error(t('files', 'Invalid path selected'))
					}
					shareFile(path, this.token)
					this.$refs.advancedInput.focusInput()
				})
		},

		/**
		 * Clicks the hidden file input when clicking the correspondent ActionButton,
		 * thus opening the file-picker
		 */
		clickImportInput() {
			this.$refs.fileUploadInput.click()
		},

		handleFileInput(event) {
			const files = Object.values(event.target.files)

			this.handleFiles(files)

			// Clear input to ensure that the change event will be emitted if
			// the same file is picked again.
			event.target.value = ''
		},

		/**
		 * Handles files pasting event
		 *
		 * @param {File[] | FileList} files pasted files list
		 */
		async handlePastedFiles(files) {
			this.handleFiles(files, true)
		},

		/**
		 * Handles file upload
		 *
		 * @param {File[] | FileList} files pasted files list
		 * @param {bool} rename whether to rename the files
		 */
		async handleFiles(files, rename = false) {
			// Create a unique id for the upload operation
			const uploadId = new Date().getTime()
			// Uploads and shares the files
			await processFiles(files, this.token, uploadId, rename)
		},

		/**
		 * Add selected emoji to text input area
		 *
		 * The emoji will be added at the current caret position, and any text
		 * currently selected will be replaced by the emoji. If the input area
		 * does not have the focus there will be no caret or selection; in that
		 * case the emoji will be added at the end.
		 *
		 * @param {Emoji} emoji Emoji object
		 */
		addEmoji(emoji) {
			const selection = document.getSelection()

			const contentEditable = this.$refs.advancedInput.$refs.contentEditable

			// There is no select, or current selection does not start in the
			// content editable element, so just append the emoji at the end.
			if (!contentEditable.isSameNode(selection.anchorNode) && !contentEditable.contains(selection.anchorNode)) {
				// Browsers add a "<br>" element as soon as some rich text is
				// written in a content editable div (for example, if a new line
				// is added the div content will be "<br><br>"), so the emoji
				// has to be added before the last "<br>" (if any).
				if (this.text.endsWith('<br>')) {
					this.text = this.text.substr(0, this.text.lastIndexOf('<br>')) + emoji + '<br>'
				} else {
					this.text += emoji
				}

				return
			}

			// Although due to legacy reasons the API allows several ranges the
			// specification requires the selection to always have a single
			// range.
			// https://developer.mozilla.org/en-US/docs/Web/API/Selection#Multiple_ranges_in_a_selection
			const range = selection.getRangeAt(0)

			// Deleting the contents also collapses the range to the start.
			range.deleteContents()

			const emojiTextNode = document.createTextNode(emoji)
			range.insertNode(emojiTextNode)

			this.text = contentEditable.innerHTML

			range.setStartAfter(emojiTextNode)
		},
	},
}
</script>

<style lang="scss" scoped>
@import '../../assets/buttons';

.wrapper {
	position: sticky;
	bottom: 0;
	display: flex;
	justify-content: center;
	padding: 12px 0;
	border-top: 1px solid var(--color-border);
	&--chatScrolledToBottom {
		border-top: none;
	}
}

.new-message {
	max-width: $messages-list-max-width + 145px;
	flex: 1 1 100%;
	&-form {
		display: flex;
		align-items: flex-end;
		&__input {
			flex-grow: 1;
			max-height: $message-form-max-height;
			overflow-y: auto;
			overflow-x: hidden;
			max-width: 638px;
		}
		&__quote {
			margin: 0 16px 12px 24px;
			background-color: var(--color-background-hover);
			padding: 8px;
			border-radius: var(--border-radius-large);
		}

		// put a grey round background when popover is opened
		// or hover-focused
		&__icon:hover,
		&__icon:focus,
		&__icon:active {
			opacity: $opacity_full;
			// good looking on dark AND white bg
			background-color: $icon-focus-bg;
		}

	}
}

// Override actions styles TODO: upstream this change
::v-deep .action-item__menutoggle {
	opacity: .8 !important;
	&:hover,
	&:focus {
		background-color: var(--color-background-hover) !important;
		opacity: .8 !important;
	}
}

</style>
