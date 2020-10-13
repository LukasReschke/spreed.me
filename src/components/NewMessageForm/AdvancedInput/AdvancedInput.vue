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
	<At ref="at"
		v-model="text"
		name-key="label"
		:members="autoCompleteMentionCandidates"
		:filter-match="atFilter"
		:tab-select="true"
		:allow-spaces="false"
		@at="handleAtEvent">
		<template v-slot:item="scope">
			<Avatar v-if="isMentionToAll(scope.item.id)"
				:size="44"
				:icon-class="'icon-group-forced-white'"
				:disable-tooltip="true"
				:disable-menu="true"
				:is-no-user="true" />
			<div v-else-if="isMentionToGuest(scope.item.id)"
				class="avatar guest"
				:style="getGuestAvatarStyle()">
				{{ getFirstLetterOfGuestName(scope.item.label) }}
			</div>
			<Avatar v-else
				:size="44"
				:user="atRemoveQuotesFromUserIdForAvatars(scope.item.id)"
				:display-name="scope.item.label"
				:disable-tooltip="true"
				:disable-menu="true" />
			&nbsp;

			<span class="mention-suggestion">
				<span>{{ scope.item.label }}</span>
				<em v-if="getStatusMessage(scope.item)"
					class="user-status">
					{{ getStatusMessage(scope.item) }}
				</em>
			</span>
		</template>
		<template v-slot:embeddedItem="scope">
			<!-- The root element itself is ignored, only its contents are taken
			     into account. -->
			<span>
				<!-- vue-at seems to try to create an embedded item at some
				     strange times in which no item is selected and thus there
				     is no data, so do not use the Mention component in those
				     cases. -->
				<Mention
					v-if="scope.current.id"
					:id="scope.current.id"
					:type="getTypeForMentionComponent(scope.current)"
					:name="scope.current.label"
					:data-mention-id="scope.current.id" />
			</span>
		</template>
		<div
			ref="contentEditable"
			v-shortkey="['c']"
			:contenteditable="activeInput"
			:placeHolder="placeholderText"
			role="textbox"
			aria-multiline="true"
			class="new-message-form__advancedinput"
			@shortkey="focusInput"
			@keydown.enter="handleKeydownEnter"
			@keydown.esc.prevent="handleKeydownEsc"
			@paste="onPaste" />
	</At>
</template>

<script>
import At from 'vue-at'
import UserStatus from '../../../mixins/userStatus'
import VueAtReparenter from '../../../mixins/vueAtReparenter'
import { EventBus } from '../../../services/EventBus'
import { searchPossibleMentions } from '../../../services/mentionsService'
import { fetchClipboardContent } from '../../../utils/clipboard'
import Avatar from '@nextcloud/vue/dist/Components/Avatar'
import Mention from '../../MessagesList/MessagesGroup/Message/MessagePart/Mention'
import escapeHtml from 'escape-html'
import debounce from 'debounce'

/**
 * Checks whether the given style sheet is the default style sheet from the
 * vue-at component or not.
 *
 * @param {CSSStyleSheet} sheet the style sheet to check.
 * @returns {Boolean} True if it is the style sheet from vue-at, false
 *          otherwise.
 */
function isDefaultAtWhoStyleSheet(sheet) {
	try {
		// cssRules may not be defined in Chromium if the stylesheet is loaded
		// from a different domain.
		if (!sheet.cssRules) {
			return false
		}

		if (sheet.cssRules.length !== 15) {
			return false
		}

		for (const cssRule of sheet.cssRules) {
			// The only way to identify the style sheet is by looking to its
			// rules. Moreover, a rather complex rule is needed so the style
			// sheet is not mismatched with a different atwho stylesheet, for
			// example, the one added by the Comments app.
			if (cssRule.cssText === '.atwho-view { color: rgb(0, 0, 0); border-radius: 3px; box-shadow: rgba(0, 0, 0, 0.1) 0px 0px 5px; min-width: 120px; z-index: 11110 !important; }') {
				return true
			}
		}
	} catch (exception) {
		// Accessing cssRules may throw a SecurityError in Firefox if the style
		// sheet is loaded from a different domain.
		if (exception.name !== 'SecurityError') {
			throw exception
		}
	}

	return false
}

/**
 * Removes the default atwho style sheet added by the vue-at component.
 *
 * The rules in that style sheet are too broad and affect other elements
 * besides those from the vue-at panel.
 */
function removeDefaultAtWhoStyleSheet() {
	const styleElements = document.querySelectorAll('style')
	for (let i = 0; i < styleElements.length; i++) {
		if (isDefaultAtWhoStyleSheet(styleElements[i].sheet)) {
			styleElements[i].remove()

			return
		}
	}
}

removeDefaultAtWhoStyleSheet()

export default {
	name: 'AdvancedInput',
	components: {
		At,
		Avatar,
		Mention,
	},
	mixins: [
		VueAtReparenter,
		UserStatus,
	],
	props: {
		/**
		 * The placeholder for the input field
		 */
		placeholderText: {
			type: String,
			default: t('spreed', 'Write message, @ to mention someone …'),
		},

		/**
		 * Determines if the input is active
		 */
		activeInput: {
			type: Boolean,
			default: true,
		},

		value: {
			type: String,
			required: true,
		},

		/**
		 * The token of the conversation to get candidate mentions for.
		 */
		token: {
			type: String,
			required: true,
		},

		/**
		 * Whether to use the shift-enter key to submitting instead of enter
		 */
		submitWithShiftEnter: {
			type: Boolean,
			default: false,
		},
	},
	data: function() {
		return {
			text: '',
			autoCompleteMentionCandidates: [],
		}
	},
	watch: {
		text(text) {
			this.$nextTick(() => {
				this.$emit('update:contentEditable', this.$refs.contentEditable.cloneNode(true))
			})

			this.$emit('update:value', text)
			this.$emit('input', text)
			this.$emit('change', text)
		},
		value(value) {
			this.text = value
		},
		atwho(atwho) {
			if (!atwho) {
				// Clear mention candidates when closing the panel. Otherwise
				// they would be shown when the panel is opened again until the
				// new ones are received.
				this.autoCompleteMentionCandidates = []
			}
		},
	},
	mounted() {
		this.focusInput()
		/**
		 * Listen to routeChange global events and focus on the
		 */
		EventBus.$on('routeChange', this.focusInput)
		EventBus.$on('focusChatInput', this.focusInput)

		this.atWhoPanelExtraClasses = 'talk candidate-mentions'
	},
	beforeDestroy() {
		EventBus.$off('routeChange', this.focusInput)
		EventBus.$off('focusChatInput', this.focusInput)
	},
	methods: {
		onPaste(e) {
			e.preventDefault()

			const content = fetchClipboardContent(e)

			if (content.kind === 'file') {
				this.$emit('files-pasted', content.files)
			} else if (content.kind === 'text') {
				const text = content.text
				const div = document.createElement('div').innerText = escapeHtml(text)
				document.execCommand('insertHtml', false, div)

				this.focusInput()
			}
		},

		/**
		 * The vue-at library only searches in the display name by default.
		 * But luckily our server responds already only with matching items,
		 * so we just filter none and show them all.
		 * @returns {boolean} True as we never filter anything out
		 */
		atFilter() {
			return true
		},

		atRemoveQuotesFromUserIdForAvatars(userId) {
			if (userId.startsWith('"')) {
				return userId.substring(1, userId.length - 1)
			}
			return userId
		},

		/**
		 * Focuses the contenteditable div input
		 */
		focusInput() {
			if (!this.$route || this.$route.name === 'conversation') {
				const contentEditable = this.$refs.contentEditable
				// This is a hack but it's the only way I've found to focus a contenteditable div
				setTimeout(function() {
					contentEditable.focus()
				}, 0)
			}
		},

		/**
		 * Emits the submit event when enter is pressed (look
		 * at the v-on in the template) unless shift is pressed:
		 * in this case a new line will be created.
		 *
		 * @param {object} event the event object;
		 */
		handleKeydownEnter(event) {
			// Prevent submit event when vue-at panel is open, as that should
			// just select the mention from the panel.
			if (this.atwho) {
				return
			}

			// TODO: add support for CTRL+ENTER new line
			if (event.shiftKey === this.submitWithShiftEnter) {
				event.preventDefault()
				this.$emit('submit', event)
			}
		},

		/**
		 * Loose focus of the chat so shortcuts can be used
		 *
		 * @param {object} event the event object;
		 */
		handleKeydownEsc(event) {
			document.activeElement.blur()
		},

		/**
		 * Sets the autocomplete mention candidates based on the matched text
		 * after the "@".
		 *
		 * @param {String} chunk the matched text to look candidate mentions for.
		 */
		handleAtEvent: debounce(function(chunk) {
			this.queryPossibleMentions(chunk)
		}, 400),

		async queryPossibleMentions(chunk) {
			const response = await searchPossibleMentions(this.token, chunk)
			const possibleMentions = response.data.ocs.data

			// Wrap mention ids with spaces in quotes.
			possibleMentions.forEach(possibleMention => {
				if (possibleMention.id.indexOf(' ') !== -1
					|| possibleMention.id.indexOf('guest/') === 0) {
					possibleMention.id = '"' + possibleMention.id + '"'
				}
			})

			this.autoCompleteMentionCandidates = possibleMentions
		},

		isMentionToAll(mentionId) {
			return mentionId === 'all'
		},

		isMentionToGuest(mentionId) {
			// Guest ids, like ids of users with spaces, are wrapped in quotes.
			return mentionId.startsWith('"guest/')
		},

		getGuestAvatarStyle() {
			return {
				'width': '44px',
				'height': '44px',
				'line-height': '44px',
				'background-color': '#b9b9b9',
				'text-align': 'center',
			}
		},

		getFirstLetterOfGuestName(displayName) {
			const customName = displayName !== t('spreed', 'Guest') ? displayName : '?'
			return customName.charAt(0)
		},

		getTypeForMentionComponent(candidate) {
			if (this.isMentionToAll(candidate.id)) {
				return 'call'
			} else if (this.isMentionToGuest(candidate.id)) {
				return 'guest'
			}

			return 'user'
		},
	},
}
</script>

<style lang="scss" scoped>
@import '../../../assets/variables';

.new-message-form__advancedinput {
	overflow: visible;
	width: 100%;
	border:none;
	margin: 0;
	margin-left: 6px !important;
	word-break: break-word;
	white-space: pre-wrap;
}

// Support for the placeholder text in the div contenteditable
div[contenteditable] {
	font-size: $chat-font-size;
	line-height: $chat-line-height;
}

// Support for the placeholder text in the div contenteditable
[contenteditable]:empty:before{
	content: attr(placeholder);
	display: block;
	color: var(--color-text-maxcontrast);
}

.mention-suggestion {
	max-width: 250px;
	padding-left: 8px;

	.user-status {
		overflow: hidden;
		text-overflow: ellipsis;
		display: block;
		margin-top: 2px;
	}
}

</style>
