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
	<AppNavigation>
		<div class="new-conversation">
			<SearchBox
				v-model="searchText"
				class="conversations-search"
				:is-searching="isSearching"
				@input="debounceFetchSearchResults"
				@abort-search="abortSearch" />
			<NewGroupConversation
				v-if="canStartConversations" />
		</div>
		<ul class="left-sidebar__list">
			<Caption v-if="isSearching"
				:title="t('spreed', 'Conversations')" />
			<li>
				<ConversationsList
					:search-text="searchText" />
			</li>
			<template v-if="isSearching">
				<template v-if="searchResultsUsers.length !== 0">
					<Caption
						:title="t('spreed', 'Contacts')" />
					<li v-if="searchResultsUsers.length !== 0">
						<ConversationsOptionsList
							:items="searchResultsUsers"
							@click="createAndJoinConversation" />
					</li>
				</template>
				<template v-if="!showStartConversationsOptions">
					<Caption v-if="searchResultsUsers.length === 0"
						:title="t('spreed', 'Contacts')" />
					<Hint v-if="contactsLoading" :hint="t('spreed', 'Loading')" />
					<Hint v-else :hint="t('spreed', 'No search results')" />
				</template>
			</template>
			<template v-if="showStartConversationsOptions">
				<template v-if="searchResultsGroups.length !== 0">
					<Caption
						:title="t('spreed', 'Groups')" />
					<li v-if="searchResultsGroups.length !== 0">
						<ConversationsOptionsList
							:items="searchResultsGroups"
							@click="createAndJoinConversation" />
					</li>
				</template>

				<template v-if="searchResultsCircles.length !== 0">
					<Caption
						:title="t('spreed', 'Circles')" />
					<li v-if="searchResultsCircles.length !== 0">
						<ConversationsOptionsList
							:items="searchResultsCircles"
							@click="createAndJoinConversation" />
					</li>
				</template>

				<Caption v-if="sourcesWithoutResults"
					:title="sourcesWithoutResultsList" />
				<Hint v-if="contactsLoading" :hint="t('spreed', 'Loading')" />
				<Hint v-else :hint="t('spreed', 'No search results')" />
			</template>
		</ul>

		<AppNavigationSettings>
			<label for="attachment_folder">{{ t('spreed', 'Default location for attachments') }}</label>
			<input id="attachment_folder"
				type="text"
				:value="attachmentFolder"
				:disabled="attachmentFolderLoading"
				@click="selectAttachmentFolder">

			<input id="my_notes"
				:checked="myNotesEnabled"
				value="1"
				type="checkbox"
				class="checkbox action-checkbox__checkbox"
				@change="toggleMyNotes">
			<label for="my_notes" class="action-checkbox__label">{{ t('spreed', 'My notes') }}</label>
		</AppNavigationSettings>
	</AppNavigation>
</template>

<script>
import AppNavigation from '@nextcloud/vue/dist/Components/AppNavigation'
import AppNavigationSettings from '@nextcloud/vue/dist/Components/AppNavigationSettings'
import Caption from '../Caption'
import ConversationsList from './ConversationsList/ConversationsList'
import ConversationsOptionsList from '../ConversationsOptionsList'
import Hint from '../Hint'
import SearchBox from './SearchBox/SearchBox'
import debounce from 'debounce'
import { EventBus } from '../../services/EventBus'
import {
	createGroupConversation, createOneToOneConversation,
	searchPossibleConversations,
} from '../../services/conversationsService'
import {
	setAttachmentFolder,
	toggleMyNotes,
} from '../../services/settingsService'
import { CONVERSATION } from '../../constants'
import { loadState } from '@nextcloud/initial-state'
import NewGroupConversation from './NewGroupConversation/NewGroupConversation'
import { getFilePickerBuilder } from '@nextcloud/dialogs'

export default {

	name: 'LeftSidebar',

	components: {
		AppNavigation,
		AppNavigationSettings,
		Caption,
		ConversationsList,
		ConversationsOptionsList,
		Hint,
		SearchBox,
		NewGroupConversation,
	},

	data() {
		return {
			searchText: '',
			searchResults: {},
			searchResultsUsers: [],
			searchResultsGroups: [],
			searchResultsCircles: [],
			contactsLoading: false,
			isCirclesEnabled: loadState('talk', 'circles_enabled'),
			canStartConversations: loadState('talk', 'start_conversations'),
			attachmentFolderLoading: true,
			myNotesEnabled: loadState('talk', 'my_notes'),
		}
	},

	computed: {
		conversationsList() {
			return this.$store.getters.conversationsList
		},
		isSearching() {
			return this.searchText !== ''
		},
		showStartConversationsOptions() {
			return this.isSearching && this.canStartConversations
		},

		sourcesWithoutResults() {
			return !this.searchResultsUsers.length
				|| !this.searchResultsGroups.length
				|| (this.isCirclesEnabled && !this.searchResultsCircles.length)
		},

		sourcesWithoutResultsList() {
			if (!this.searchResultsUsers.length) {
				if (!this.searchResultsGroups.length) {
					if (this.isCirclesEnabled && !this.searchResultsCircles.length) {
						return t('spreed', 'Contacts, groups and circles')
					} else {
						return t('spreed', 'Contacts and groups')
					}
				} else {
					if (this.isCirclesEnabled && !this.searchResultsCircles.length) {
						return t('spreed', 'Contacts and circles')
					} else {
						return t('spreed', 'Contacts')
					}
				}
			} else {
				if (!this.searchResultsGroups.length) {
					if (this.isCirclesEnabled && !this.searchResultsCircles.length) {
						return t('spreed', 'Groups and circles')
					} else {
						return t('spreed', 'Groups')
					}
				} else {
					if (this.isCirclesEnabled && !this.searchResultsCircles.length) {
						return t('spreed', 'Circles')
					}
				}
			}
			return t('spreed', 'Other sources')
		},

		attachmentFolder() {
			return this.$store.getters.getAttachmentFolder()
		},
	},

	beforeMount() {
		/**
		 * After a conversation was created, the search filter is reset
		 */
		EventBus.$once('resetSearchFilter', () => {
			this.searchText = ''
		})
	},

	mounted() {
		this.attachmentFolderLoading = false
	},

	methods: {
		debounceFetchSearchResults: debounce(function() {
			if (this.isSearching) {
				this.fetchSearchResults()
			}
		}, 250),

		async fetchSearchResults() {
			this.contactsLoading = true
			const response = await searchPossibleConversations(this.searchText, undefined, !this.canStartConversations)
			this.searchResults = response.data.ocs.data
			this.searchResultsUsers = this.searchResults.filter((match) => {
				return match.source === 'users'
					&& match.id !== this.$store.getters.getUserId()
					&& !this.hasOneToOneConversationWith(match.id)
			})
			this.searchResultsGroups = this.searchResults.filter((match) => match.source === 'groups')
			this.searchResultsCircles = this.searchResults.filter((match) => match.source === 'circles')
			this.contactsLoading = false
		},

		/**
		 * Create a new conversation with the selected group/user/circle
		 * @param {Object} item The autocomplete suggestion to start a conversation with
		 * @param {string} item.id The ID of the target
		 * @param {string} item.source The source of the target
		 */
		async createAndJoinConversation(item) {
			let response
			if (item.source === 'users') {
				response = await createOneToOneConversation(item.id)
			} else {
				response = await createGroupConversation(item.id, item.source)
			}
			const conversation = response.data.ocs.data
			this.$store.dispatch('addConversation', conversation)
			this.$router.push({ name: 'conversation', params: { token: conversation.token } }).catch(err => console.debug(`Error while pushing the new conversation's route: ${err}`))
			EventBus.$emit('resetSearchFilter')
		},

		hasOneToOneConversationWith(userId) {
			return !!this.conversationsList.find(conversation => conversation.type === CONVERSATION.TYPE.ONE_TO_ONE && conversation.name === userId)
		},
		// Reset the search text, therefore end the search operation.
		abortSearch() {
			this.searchText = ''
		},

		selectAttachmentFolder() {
			const picker = getFilePickerBuilder(t('spreed', 'Select default location for attachments'))
				.setMultiSelect(false)
				.setModal(true)
				.setType(1)
				.addMimeTypeFilter('httpd/unix-directory')
				.allowDirectories()
				.startAt(this.attachmentFolder)
				.build()
			picker.pick()
				.then(async(path) => {
					console.debug(`Path '${path}' selected for talk attachments`)
					if (path !== '' && !path.startsWith('/')) {
						throw new Error(t('spreed', 'Invalid path selected'))
					}

					const oldFolder = this.attachmentFolder
					this.attachmentFolderLoading = true
					try {
						this.$store.commit('setAttachmentFolder', path)
						await setAttachmentFolder(path)
					} catch (exception) {
						OCP.Toast.error(t('spreed', 'Error while setting attachment folder'))
						this.$store.commit('setAttachmentFolder', oldFolder)
					}
					this.attachmentFolderLoading = false
				})
		},

		async toggleMyNotes() {
			this.myNotesEnabled = !this.myNotesEnabled
			await toggleMyNotes(this.myNotesEnabled)
			EventBus.$emit('shouldRefreshConversations')
		},
	},
}
</script>

<style lang="scss" scoped>

@import '../../assets/variables';

.new-conversation {
	display: flex;
	padding: 6px;
	border-bottom: 1px solid var(--color-border-dark);
}

</style>
