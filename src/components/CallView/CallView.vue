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
	<div id="call-container">
		<EmptyCallView
			v-if="!remoteParticipantsCount && !screenSharingActive && !isGrid"
			:is-sidebar="isSidebar" />
		<div id="videos">
			<template
				v-if="!isGrid">
				<LocalMediaControls
					class="local-media-controls"
					:class="{ 'local-media-controls--sidebar': isSidebar }"
					:token="token"
					:model="localMediaModel"
					:show-actions="!isSidebar"
					:local-call-participant-model="localCallParticipantModel"
					:screen-sharing-button-hidden="isSidebar"
					@switch-screen-to-id="$emit('switchScreenToId', $event)" />

				<!-- Selected override mode -->
				<div v-if="showSelected"
					ref="videoContainer"
					class="video__promoted selected-video"
					:class="{'full-page': isOneToOne}">
					<template v-for="callParticipantModel in reversedCallParticipantModels">
						<Video
							v-if="callParticipantModel.attributes.peerId === selectedVideoPeerId"
							:key="callParticipantModel.attributes.selectedVideoPeerId"
							:token="token"
							:model="callParticipantModel"
							:shared-data="sharedDatas[selectedVideoPeerId]"
							:show-talking-highlight="false"
							:is-grid="true"
							:is-big="true"
							:fit-video="true"
							@switchScreenToId="_switchScreenToId" />
					</template>
				</div>
				<!-- Screens -->
				<div v-else-if="!isSidebar && (showLocalScreen || showRemoteScreen)" id="screens">
					<!-- local screen -->
					<Screen
						v-if="showLocalScreen"
						:token="token"
						:local-media-model="localMediaModel"
						:shared-data="localSharedData"
						:is-big="true" />
					<!-- remote screen -->
					<template
						v-else>
						<template
							v-for="callParticipantModel in reversedCallParticipantModels">
							<Screen
								v-if="callParticipantModel.attributes.peerId === shownRemoteScreenPeerId"
								:key="'screen-' + callParticipantModel.attributes.peerId"
								:token="token"
								:call-participant-model="callParticipantModel"
								:shared-data="sharedDatas[shownRemoteScreenPeerId]"
								:is-big="true" />
						</template>
					</template>
				</div>
				<!-- Local Video Override mode (following own video) -->
				<div v-else-if="showLocalVideo"
					ref="videoContainer"
					class="video__promoted selected-video--local"
					:class="{'full-page': isOneToOne}">
					<LocalVideo
						ref="localVideo"
						:fit-video="true"
						:is-stripe="false"
						:show-controls="false"
						:is-big="true"
						:token="token"
						:local-media-model="localMediaModel"
						:video-container-aspect-ratio="videoContainerAspectRatio"
						:local-call-participant-model="localCallParticipantModel"
						:is-sidebar="false"
						@switchScreenToId="1" />
				</div>
				<!-- Promoted "autopilot" mode -->
				<div v-else
					ref="videoContainer"
					class="video__promoted autopilot"
					:class="{'full-page': isOneToOne}">
					<template v-for="callParticipantModel in reversedCallParticipantModels">
						<Video
							v-if="sharedDatas[callParticipantModel.attributes.peerId].promoted"
							:key="callParticipantModel.attributes.peerId"
							:token="token"
							:model="callParticipantModel"
							:shared-data="sharedDatas[callParticipantModel.attributes.peerId]"
							:show-talking-highlight="false"
							:is-grid="true"
							:fit-video="true"
							:is-big="true"
							:is-sidebar="isSidebar"
							@switchScreenToId="_switchScreenToId" />
					</template>
				</div>
			</template>

			<!-- Stripe or fullscreen grid depending on `isGrid` -->
			<Grid
				v-if="!isSidebar"
				v-bind="$attrs"
				:is-stripe="!isGrid"
				:token="token"
				:fit-video="true"
				:has-pagination="true"
				:call-participant-models="callParticipantModels"
				:screens="screens"
				:target-aspect-ratio="gridTargetAspectRatio"
				:local-media-model="localMediaModel"
				:local-call-participant-model="localCallParticipantModel"
				:shared-datas="sharedDatas"
				@select-video="handleSelectVideo"
				@click-local-video="handleClickLocalVideo" />
			<!-- Local video if sidebar -->
			<LocalVideo
				v-if="isSidebar && !showLocalVideo"
				ref="localVideo"
				class="local-video"
				:class="{ 'local-video--sidebar': isSidebar }"
				:show-controls="false"
				:fit-video="true"
				:is-stripe="true"
				:token="token"
				:local-media-model="localMediaModel"
				:video-container-aspect-ratio="videoContainerAspectRatio"
				:local-call-participant-model="localCallParticipantModel"
				:is-sidebar="isSidebar"
				@switchScreenToId="1"
				@click-video="handleClickLocalVideo" />
		</div>
	</div>
</template>

<script>
import Grid from './Grid/Grid'
import { localMediaModel, localCallParticipantModel, callParticipantCollection } from '../../utils/webrtc/index'
import { fetchPeers } from '../../services/callsService'
import { showMessage } from '@nextcloud/dialogs'
import LocalMediaControls from './shared/LocalMediaControls'
import EmptyCallView from './shared/EmptyCallView'
import Video from './shared/Video'
import LocalVideo from './shared/LocalVideo'
import Screen from './shared/Screen'
import debounce from 'debounce'
import { EventBus } from '../../services/EventBus'

export default {
	name: 'CallView',

	components: {
		Grid,
		EmptyCallView,
		Video,
		LocalVideo,
		LocalMediaControls,
		Screen,
	},

	props: {
		token: {
			type: String,
			required: true,
		},
		// Determines whether this component is used in the sidebar
		isSidebar: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			screens: [],
			localMediaModel: localMediaModel,
			localCallParticipantModel: localCallParticipantModel,
			sharedDatas: {},
			raisedHandUnwatchers: {},
			speakingUnwatchers: {},
			screenUnwatchers: {},
			speakers: [],
			// callParticipantModelsWithScreen: [],
			localSharedData: {
				screenVisible: true,
			},
			callParticipantCollection: callParticipantCollection,
			videoContainerAspectRatio: 0,
		}
	},
	computed: {
		callParticipantModels() {
			return callParticipantCollection.callParticipantModels.filter(callParticipantModel => !callParticipantModel.attributes.internal)
		},

		reversedCallParticipantModels() {
			return this.callParticipantModels.slice().reverse()
		},

		remoteParticipantsCount() {
			return this.callParticipantModels.length
		},

		callParticipantModelsWithScreen() {
			return this.callParticipantModels.filter(callParticipantModel => callParticipantModel.attributes.screen)
		},

		callParticipantModelsWithVideo() {
			return this.callParticipantModels.filter(callParticipantModel => {
				return callParticipantModel.attributes.videoAvailable
					&& this.sharedDatas[callParticipantModel.attributes.peerId].videoEnabled
					&& (typeof callParticipantModel.attributes.stream === 'object')
			})
		},

		injectableLocalMediaModel() {
			return localMediaModel
		},

		localScreen() {
			return localMediaModel.attributes.localScreen
		},

		screenSharingActive() {
			return this.screens.length > 0
		},

		isGrid() {
			return this.$store.getters.isGrid && !this.isSidebar
		},

		gridTargetAspectRatio() {
			if (this.isGrid) {
				return 1.5
			} else {
				return 1
			}
		},

		selectedVideoPeerId() {
			return this.$store.getters.selectedVideoPeerId
		},

		hasSelectedScreen() {
			return this.selectedVideoPeerId !== null && this.screens.includes(this.selectedVideoPeerId)
		},

		hasSelectedVideo() {
			return this.selectedVideoPeerId !== null && !this.screens.includes(this.selectedVideoPeerId)
		},

		isOneToOne() {
			return this.callParticipantModels.length === 1
		},
		hasLocalVideo() {
			return this.localMediaModel.attributes.videoEnabled
		},

		hasRemoteVideo() {
			return this.callParticipantModelsWithVideo.length > 0
		},

		hasLocalScreen() {
			return !!this.localMediaModel.attributes.localScreen
		},

		hasRemoteScreen() {
			return this.callParticipantModelsWithScreen.length > 0
		},
		// The following conditions determine what to show in the "Big container"
		// of the promoted view

		// Show selected video (other than local)
		showSelected() {
			return this.hasSelectedVideo && !this.showLocalVideo
		},

		// Shows the local video if selected
		showLocalVideo() {
			return this.hasLocalVideo && this.selectedVideoPeerId === 'local'
		},

		// Show local screen
		showLocalScreen() {
			return this.hasLocalScreen && this.selectedVideoPeerId === null
		},

		// Show somebody else's screen
		showRemoteScreen() {
			return this.shownRemoteScreenPeerId !== null
		},

		shownRemoteScreenPeerId() {
			if (!this.screenSharingActive) {
				return null
			}

			if (!this.hasRemoteScreen) {
				return null
			}

			if (this.screens.includes(this.selectedVideoPeerId)) {
				return this.selectedVideoPeerId
			}

			if (!this.hasSelectedScreen) {
				return this.screens[0]
			}

			return null
		},
	},
	watch: {
		localScreen: function(localScreen) {
			this._setScreenAvailable(localCallParticipantModel.attributes.peerId, localScreen)
		},

		callParticipantModels: function(models) {
			this.updateDataFromCallParticipantModels(models)
		},

		'speakers': function() {
			this._setPromotedParticipant()
		},

		'screenSharingActive': function() {
			this._setPromotedParticipant()
		},

		'screens': function() {
			this._setScreenVisible()

		},

		'callParticipantModelsWithScreen': function(newValue, previousValue) {
			// Everytime a new screen is shared, switch to promoted view
			if (newValue.length > previousValue.length) {
				this.$store.dispatch('startPresentation')
			} else if (newValue.length === 0 && previousValue.length > 0) {
				// last screen share stopped, reopening stripe
				this.$store.dispatch('stopPresentation')
			}
		},
		'showLocalScreen': function(showLocalScreen) {
			// Everytime the local screen is shared, switch to promoted view
			if (showLocalScreen) {
				this.$store.dispatch('startPresentation')
			} else {
				this.$store.dispatch('stopPresentation')
			}
		},
		'hasLocalVideo': function(newValue) {
			if (this.$store.getters.selectedVideoPeerId === 'local') {
				if (!newValue) {
					this.$store.dispatch('selectedVideoPeerId', null)
				}
			}
		},

	},
	created() {
		// Ensure that data is properly initialized before mounting the
		// subviews.
		this.updateDataFromCallParticipantModels(this.callParticipantModels)
	},
	mounted() {
		EventBus.$on('refreshPeerList', this.debounceFetchPeers)

		callParticipantCollection.on('remove', this._lowerHandWhenParticipantLeaves)
	},
	beforeDestroy() {
		EventBus.$off('refreshPeerList', this.debounceFetchPeers)

		callParticipantCollection.off('remove', this._lowerHandWhenParticipantLeaves)
	},
	methods: {
		/**
		 * Updates data properties that depend on the CallParticipantModels.
		 *
		 * The data contains some properties that can not be dynamically
		 * computed but that depend on the current CallParticipantModels, so
		 * this function adds and removes elements and watchers as needed based
		 * on the given CallParticipantModels.
		 *
		 * @param {Array} models the array of CallParticipantModels
		 */
		updateDataFromCallParticipantModels(models) {
			const addedModels = models.filter(model => !this.sharedDatas[model.attributes.peerId])
			const removedModelIds = Object.keys(this.sharedDatas).filter(sharedDataId => models.find(model => model.attributes.peerId === sharedDataId) === undefined)

			removedModelIds.forEach(removedModelId => {
				this.$delete(this.sharedDatas, removedModelId)

				this.speakingUnwatchers[removedModelId]()
				// Not reactive, but not a problem
				delete this.speakingUnwatchers[removedModelId]

				this.screenUnwatchers[removedModelId]()
				// Not reactive, but not a problem
				delete this.screenUnwatchers[removedModelId]

				this.raisedHandUnwatchers[removedModelId]()
				// Not reactive, but not a problem
				delete this.raisedHandUnwatchers[removedModelId]

				const index = this.speakers.findIndex(speaker => speaker.id === removedModelId)
				this.speakers.splice(index, 1)

				this._setScreenAvailable(removedModelId, false)
			})

			addedModels.forEach(addedModel => {
				const sharedData = {
					promoted: false,
					videoEnabled: true,
					screenVisible: false,
				}

				this.$set(this.sharedDatas, addedModel.attributes.peerId, sharedData)

				// Not reactive, but not a problem
				this.speakingUnwatchers[addedModel.attributes.peerId] = this.$watch(function() {
					return addedModel.attributes.speaking
				}, function(speaking) {
					this._setSpeaking(addedModel.attributes.peerId, speaking)
				})

				this.speakers.push({
					id: addedModel.attributes.peerId,
					active: false,
				})

				// Not reactive, but not a problem
				this.screenUnwatchers[addedModel.attributes.peerId] = this.$watch(function() {
					return addedModel.attributes.screen
				}, function(screen) {
					this._setScreenAvailable(addedModel.attributes.peerId, screen)
				})

				// Not reactive, but not a problem
				this.raisedHandUnwatchers[addedModel.attributes.peerId] = this.$watch(function() {
					return addedModel.attributes.raisedHand
				}, function(raisedHand) {
					this._handleParticipantRaisedHand(addedModel, raisedHand)
				})
			})
		},

		_setSpeaking(peerId, speaking) {
			if (speaking) {
				// Move the speaker to the first element of the list
				const index = this.speakers.findIndex(speaker => speaker.id === peerId)
				const speaker = this.speakers[index]
				speaker.active = true
				this.speakers.splice(index, 1)
				this.speakers.unshift(speaker)

				return
			}

			// Set the speaker as not speaking
			const index = this.speakers.findIndex(speaker => speaker.id === peerId)
			const speaker = this.speakers[index]
			speaker.active = false

			// Move the speaker after all the active speakers
			if (index === 0) {
				this.speakers.shift()

				const firstInactiveSpeakerIndex = this.speakers.findIndex(speaker => !speaker.active)
				if (firstInactiveSpeakerIndex === -1) {
					this.speakers.push(speaker)
				} else {
					this.speakers.splice(firstInactiveSpeakerIndex, 0, speaker)
				}
			}
		},

		_handleParticipantRaisedHand(callParticipantModel, raisedHand) {
			const nickName = callParticipantModel.attributes.name || callParticipantModel.attributes.userId
			// sometimes the nick name is not available yet...
			if (nickName) {
				if (raisedHand?.state) {
					showMessage(t('spreed', '{nickName} raised their hand.', { nickName: nickName }))
				}
			} else {
				if (raisedHand?.state) {
					showMessage(t('spreed', 'A participant raised their hand.'))
				}
			}

			// update in callViewStore
			this.$store.dispatch('setParticipantHandRaised', {
				sessionId: callParticipantModel.attributes.nextcloudSessionId,
				raisedHand: raisedHand,
			})
		},

		_lowerHandWhenParticipantLeaves(callParticipantCollection, callParticipantModel) {
			this.$store.dispatch('setParticipantHandRaised', {
				sessionId: callParticipantModel.attributes.nextcloudSessionId,
				raisedHand: false,
			})
		},

		_setScreenAvailable(id, screen) {
			if (screen) {
				this.screens.unshift(id)

				return
			}

			const index = this.screens.indexOf(id)
			if (index !== -1) {
				this.screens.splice(index, 1)
			}
		},

		_setPromotedParticipant() {
			Object.values(this.sharedDatas).forEach(sharedData => {
				sharedData.promoted = false
			})

			if (!this.screenSharingActive && this.speakers.length) {
				this.sharedDatas[this.speakers[0].id].promoted = true
			}
		},

		_switchScreenToId(id) {
			const index = this.screens.indexOf(id)
			if (index === -1) {
				return
			}

			this.screens.splice(index, 1)
			this.screens.unshift(id)
		},

		_setScreenVisible() {
			this.localSharedData.screenVisible = false

			Object.values(this.sharedDatas).forEach(sharedData => {
				sharedData.screenVisible = false
			})

			if (!this.screens.length) {
				return
			}

			if (this.screens[0] === this.localCallParticipantModel.attributes.peerId) {
				this.localSharedData.screenVisible = true

				return
			}

			this.sharedDatas[this.screens[0]].screenVisible = true
		},

		// Get the aspect ratio of the incoming stream
		getVideoContainerAspectRatio() {
			const videoContainerWidth = this.$refs.videoContainer.clientWidth
			const VideoContainerHeight = this.$refs.videoContainer.clientHeight
			this.videoContainerAspectRatio = videoContainerWidth / VideoContainerHeight
		},
		handleSelectVideo(peerId) {
			if (this.isSidebar) {
				return
			}
			this.$store.dispatch('startPresentation')
			this.$store.dispatch('selectedVideoPeerId', peerId)
			this.isLocalVideoSelected = false
		},
		handleClickLocalVideo() {
			// DO nothing if no video
			if (!this.hasLocalVideo || this.isSidebar) {
				return
			}
			// Deselect possible selected video
			this.$store.dispatch('selectedVideoPeerId', 'local')
			this.$store.dispatch('startPresentation')
		},

		debounceFetchPeers: debounce(async function() {
			const token = this.token
			try {
				const response = await fetchPeers(token)
				this.$store.dispatch('purgePeersStore')

				response.data.ocs.data.forEach((peer) => {
					this.$store.dispatch('addPeer', {
						token,
						peer,
					})
				})
			} catch (exception) {
				// Just means guests have no name, so don't error …
				console.error(exception)
			}
		}, 1500),
	},
}
</script>

<style lang="scss" scoped>
@import '../../assets/variables.scss';

.call-view {
	width: 100%;
	height: 100%;
	overflow: hidden;
	background-color: $color-call-background;
}

#call-container {
	width: 100%;
	height: 100%;
	background-color: $color-call-background;
}

#videos {
	position: absolute;
	width: 100%;
	height: 100%;
	top: 0;
	overflow: hidden;
	display: -webkit-box;
	display: -moz-box;
	display: -ms-flexbox;
	display: -webkit-flex;
	display: flex;
	-webkit-justify-content: space-around;
	justify-content: space-around;
	-webkit-align-items: flex-end;
	align-items: flex-end;
	flex-direction: column;
}

.video__promoted {
	position:relative;
	height: 100%;
	width: 100%;
	display: block;
}

.video__promoted.full-page {
	/* make the promoted video cover the whole call view */
	position: static;
}

.local-video {
	position: absolute;
	right: 0;
	bottom: 0;
	width: 300px;
	height: 250px;
	&--sidebar {
		width: 150px;
		height: 100px;
	}
}

#videos.hidden {
	display: none;
}

::v-deep video {
	z-index: 0;
	/* default filter for slightly better look */
	/* Disabled for now as it causes a huuuuge performance drop.
	 CPU usage is more than halved without this.
	 -webkit-filter: contrast(1.1) saturate(1.1) sepia(.1);
	 filter: contrast(1.1) saturate(1.1) sepia(.1);
	 */
	vertical-align: top; /* fix white line below video */
}

#videos .videoContainer ::v-deep .avatardiv {
	box-shadow: 0 0 15px var(--color-box-shadow);
}

.participants-1 #videos .videoContainer ::v-deep video,
.participants-2 #videos .videoContainer ::v-deep video {
	padding: 0;
}

.videoContainer ::v-deep .avatar-container .avatardiv {
	display: block;
	margin-left: auto;
	margin-right: auto;
}

.videoContainer.promoted ::v-deep .avatar-container {
	top: 30%;
}

.videoContainer.promoted ::v-deep .avatar-container + .nameIndicator {
	display: none;
}

.videoContainer.promoted ::v-deep .mediaIndicator {
	display: none !important;
}

@media only screen and (max-width: 768px) {
	.participants-1 .videoView,
	.participants-2 .videoView {
		max-height: 35%;
	}
}

.participants-1 .videoView ::v-deep video,
.participants-2 .videoView ::v-deep video {
	position: absolute;
	max-height: 100% !important;
	bottom: 0;
	border-top-right-radius: 3px;
	right: 0;
}

#screens {
	position: relative;
	width: 100%;
	height: 100%;
	overflow: hidden;
}

::v-deep .nameIndicator {
	position: absolute;
	bottom: 0;
	left: 0;
	padding: 12px;
	color: #fff;
	text-shadow: 3px 3px 10px rgba(0, 0, 0, .5), 3px -3px 10px rgba(0, 0, 0, .5), -3px 3px 10px rgba(0, 0, 0, .5), -3px -3px 10px rgba(0, 0, 0, .5);
	width: 100%;
	text-align: center;
	font-size: 20px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

::v-deep .videoView .nameIndicator {
	padding: 0;
	overflow: visible;
}

.participants-1 .videoView ::v-deep .nameIndicator,
.participants-2 .videoView ::v-deep .nameIndicator {
	left: initial;
	right: 0;
}

.participants-1 .videoView ::v-deep .avatar-container,
.participants-2 .videoView ::v-deep .avatar-container {
	left: initial;
	right: 0;
}

/* ellipsize name in 1on1 calls */
.participants-2 ::v-deep .videoContainer.promoted + .videoContainer-dummy .nameIndicator {
	padding: 12px 35%;
}

#videos .videoContainer.speaking:not(.videoView) ::v-deep .nameIndicator,
#videos .videoContainer.videoView.speaking ::v-deep .nameIndicator .microphone-icon {
	animation: pulse 1s;
	animation-iteration-count: infinite;
}

@keyframes pulse {
	0% {
		opacity: 1;
	}
	50% {
		opacity: .3;
	}
	100% {
		opacity: 1;
	}
}

.local-media-controls {
	position: absolute;
	width: 300px; /* same as .video-container-stripe */
	text-align: center;
	right: 0;
	bottom: 4px;
	z-index: 10;
	white-space: nowrap;

	&--sidebar {
		width: 100%;
	}
}

</style>
