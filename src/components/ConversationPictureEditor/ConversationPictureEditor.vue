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
	<Modal
		class="top"
		@close="handleDismiss">
		<div class="conversation-picture-editor"
			@click.stop="">
			<!--native file picker, hidden -->
			<input
				ref="conversationPictureInput"
				type="file"
				accept="image/png, image/jpeg"
				class="hidden-visually"
				@change="loadImage">
			<Cropper
				ref="cropper"
				:src="selectedPicture"
				:resize-image="false"
				stencil-component="circle-stencil"
				:stencil-props="{
					movable: true,
					scalable: true,
					aspectRatio: 1,
					stencilSize: ({ boundaries }) => {
						return {
							width: boundaries.width,
							height: boundaries.height,
						}
					}
				}" />
			<div class="actions" />
			<div class="conversation-picture-editor__actions">
				<button @click="handleDismiss">
					{{ t('spreed', 'Dismiss') }}
				</button>
				<button ref="submitButton" class="primary" @click="uploadResult">
					{{ t('spreed', 'Send') }}
				</button>
			</div>
		</div>
	</Modal>
</template>

<script>

import Modal from '@nextcloud/vue/dist/Components/Modal'
import { Cropper } from 'vue-advanced-cropper'
import { setConversationPicture } from '../../services/conversationsService'
export default {
	name: 'ConversationPictureEditor',

	components: {
		Modal,
		Cropper,
	},

	props: {
		token: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			selectedPicture: '',
			croppedPicture: null,
		}
	},

	mounted() {
		// Upon mounting the component, click the invisible file input immediately

		this.$nextTick(() => {
			this.clickInput()
		})
	},

	methods: {
		focus() {
			this.$nextTick(() => {
				this.$refs.submitButton.focus()
			})
		},

		handleDismiss() {
			this.$emit('close')
		},

		handleUpload() {
			this.$store.dispatch('uploadFiles', this.currentUploadId)
		},
		/**
		 * Clicks the hidden file input when clicking the correspondent ActionButton,
		 * thus opening the file-picker
		 */
		clickInput() {
			console.debug('input', this.$refs.conversationPictureInput)
			this.$refs.conversationPictureInput.click()
		},

		/**
		 * Loads the image into the cropper
		 * @param {object} event The event triggered by the input
		 */
		loadImage(event) {
			// Loading state
			this.isLoading = true
			// Reference to the DOM input element
			const input = event.target
			// Ensure that a file exists before attempting to read it
			if (input.files && input.files[0]) {
				// create a new FileReader to read this image and convert to base64 format
				const reader = new FileReader()
				// Define a callback function to run, when FileReader finishes its job
				reader.onload = (e) => {
					// Note: arrow function used here, so that "this.imageData" refers to the imageData of Vue component
					// Read image as base64 and set to imageData
					this.selectedPicture = e.target.result
				}
				// Start the reader job - read file as a data url (base64 format)
				reader.readAsDataURL(input.files[0])
			}
			// Loading state off
			this.isLoading = false
		},

		async uploadResult() {
			const { canvas } = this.$refs.cropper.getResult()
			if (canvas) {
				await canvas.toBlob(async blob => {
					try {
						await setConversationPicture(this.token, blob)
					} catch (exception) {
						console.debug(exception)
					}
				}, 'image/jpeg')
			}
		},
	},
}
</script>

<style lang="scss" scoped>
@import '../../assets/variables.scss';

.conversation-picture-editor {
	max-width: 480px;
	&__actions {
		display: flex;
		justify-content: flex-end;
		margin-top: 16px;
		margin-bottom: 4px;
		button {
			margin: 0 4px 0 4px;
		}
	}
}

::v-deep .modal-container {
	display: flex !important;
	flex-direction: column;
	padding: 12px !important;
	min-width: 400px;
}

</style>
