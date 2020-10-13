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
		class="wrapper">
		<!--native file picker, hidden -->
		<input id="file-upload"
			ref="fileUploadInput"
			multiple
			type="file"
			class="hidden-visually"
			@change="handleFileInput">
		<div
			class="new-message">
			<form
				class="new-message-form"
				@submit.prevent>
				<div
					class="new-message-form__button">
					<Actions
						default-icon="icon-clip-add-file"
						class="new-message-form__button"
						:aria-label="t('spreed', 'Share files to the conversation')"
						:aria-haspopup="true">
						<ActionButton
							v-if="canShareAndUploadFiles"
							:close-after-click="true"
							icon="icon-upload"
							@click.prevent="clickImportInput">
							{{ t('spreed', 'Upload new files') }}
						</ActionButton>
						<ActionButton
							v-if="canShareAndUploadFiles"
							:close-after-click="true"
							icon="icon-folder"
							@click.prevent="handleFileShare">
							{{ t('spreed', 'Share from Files') }}
						</ActionButton>
					</Actions>
				</div>
				<div
					class="new-message-form__button">
					<EmojiPicker @select="addEmoji">
						<button
							type="button"
							class="new-message-form__icon new-message-form__button"
							:aria-label="t('spreed', 'Add emoji')"
							:aria-haspopup="true">
							<EmoticonOutline
								:size="20"
								decorative />
						</button>
					</EmojiPicker>
				</div>
				<div class="new-message-form__input">
					<Quote
						v-if="messageToBeReplied"
						:is-new-message-form-quote="true"
						v-bind="messageToBeReplied" />
					<AdvancedInput
						ref="advancedInput"
						v-model="text"
						:token="token"
						:submit-with-shift-enter="submitWithShiftEnter"
						@update:contentEditable="contentEditableToParsed"
						@submit="handleSubmit"
						@files-pasted="handleFiles" />
				</div>
				<button
					type="submit"
					:aria-label="t('spreed', 'Send message')"
					class="new-message-form__button submit icon-confirm-fade"
					@click.prevent="handleSubmit" />
			</form>
		</div>
	</div>
</template>

<script>
import AdvancedInput from './AdvancedInput/AdvancedInput'
import { getFilePickerBuilder } from '@nextcloud/dialogs'
import { postNewMessage } from '../../services/messagesService'
import Quote from '../Quote'
import Actions from '@nextcloud/vue/dist/Components/Actions'
import ActionButton from '@nextcloud/vue/dist/Components/ActionButton'
import EmojiPicker from '@nextcloud/vue/dist/Components/EmojiPicker'
import { EventBus } from '../../services/EventBus'
import { shareFile } from '../../services/filesSharingServices'
import { processFiles } from '../../utils/fileUpload'
import { CONVERSATION, SEND_MESSAGE_KEY } from '../../constants'
import createTemporaryMessage from '../../utils/temporaryMessage'
import EmoticonOutline from 'vue-material-design-icons/EmoticonOutline'

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

		submitWithShiftEnter() {
			return this.$store.getters.getSendMessageKey() === SEND_MESSAGE_KEY.SHIFT_ENTER
		},

		conversation() {
			return this.$store.getters.conversation(this.token) || {
				readOnly: CONVERSATION.STATE.READ_WRITE,
			}
		},

		messageToBeReplied() {
			return this.$store.getters.getMessageToBeReplied(this.token)
		},

		currentUserIsGuest() {
			return this.$store.getters.getUserId() === null
		},

		canShareAndUploadFiles() {
			return !this.currentUserIsGuest && this.conversation.readOnly === CONVERSATION.STATE.READ_WRITE
		},
	},

	watch: {
		token(newValue, oldValue) {
			this.isCurrentConversationIsFirstInList()
		},
	},

	mounted() {
		this.isCurrentConversationIsFirstInList()

		EventBus.$on('uploadStart', this.handleUploadStart)
	},

	beforeDestroy() {
		EventBus.$off('uploadStart', this.handleUploadStart)
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
			// first div doesn't count
			text = text.replace(/^<div>/g, '')

			// newlines from enter key
			//   extra newlines
			text = text.replace(/<div><br><\/div>/g, '\n')
			//   regular newlines
			text = text.replace(/<div>/g, '\n')

			// newlines from shift+enter key
			text = text.replace(/<br>/g, '\n')

			// clear formatting tags (for now)i
			// some browsers natively allow users to insert those with ctrl+b or ctrl+i
			text = text.replace(/<\/?i>/g, '')
			text = text.replace(/<\/?b>/g, '')

			// clean up
			text = text.replace(/<\/div>/g, '')

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
				this.$nextTick(function() {
					document.querySelector('.scroller').scrollTop = document.querySelector('.scroller').scrollHeight
				})
				// Also remove the message to be replied for this conversation
				this.$store.dispatch('removeMessageToBeReplied', this.token)
				try {
					// Posts the message to the server
					const response = await postNewMessage(temporaryMessage)
					// If successful, deletes the temporary message from the store
					this.$store.dispatch('deleteMessage', temporaryMessage)
					// And adds the complete version of the message received
					// by the server
					this.$store.dispatch('processMessage', response.data.ocs.data)
					// Scrolls the conversationlist to conversation
					if (!this.conversationIsFirstInList) {
						const conversation = document.getElementById(`conversation_${this.token}`)
						this.$nextTick(() => {
							conversation.scrollIntoView({
								behavior: 'smooth',
								block: 'center',
								inline: 'nearest',
							})
						})
					}
				} catch (error) {
					console.debug(`error while submitting message ${error}`)
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
		},

		/**
		 * Handles files pasting event.
		 *
		 * @param {File[] | FileList} files pasted files list
		 */
		async handleFiles(files) {
			// Create a unique id for the upload operation
			const uploadId = new Date().getTime()
			// Uploads and shares the files
			await processFiles(files, this.token, uploadId)
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

		// Check whether the current conversation is the first in the conversations
		// list and stores the value in the component's data.
		isCurrentConversationIsFirstInList() {
			this.conversationIsFirstInList = this.$store.getters.conversationsList.map(conversation => conversation.token).indexOf(this.token) === 0
		},
	},
}
</script>

<style lang="scss" scoped>
@import '../../assets/variables';

.wrapper {
	border-top: 1px solid var(--color-border-dark);
	padding: 4px 0;
}

.new-message {
	margin: auto;
	max-width: $messages-list-max-width;
	position: sticky;
	position: -webkit-sticky;
	bottom: 0;
	background-color: var(--color-main-background);
	&-form {
		display: flex;
		align-items: center;
		&__input {
			flex-grow: 1;
			max-height: $message-form-max-height;
			overflow-y: auto;
			overflow-x: hidden;
			max-width: $message-max-width;
		}
		&__button {
			width: 44px;
			height: 44px;
			margin-top: auto;
			background-color: transparent;
			border: none;
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

</style>
