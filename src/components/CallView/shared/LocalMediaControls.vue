<!--
  - @copyright Copyright (c) 2019, Daniel Calviño Sánchez (danxuliu@gmail.com)
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
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program. If not, see <http://www.gnu.org/licenses/>.
  -
  -->
<template>
	<div v-shortkey.push="['space']"
		@shortkey="handleShortkey">
		<div class="buttons-bar">
			<div id="muteWrapper">
				<button
					id="mute"
					v-shortkey.once="['m']"
					v-tooltip="audioButtonTooltip"
					:aria-label="audioButtonAriaLabel"
					:class="audioButtonClass"
					@shortkey="toggleAudio"
					@click.stop="toggleAudio">
					<Microphone
						v-if="showMicrophoneOn"
						:size="24"
						title=""
						fill-color="#ffffff"
						decorative />
					<MicrophoneOff
						v-else
						:size="24"
						title=""
						fill-color="#ffffff"
						decorative />
				</button>
				<span v-show="model.attributes.audioAvailable"
					ref="volumeIndicator"
					class="volume-indicator" />
			</div>
			<button
				id="hideVideo"
				v-shortkey.once="['v']"
				v-tooltip="videoButtonTooltip"
				:aria-label="videoButtonAriaLabel"
				:class="videoButtonClass"
				@shortkey="toggleVideo"
				@click.stop="toggleVideo">
				<VideoIcon
					v-if="showVideoOn"
					:size="24"
					title=""
					fill-color="#ffffff"
					decorative />
				<VideoOff
					v-else
					:size="24"
					title=""
					fill-color="#ffffff"
					decorative />
			</button>
			<Actions
				v-if="!screenSharingButtonHidden"
				id="screensharing-button"
				v-tooltip="screenSharingButtonTooltip"
				:aria-label="screenSharingButtonAriaLabel"
				:class="screenSharingButtonClass"
				class="app-navigation-entry-utils-menu-button"
				:boundaries-element="boundaryElement"
				container="#content-vue"
				:open="screenSharingMenuOpen"
				@update:open="screenSharingMenuOpen = true"
				@update:close="screenSharingMenuOpen = false">
				<!-- Actions button icon -->
				<CancelPresentation
					v-if="model.attributes.localScreen"
					slot="icon"
					:size="24"
					title=""
					fill-color="#ffffff"
					decorative />
				<PresentToAll
					v-else
					slot="icon"
					:size="24"
					title=""
					fill-color="#ffffff"
					decorative />
				<!-- /Actions button icon -->
				<!-- Actions -->
				<ActionButton
					v-if="!screenSharingMenuOpen"
					@click.stop="toggleScreenSharingMenu">
					<PresentToAll
						slot="icon"
						:size="24"
						title=""
						fill-color="#ffffff"
						decorative />
					{{ screenSharingButtonTooltip }}
				</ActionButton>
				<ActionButton
					v-if="model.attributes.localScreen"
					@click="showScreen">
					<Monitor
						slot="icon"
						:size="24"
						title=""
						decorative />
					{{ t('spreed', 'Show your screen') }}
				</ActionButton>
				<ActionButton
					v-if="model.attributes.localScreen"
					@click="stopScreen">
					<CancelPresentation
						slot="icon"
						:size="24"
						title=""
						decorative />
					{{ t('spreed', 'Stop screensharing') }}
				</ActionButton>
			</Actions>
			<button
				v-shortkey.once="['r']"
				v-tooltip="t('spreed', 'Lower hand (R)')"
				class="lower-hand"
				:class="model.attributes.raisedHand.state ? '' : 'hidden-visually'"
				:tabindex="model.attributes.raisedHand.state ? 0 : -1"
				:aria-label="t('spreed', 'Lower hand (R)')"
				@shortkey="toggleHandRaised"
				@click.stop="toggleHandRaised">
				<Hand
					:size="24"
					title=""
					fill-color="#ffffff"
					decorative />
			</button>
			<Actions
				v-if="showActions"
				v-tooltip="t('spreed', 'More actions')"
				container="#content-vue"
				:aria-label="t('spreed', 'More actions')">
				<ActionButton
					:close-after-click="true"
					@click="toggleHandRaised">
					<Hand
						slot="icon"
						:size="16"
						decorative
						title="" />
					{{ raiseHandButtonLabel }}
				</ActionButton>
				<ActionButton
					:close-after-click="true"
					@click="toggleVideoEffects">
					<BlurOff
						v-if="isVideoEffectsEnabled"
						slot="icon"
						:size="16"
						decorative
						title="" />
					<Blur
						v-else
						slot="icon"
						:size="16"
						decorative
						title="" />
					{{ toggleVideoEffectsButtonLabel }}
				</ActionButton>
				<ActionSeparator />
				<ActionButton
					icon="icon-settings"
					:close-after-click="true"
					@click="showSettings">
					{{ t('spreed', 'Settings') }}
				</ActionButton>
			</Actions>
		</div>
		<div class="network-connection-state">
			<Popover
				v-if="qualityWarningTooltip"
				:boundaries-element="boundaryElement"
				:aria-label="qualityWarningAriaLabel"
				trigger="hover"
				:auto-hide="false"
				:open="showQualityWarningTooltip">
				<NetworkStrength2Alert
					slot="trigger"
					decorative
					fill-color="#e9322d"
					title=""
					:size="24"
					@mouseover="mouseover = true"
					@mouseleave="mouseover = false" />
				<div class="hint">
					<span>{{ qualityWarningTooltip.content }}</span>
					<div class="hint__actions">
						<button
							v-if="qualityWarningTooltip.action"
							class="primary hint__button"
							@click="executeQualityWarningTooltipAction">
							{{ qualityWarningTooltip.actionLabel }}
						</button>
						<button
							v-if="!isQualityWarningTooltipDismissed"
							class="hint__button"
							@click="dismissQualityWarningTooltip">
							{{ t('spreed', 'Dismiss') }}
						</button>
					</div>
				</div>
			</Popover>
		</div>
	</div>
</template>

<script>
import escapeHtml from 'escape-html'
import { emit } from '@nextcloud/event-bus'
import { showMessage } from '@nextcloud/dialogs'
import CancelPresentation from '../../missingMaterialDesignIcons/CancelPresentation'
import Hand from 'vue-material-design-icons/Hand'
import Microphone from 'vue-material-design-icons/Microphone'
import MicrophoneOff from 'vue-material-design-icons/MicrophoneOff'
import Monitor from 'vue-material-design-icons/Monitor'
import PresentToAll from '../../missingMaterialDesignIcons/PresentToAll'
import Video from 'vue-material-design-icons/Video'
import VideoOff from 'vue-material-design-icons/VideoOff'
import Blur from 'vue-material-design-icons/Blur'
import BlurOff from 'vue-material-design-icons/BlurOff'
import Popover from '@nextcloud/vue/dist/Components/Popover'
import Tooltip from '@nextcloud/vue/dist/Directives/Tooltip'
import { PARTICIPANT } from '../../../constants'
import SpeakingWhileMutedWarner from '../../../utils/webrtc/SpeakingWhileMutedWarner'
import NetworkStrength2Alert from 'vue-material-design-icons/NetworkStrength2Alert'
import { Actions, ActionSeparator, ActionButton } from '@nextcloud/vue'
import { callAnalyzer } from '../../../utils/webrtc/index'
import { CONNECTION_QUALITY } from '../../../utils/webrtc/analyzers/PeerConnectionAnalyzer'

export default {

	name: 'LocalMediaControls',

	directives: {
		tooltip: Tooltip,
	},

	components: {
		NetworkStrength2Alert,
		Popover,
		Actions,
		ActionSeparator,
		ActionButton,
		CancelPresentation,
		Hand,
		Microphone,
		MicrophoneOff,
		PresentToAll,
		VideoIcon: Video,
		VideoOff,
		Monitor,
		Blur,
		BlurOff,
	},

	props: {
		token: {
			type: String,
			required: true,
		},
		model: {
			type: Object,
			required: true,
		},
		localCallParticipantModel: {
			type: Object,
			required: true,
		},
		screenSharingButtonHidden: {
			type: Boolean,
			default: false,
		},
		showActions: {
			type: Boolean,
			default: true,
		},
	},

	data() {
		return {
			mounted: false,
			speakingWhileMutedNotification: null,
			screenSharingMenuOpen: false,
			boundaryElement: document.querySelector('.main-view'),
			mouseover: false,
			callAnalyzer,
			qualityWarningInGracePeriodTimeout: null,
			audioEnabledBeforeSpacebarKeydown: undefined,
			spacebarKeyDown: false,
		}
	},

	computed: {
		isVideoEffectsEnabled() {
			return this.$store.getters.isVideoEffectsEnabled
		},

		raiseHandButtonLabel() {
			if (!this.model.attributes.raisedHand.state) {
				return t('spreed', 'Raise hand (R)')
			}
			return t('spreed', 'Lower hand (R)')
		},

		toggleVideoEffectsButtonLabel() {
			if (!this.isVideoEffectsEnabled) {
				return t('spreed', 'Blur my video')
			}
			return t('spreed', 'Disable blur')
		},

		conversation() {
			return this.$store.getters.conversation(this.token) || this.$store.getters.dummyConversation
		},

		isAudioAllowed() {
			return this.conversation.publishingPermissions === PARTICIPANT.PUBLISHING_PERMISSIONS.ALL
		},

		isVideoAllowed() {
			return this.conversation.publishingPermissions === PARTICIPANT.PUBLISHING_PERMISSIONS.ALL
		},

		isScreensharingAllowed() {
			return this.conversation.publishingPermissions === PARTICIPANT.PUBLISHING_PERMISSIONS.ALL
		},

		audioButtonClass() {
			return {
				'audio-disabled': this.isAudioAllowed && this.model.attributes.audioAvailable && !this.model.attributes.audioEnabled,
				'no-audio-available': !this.isAudioAllowed || !this.model.attributes.audioAvailable,
			}
		},

		showMicrophoneOn() {
			return this.model.attributes.audioAvailable && this.model.attributes.audioEnabled
		},

		audioButtonTooltip() {
			if (!this.isAudioAllowed) {
				return t('spreed', 'You are not allowed to enable audio')
			}

			if (!this.model.attributes.audioAvailable) {
				return {
					content: t('spreed', 'No audio'),
					show: false,
				}
			}

			if (this.speakingWhileMutedNotification && !this.screenSharingMenuOpen) {
				return {
					content: this.speakingWhileMutedNotification,
					show: true,
				}
			}

			return {
				content: this.model.attributes.audioEnabled ? t('spreed', 'Mute audio (M)') : t('spreed', 'Unmute audio (M)'),
				show: false,
			}
		},

		audioButtonAriaLabel() {
			if (!this.model.attributes.audioAvailable) {
				return t('spreed', 'No audio')
			}
			return this.model.attributes.audioEnabled ? t('spreed', 'Mute audio') : t('spreed', 'Unmute audio')
		},

		currentVolumeProportion() {
			// refs can not be accessed on the initial render, only after the
			// component has been mounted.
			if (!this.mounted) {
				return 0
			}

			// WebRTC volume goes from -100 (silence) to 0 (loudest sound in the
			// system); for the volume indicator only sounds above the threshold
			// are taken into account.
			let currentVolumeProportion = 0
			if (this.model.attributes.currentVolume > this.model.attributes.volumeThreshold) {
				currentVolumeProportion = (this.model.attributes.volumeThreshold - this.model.attributes.currentVolume) / this.model.attributes.volumeThreshold
			}

			return currentVolumeProportion
		},

		videoButtonClass() {
			return {
				'video-disabled': this.isVideoAllowed && this.model.attributes.videoAvailable && !this.model.attributes.videoEnabled,
				'no-video-available': !this.isVideoAllowed || !this.model.attributes.videoAvailable,
			}
		},

		showVideoOn() {
			return this.model.attributes.videoAvailable && this.model.attributes.videoEnabled
		},

		videoButtonTooltip() {
			if (!this.isVideoAllowed) {
				return t('spreed', 'You are not allowed to enable video')
			}

			if (!this.model.attributes.videoAvailable) {
				return t('spreed', 'No camera')
			}

			if (this.model.attributes.videoEnabled) {
				return t('spreed', 'Disable video (V)')
			}

			if (!this.model.getWebRtc() || !this.model.getWebRtc().connection || this.model.getWebRtc().connection.getSendVideoIfAvailable()) {
				return t('spreed', 'Enable video (V)')
			}

			return t('spreed', 'Enable video (V) - Your connection will be briefly interrupted when enabling the video for the first time')
		},

		videoButtonAriaLabel() {
			if (!this.model.attributes.videoAvailable) {
				return t('spreed', 'No camera')
			}

			if (this.model.attributes.videoEnabled) {
				return t('spreed', 'Disable video')
			}

			if (!this.model.getWebRtc() || !this.model.getWebRtc().connection || this.model.getWebRtc().connection.getSendVideoIfAvailable()) {
				return t('spreed', 'Enable video')
			}

			return t('spreed', 'Enable video. Your connection will be briefly interrupted when enabling the video for the first time')
		},

		screenSharingButtonClass() {
			return {
				'screensharing-disabled': this.isScreensharingAllowed && !this.model.attributes.localScreen,
				'no-screensharing-available': !this.isScreensharingAllowed,
			}
		},

		screenSharingButtonTooltip() {
			if (!this.isScreensharingAllowed) {
				return t('spreed', 'You are not allowed to enable screensharing')
			}

			if (this.screenSharingMenuOpen) {
				return null
			}

			if (!this.isScreensharingAllowed) {
				return t('spreed', 'No screensharing')
			}

			return this.model.attributes.localScreen ? t('spreed', 'Screensharing options') : t('spreed', 'Enable screensharing')
		},

		screenSharingButtonAriaLabel() {
			if (this.screenSharingMenuOpen) {
				return ''
			}

			return this.model.attributes.localScreen ? t('spreed', 'Screensharing options') : t('spreed', 'Enable screensharing')
		},

		isQualityWarningTooltipDismissed() {
			return this.$store.getters.isQualityWarningTooltipDismissed
		},

		showQualityWarningTooltip() {
			return this.qualityWarningTooltip && (!this.isQualityWarningTooltipDismissed || this.mouseover)
		},

		showQualityWarning() {
			return this.senderConnectionQualityIsBad || this.qualityWarningInGracePeriodTimeout
		},

		senderConnectionQualityIsBad() {
			return this.senderConnectionQualityAudioIsBad
				|| this.senderConnectionQualityVideoIsBad
				|| this.senderConnectionQualityScreenIsBad
		},

		senderConnectionQualityAudioIsBad() {
			return callAnalyzer
				&& (callAnalyzer.attributes.senderConnectionQualityAudio === CONNECTION_QUALITY.VERY_BAD
				 || callAnalyzer.attributes.senderConnectionQualityAudio === CONNECTION_QUALITY.NO_TRANSMITTED_DATA)
		},

		senderConnectionQualityVideoIsBad() {
			return callAnalyzer
				&& (callAnalyzer.attributes.senderConnectionQualityVideo === CONNECTION_QUALITY.VERY_BAD
				 || callAnalyzer.attributes.senderConnectionQualityVideo === CONNECTION_QUALITY.NO_TRANSMITTED_DATA)
		},

		senderConnectionQualityScreenIsBad() {
			return callAnalyzer
				&& (callAnalyzer.attributes.senderConnectionQualityScreen === CONNECTION_QUALITY.VERY_BAD
				 || callAnalyzer.attributes.senderConnectionQualityScreen === CONNECTION_QUALITY.NO_TRANSMITTED_DATA)
		},

		qualityWarningAriaLabel() {
			let label = ''
			if (!this.model.attributes.audioEnabled && this.model.attributes.videoEnabled && this.model.attributes.localScreen) {
				label = t('spreed', 'Bad sent video and screen quality.')
			} else if (!this.model.attributes.audioEnabled && this.model.attributes.localScreen) {
				label = t('spreed', 'Bad sent screen quality.')
			} else if (!this.model.attributes.audioEnabled && this.model.attributes.videoEnabled) {
				label = t('spreed', 'Bad sent video quality.')
			} else if (this.model.attributes.videoEnabled && this.model.attributes.localScreen) {
				label = t('spreed', 'Bad sent audio, video and screen quality.')
			} else if (this.model.attributes.localScreen) {
				label = t('spreed', 'Bad sent audio and screen quality.')
			} else if (this.model.attributes.videoEnabled) {
				label = t('spreed', 'Bad sent audio and video quality.')
			} else {
				label = t('spreed', 'Bad sent audio quality.')
			}

			return label
		},

		qualityWarningTooltip() {
			if (!this.showQualityWarning) {
				return null
			}

			const tooltip = {}
			if (!this.model.attributes.audioEnabled && this.model.attributes.videoEnabled && this.model.attributes.localScreen) {
				tooltip.content = t('spreed', 'Your internet connection or computer are busy and other participants might be unable to see you. To improve the situation try to disable your video while doing a screenshare.')
				tooltip.actionLabel = t('spreed', 'Disable video')
				tooltip.action = 'disableVideo'
			} else if (!this.model.attributes.audioEnabled && this.model.attributes.localScreen) {
				tooltip.content = t('spreed', 'Your internet connection or computer are busy and other participants might be unable to see your screen.')
				tooltip.actionLabel = ''
				tooltip.action = ''
			} else if (!this.model.attributes.audioEnabled && this.model.attributes.videoEnabled) {
				tooltip.content = t('spreed', 'Your internet connection or computer are busy and other participants might be unable to see you.')
				tooltip.actionLabel = ''
				tooltip.action = ''
			} else if (this.model.attributes.videoEnabled && this.model.attributes.localScreen) {
				tooltip.content = t('spreed', 'Your internet connection or computer are busy and other participants might be unable to understand and see you. To improve the situation try to disable your video while doing a screenshare.')
				tooltip.actionLabel = t('spreed', 'Disable video')
				tooltip.action = 'disableVideo'
			} else if (this.model.attributes.localScreen) {
				tooltip.content = t('spreed', 'Your internet connection or computer are busy and other participants might be unable to understand and see your screen. To improve the situation try to disable your screenshare.')
				tooltip.actionLabel = t('spreed', 'Disable screenshare')
				tooltip.action = 'disableScreenShare'
			} else if (this.model.attributes.videoEnabled) {
				tooltip.content = t('spreed', 'Your internet connection or computer are busy and other participants might be unable to understand and see you. To improve the situation try to disable your video.')
				tooltip.actionLabel = t('spreed', 'Disable video')
				tooltip.action = 'disableVideo'
			} else {
				tooltip.content = t('spreed', 'Your internet connection or computer are busy and other participants might be unable to understand you.')
				tooltip.actionLabel = ''
				tooltip.action = ''
			}

			return tooltip
		},

	},

	watch: {
		currentVolumeProportion() {
			// The volume meter is updated directly in the DOM as it is
			// more efficient than relying on Vue.js to animate the style property,
			// because the latter would also process all neighboring components repeatedly.
			this.updateVolumeMeter()
		},

		senderConnectionQualityIsBad(senderConnectionQualityIsBad) {
			if (!senderConnectionQualityIsBad) {
				return
			}

			if (this.qualityWarningInGracePeriodTimeout) {
				window.clearTimeout(this.qualityWarningInGracePeriodTimeout)
			}

			this.qualityWarningInGracePeriodTimeout = window.setTimeout(() => {
				this.qualityWarningInGracePeriodTimeout = null
			}, 10000)
		},
	},

	mounted() {
		this.mounted = true
		this.updateVolumeMeter()

		this.speakingWhileMutedWarner = new SpeakingWhileMutedWarner(this.model, this)
	},

	beforeDestroy() {
		this.speakingWhileMutedWarner.destroy()
	},

	methods: {
		updateVolumeMeter() {
			if (!this.mounted) {
				return
			}

			const volumeIndicatorStyle = window.getComputedStyle ? getComputedStyle(this.$refs.volumeIndicator, null) : this.$refs.volumeIndicator.currentStyle
			const maximumVolumeIndicatorHeight = this.$refs.volumeIndicator.parentElement.clientHeight - (parseInt(volumeIndicatorStyle.bottom, 10) * 2)

			// round up to avoid property changes
			const height = Math.floor(maximumVolumeIndicatorHeight * this.currentVolumeProportion)
			this.$refs.volumeIndicator.style.height = height + 'px'
		},

		showSettings() {
			emit('show-settings')
		},

		/**
		 * This method executes on spacebar keydown and keyup
		 */
		handleShortkey() {
			if (!this.model.attributes.audioAvailable) {
				return
			}

			if (!this.spacebarKeyDown) {
				this.audioEnabledBeforeSpacebarKeydown = this.model.attributes.audioEnabled
				this.spacebarKeyDown = true
				this.toggleAudio()
			} else {
				this.spacebarKeyDown = false
				if (this.audioEnabledBeforeSpacebarKeydown) {
					this.model.enableAudio()
				} else {
					this.model.disableAudio()
				}
				this.audioEnabledBeforeSpacebarKeydown = undefined
			}

		},

		toggleAudio() {
			if (!this.model.attributes.audioAvailable) {
				return
			}

			if (this.model.attributes.audioEnabled) {
				this.model.disableAudio()
			} else {
				this.model.enableAudio()
			}
		},

		setSpeakingWhileMutedNotification(message) {
			this.speakingWhileMutedNotification = message
		},

		toggleVideoEffects() {
			this.$store.dispatch(
				'setVideoEffectsEnabled',
				!this.$store.getters.isVideoEffectsEnabled,
			)
		},

		toggleVideo() {
			if (!this.model.attributes.videoAvailable) {
				return
			}

			if (this.model.attributes.videoEnabled) {
				this.model.disableVideo()
			} else {
				this.model.enableVideo()
			}
		},

		toggleScreenSharingMenu() {
			if (!this.isScreensharingAllowed) {
				return
			}

			if (!this.model.getWebRtc().capabilities.supportScreenSharing) {
				if (window.location.protocol === 'https:') {
					showMessage(t('spreed', 'Screen sharing is not supported by your browser.'))
				} else {
					showMessage(t('spreed', 'Screen sharing requires the page to be loaded through HTTPS.'))
				}
				return
			}

			if (this.model.attributes.localScreen) {
				this.screenSharingMenuOpen = !this.screenSharingMenuOpen
			} else {
				this.startShareScreen()
			}
		},

		toggleHandRaised() {
			const state = !this.model.attributes.raisedHand?.state
			this.model.toggleHandRaised(state)
			this.$store.dispatch(
				'setParticipantHandRaised',
				{
					sessionId: this.$store.getters.getSessionId(),
					raisedHand: this.model.attributes.raisedHand,
				}
			)
		},

		showScreen() {
			if (this.model.attributes.localScreen) {
				this.$emit('switch-screen-to-id', this.localCallParticipantModel.attributes.peerId)
			}

			this.screenSharingMenuOpen = false
		},

		stopScreen() {
			this.model.stopSharingScreen()

			this.screenSharingMenuOpen = false
		},

		startShareScreen(mode) {
			this.model.shareScreen(mode, function(err) {
				if (!err) {
					return
				}

				let extensionURL = null

				switch (err.name) {
				case 'HTTPS_REQUIRED':
					showMessage(t('spreed', 'Screensharing requires the page to be loaded through HTTPS.'))
					break
				case 'PERMISSION_DENIED':
				case 'NotAllowedError':
				case 'CEF_GETSCREENMEDIA_CANCELED': // Experimental, may go away in the future.
					break
				case 'FF52_REQUIRED':
					showMessage(t('spreed', 'Sharing your screen only works with Firefox version 52 or newer.'))
					break
				case 'EXTENSION_UNAVAILABLE':
					if (window.chrome) { // Chrome
						extensionURL = 'https://chrome.google.com/webstore/detail/screensharing-for-nextclo/kepnpjhambipllfmgmbapncekcmabkol'
					}

					if (extensionURL) {
						const text = t('spreed', 'Screensharing extension is required to share your screen.')
						const element = '<a href="' + extensionURL + '" target="_blank">' + escapeHtml(text) + '</a>'

						showMessage(element, { isHTML: true })
					} else {
						showMessage(t('spreed', 'Please use a different browser like Firefox or Chrome to share your screen.'))
					}
					break
				default:
					showMessage(t('spreed', 'An error occurred while starting screensharing.'))
					break
				}
			})
		},

		executeQualityWarningTooltipAction() {
			if (this.qualityWarningTooltip.action === '') {
				return
			}
			if (this.qualityWarningTooltip.action === 'disableScreenShare') {
				this.model.stopSharingScreen()
				this.dismissQualityWarningTooltip()
			} else if (this.qualityWarningTooltip.action === 'disableVideo') {
				this.model.disableVideo()
				this.dismissQualityWarningTooltip()
			}
		},

		dismissQualityWarningTooltip() {
			this.$store.dispatch('dismissQualityWarningTooltip')
		},
	},
}
</script>

<style lang="scss" scoped>
@import '../../../assets/variables.scss';

.buttons-bar {
	button, .action-item {
		vertical-align: middle;
	}
}

.buttons-bar button, .buttons-bar button:active {
	background-color: transparent;
	border: none;
	margin: 0;
	padding: 0 12px;
}

.buttons-bar #screensharing-menu button {
	width: 100%;
	height: auto;
}

.buttons-bar button.audio-disabled,
.buttons-bar button.video-disabled,
.buttons-bar button.screensharing-disabled,
.buttons-bar button.lower-hand {
	opacity: .7;
}

.buttons-bar button.audio-disabled:not(.no-audio-available),
.buttons-bar button.video-disabled:not(.no-video-available),
.buttons-bar button.screensharing-disabled:not(.no-screensharing-available),
.buttons-bar button.lower-hand {
	&:hover,
	&:focus {
		opacity: 1;
	}
}

.buttons-bar button.no-audio-available,
.buttons-bar button.no-video-available,
.buttons-bar button.no-screensharing-available {
	&, & * {
		opacity: .7;
		cursor: not-allowed;
	}
}

.buttons-bar button.no-audio-available:active,
.buttons-bar button.no-video-available:active,
.buttons-bar button.no-screensharing-available:active {
	background-color: transparent;
}

#muteWrapper {
	display: inline-block;

	/* Make the wrapper the positioning context of the volume indicator. */
	position: relative;
}

#muteWrapper .volume-indicator {
	position: absolute;

	width: 3px;
	right: 0;

	/* The button height is 44px; the volume indicator button is 36px at
	* maximum, but its value will be changed based on the current volume; the
	* height change will reveal more or less of the gradient, which has
	* absolute dimensions and thus does not change when the height changes. */
	height: 36px;
	bottom: 4px;

	background: linear-gradient(0deg, green, yellow, red 36px);

	opacity: 0.7;
}

#muteWrapper .microphone-off-icon + .volume-indicator {
	background: linear-gradient(0deg, gray, white 36px);
}

.network-connection-state {
	position: absolute;
	bottom: 3px;
	right: 12px;
	width: 32px;
	height: 32px;
}

.hint {
	padding: 12px;
	max-width: 300px;
	text-align: left;
	&__actions {
		display: flex;
		flex-direction: row-reverse;
		justify-content: space-between;
		padding-top:4px;
	}
	&__button {
		height: $clickable-area;
	}
}

::v-deep button.action-item,
::v-deep .action-item__menutoggle {
	// Fix screensharing icon width
	&:hover,
	&:focus,
	&:active {
		background-color: transparent;
	}
}
</style>
