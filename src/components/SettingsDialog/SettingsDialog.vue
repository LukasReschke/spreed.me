<!--
  - @copyright Copyright (c) 2020 Marco Ambrosini <marcoambrosini@pm.me>
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
	<Modal v-if="showSettings"
		@close="showSettings = false">
		<div class="wrapper">
			<div class="app-settings-section">
				<h2 class="app-setting-section__title">
					{{ t('spreed', 'Choose devices') }}
				</h2>
				<MediaDevicesPreview />
			</div>
			<div v-if="!isGuest"
				class="app-settings-section">
				<h2 class="app-setting-section__title">
					{{ t('spreed', 'Attachments folder') }}
				</h2>
				<h3 class="app-settings-section__hint">
					{{ locationHint }}
				</h3>
				<input
					type="text"
					class="app-settings-section__input"
					:value="attachmentFolder"
					:disabled="attachmentFolderLoading"
					@click="selectAttachmentFolder">
			</div>
			<div class="app-settings-section last">
				<h2 class="app-setting-section__title">
					{{ t('spreed', 'Keyboard shortcuts') }}
				</h2>

				<div v-if="!isGuest">
					<input
						id="app-settings-send-message-key"
						type="checkbox"
						class="checkbox"
						:checked="sendMessageKeyIsShiftEnter"
						:disabled="sendMessageKeyLoading"
						@change="updateSendMessageKey($event)">
					<label for="app-settings-send-message-key">
						{{ t('spreed', 'Shift-Enter sends messages instead of Enter') }}
					</label>
				</div>

				<h3 class="app-settings-section__hint">
					{{ t('spreed', 'Speed up your Talk experience with these quick shortcuts.') }}
				</h3>

				<dl>
					<div>
						<dt><kbd>C</kbd></dt>
						<dd class="shortcut-description">
							{{ t('spreed', 'Focus the chat input') }}
						</dd>
					</div>
					<div>
						<dt><kbd>Esc</kbd></dt>
						<dd class="shortcut-description">
							{{ t('spreed', 'Unfocus the chat input to use shortcuts') }}
						</dd>
					</div>
					<div>
						<dt><kbd>F</kbd></dt>
						<dd class="shortcut-description">
							{{ t('spreed', 'Fullscreen the chat or call') }}
						</dd>
					</div>
					<div>
						<dt><kbd>Ctrl</kbd> + <kbd>F</kbd></dt>
						<dd class="shortcut-description">
							{{ t('spreed', 'Search') }}
						</dd>
					</div>
				</dl>

				<h3>{{ t('spreed', 'Shortcuts while in a call') }}</h3>
				<dl>
					<div>
						<dt><kbd>V</kbd></dt>
						<dd class="shortcut-description">
							{{ t('spreed', 'Video on and off') }}
						</dd>
					</div>
					<div>
						<dt><kbd>M</kbd></dt>
						<dd class="shortcut-description">
							{{ t('spreed', 'Microphone on and off') }}
						</dd>
					</div>
				</dl>
			</div>
		</div>
	</Modal>
</template>

<script>
import Modal from '@nextcloud/vue/dist/Components/Modal'
import { getFilePickerBuilder, showError } from '@nextcloud/dialogs'
import { setAttachmentFolder, setSendMessageKey } from '../../services/settingsService'
import { EventBus } from '../../services/EventBus'
import { SEND_MESSAGE_KEY } from '../../constants'
import MediaDevicesPreview from '../MediaDevicesPreview'

export default {
	name: 'SettingsDialog',

	components: {
		Modal,
		MediaDevicesPreview,
	},

	data() {
		return {
			showSettings: false,
			attachmentFolderLoading: true,
			sendMessageKeyLoading: true,
		}
	},

	computed: {
		attachmentFolder() {
			return this.$store.getters.getAttachmentFolder()
		},

		sendMessageKeyIsShiftEnter() {
			return this.$store.getters.getSendMessageKey() === SEND_MESSAGE_KEY.SHIFT_ENTER
		},

		locationHint() {
			return t('spreed', 'Choose in which folder attachments should be saved.')
		},

		isGuest() {
			return !this.$store.getters.getUserId()
		},
	},

	mounted() {
		EventBus.$on('show-settings', this.handleShowSettings)
		this.attachmentFolderLoading = false
		this.sendMessageKeyLoading = false
	},

	methods: {

		selectAttachmentFolder() {
			const picker = getFilePickerBuilder(t('spreed', 'Select location for attachments'))
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
						showError(t('spreed', 'Error while setting attachment folder'))
						this.$store.commit('setAttachmentFolder', oldFolder)
					}
					this.attachmentFolderLoading = false
				})
		},

		async updateSendMessageKey(event) {
			const newValue = event.target.checked ? SEND_MESSAGE_KEY.SHIFT_ENTER : SEND_MESSAGE_KEY.ENTER
			this.sendMessageKeyLoading = true

			try {
				this.$store.commit('updateSendMessageKey', newValue)
				await setSendMessageKey(newValue)
			} catch (exception) {
				showError(t('spreed', 'Error while setting the send message key'))
			}

			this.sendMessageKeyLoading = false
		},

		handleShowSettings(showSettings) {
			this.showSettings = showSettings
		},

		beforeDestroy() {
			EventBus.$off('show-settings')
		},
	},
}
</script>

<style lang="scss" scoped>

.wrapper {
	overflow-y: scroll;
	padding: 20px;
}

.app-settings-section {
	margin-bottom: 80px;
	&.last {
		margin-bottom: 0;
	}
	&__title {
		overflow: hidden;
		white-space: nowrap;
		text-overflow: ellipsis;
	}
	&__hint {
		color: var(--color-text-lighter);
		padding: 8px 0;
	}
	&__input {
		width: 100%;
	}

	.shortcut-description {
		width: calc(100% - 160px);
	}
}

::v-deep .modal-container {
	display: flex !important;
	flex-direction: column;
	min-width: 250px !important;
	max-width: 500px !important;
	padding: 8px !important;
}

</style>
