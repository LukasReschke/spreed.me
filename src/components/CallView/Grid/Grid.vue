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
	<div class="grid-main-wrapper" :class="{'is-grid': !isStripe, 'transparent': isLessThanTwoVideos}">
		<button v-if="isStripe"
			class="stripe--collapse"
			:aria-label="stripeButtonTooltip"
			@click="handleClickStripeCollapse">
			<ChevronDown
				v-if="stripeOpen"
				fill-color="#ffffff"
				decorative
				title=""
				:size="24" />
			<ChevronUp
				v-else
				fill-color="#ffffff"
				decorative
				title=""
				:size="24" />
		</button>
		<transition :name="isStripe ? 'slide-down' : ''">
			<div v-if="!isStripe || stripeOpen" class="wrapper" :style="wrapperStyle">
				<div :class="{'stripe-wrapper': isStripe, 'wrapper': !isStripe}">
					<button v-if="hasPreviousPage && gridWidth > 0 && showVideoOverlay"
						class="grid-navigation grid-navigation__previous"
						:aria-label="t('spreed', 'Previous page of videos')"
						@click="handleClickPrevious">
						<ChevronLeft
							decorative
							title=""
							:size="24" />
					</button>
					<div
						ref="grid"
						class="grid"
						:style="gridStyle"
						@mousemove="handleMovement"
						@keydown="handleMovement">
						<template v-if="!devMode && (!isLessThanTwoVideos || !isStripe)">
							<EmptyCallView v-if="videos.length === 0 && !isStripe" class="video" :is-grid="true" />
							<template v-for="callParticipantModel in displayedVideos">
								<Video
									:key="callParticipantModel.attributes.peerId"
									:class="{'video': !isStripe}"
									:show-video-overlay="showVideoOverlay"
									:token="token"
									:model="callParticipantModel"
									:is-grid="true"
									:show-talking-highlight="!isStripe"
									:is-stripe="isStripe"
									:is-promoted="sharedDatas[callParticipantModel.attributes.peerId].promoted"
									:is-selected="isSelected(callParticipantModel)"
									:fit-video="false"
									:video-container-aspect-ratio="videoContainerAspectRatio"
									:shared-data="sharedDatas[callParticipantModel.attributes.peerId]"
									@click-video="handleClickVideo($event, callParticipantModel.attributes.peerId)" />
							</template>
						</template>
						<!-- Grid developer mode -->
						<template v-if="devMode">
							<div
								v-for="(video, key) in displayedVideos"
								:key="video"
								class="dev-mode-video video"
								:class="{'dev-mode-screenshot': screenshotMode}">
								<img :src="placeholderImage(key)">
								<VideoBottomBar
									:has-shadow="false"
									:model="placeholderModel(key)"
									:shared-data="placeholderSharedData(key)"
									:token="token"
									:participant-name="placeholderName(key)" />
							</div>
							<h1 v-if="!screenshotMode" class="dev-mode__title">
								Dev mode on ;-)
							</h1>
							<div v-else
								class="dev-mode-video--self video"
								:style="{'background': 'url(' + placeholderImage(8) + ')'}">
								<LocalMediaControls
									:has-shadow="false"
									:model="placeholderModel(8)"
									:local-call-participant-model="placeholderModel(8)"
									:shared-data="placeholderSharedData(8)"
									:token="token"
									:participant-name="placeholderName(8)" />
							</div>
						</template>
						<LocalVideo
							v-if="!isStripe && !screenshotMode"
							ref="localVideo"
							class="video"
							:is-grid="true"
							:fit-video="isStripe"
							:token="token"
							:local-media-model="localMediaModel"
							:video-container-aspect-ratio="videoContainerAspectRatio"
							:local-call-participant-model="localCallParticipantModel"
							@switchScreenToId="1"
							@click-video="handleClickLocalVideo" />
					</div>
					<button v-if="hasNextPage && gridWidth > 0 && showVideoOverlay"
						class="grid-navigation grid-navigation__next"
						:aria-label="t('spreed', 'Next page of videos')"
						@click="handleClickNext">
						<ChevronRight
							decorative
							title=""
							:size="24" />
					</button>
				</div>
				<LocalVideo
					v-if="isStripe && !screenshotMode"
					ref="localVideo"
					class="video"
					:fit-video="true"
					:is-stripe="true"
					:show-controls="false"
					:token="token"
					:local-media-model="localMediaModel"
					:video-container-aspect-ratio="videoContainerAspectRatio"
					:local-call-participant-model="localCallParticipantModel"
					@switchScreenToId="1"
					@click-video="handleClickLocalVideo" />
				<!-- page indicator (disabled) -->
				<div
					v-if="numberOfPages !== 0 && hasPagination && false"
					class="pages-indicator">
					<div v-for="(page, index) in numberOfPages"
						:key="index"
						class="pages-indicator__dot"
						:class="{'pages-indicator__dot--active': index === currentPage }" />
				</div>
				<div v-if="devMode && !screenshotMode" class="dev-mode__data">
					<p>GRID INFO</p>
					<p>Videos (total): {{ videosCount }}</p>
					<p>Displayed videos n: {{ displayedVideos.length }}</p>
					<p>Max per page: ~{{ videosCap }}</p>
					<p>Grid width: {{ gridWidth }}</p>
					<p>Grid height: {{ gridHeight }}</p>
					<p>Min video width: {{ minWidth }} </p>
					<p>Min video Height: {{ minHeight }} </p>
					<p>Grid aspect ratio: {{ gridAspectRatio }}</p>
					<p>Number of pages: {{ numberOfPages }}</p>
					<p>Current page: {{ currentPage }}</p>
				</div>
			</div>
		</transition>
	</div>
</template>

<script>
import debounce from 'debounce'
import Video from '../shared/Video'
import LocalVideo from '../shared/LocalVideo'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { generateFilePath } from '@nextcloud/router'
import EmptyCallView from '../shared/EmptyCallView'
import VideoBottomBar from '../shared/VideoBottomBar'
import Tooltip from '@nextcloud/vue/dist/Directives/Tooltip'
import ChevronRight from 'vue-material-design-icons/ChevronRight'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft'
import ChevronUp from 'vue-material-design-icons/ChevronUp'
import ChevronDown from 'vue-material-design-icons/ChevronDown'
import LocalMediaControls from '../shared/LocalMediaControls'

export default {
	name: 'Grid',

	components: {
		LocalMediaControls,
		Video,
		LocalVideo,
		EmptyCallView,
		VideoBottomBar,
		ChevronRight,
		ChevronLeft,
		ChevronUp,
		ChevronDown,
	},

	directives: {
		Tooltip,
	},

	props: {
		/**
		 * Minimum width of the video components
		 */
		minWidth: {
			type: Number,
			default: 200,
		},
		/**
		 * Minimum height of the video components
		 */
		minHeight: {
			type: Number,
			default: 150,
		},
		/**
		 * Max number of videos per page. `0`, the default value, means no cap
		 */
		videosCap: {
			type: Number,
			default: 0,
		},
		targetAspectRatio: {
			type: Number,
			default: 1,
		},
		/**
		 * Developer mode: If enabled it allows to debug the grid using dummy
		 * videos
		 */
		devMode: {
			type: Boolean,
			default: false,
		},
		screenshotMode: {
			type: Boolean,
			default: false,
		},
		/**
		 * The number of dummy videos in dev mode
		 */
		dummies: {
			type: Number,
			default: 8,
		},
		/**
		 * Display the overflow of videos in separate pages;
		 */
		hasPagination: {
			type: Boolean,
			default: false,
		},
		/**
		 * To be set to true when the grid is in the promoted view.
		 */
		isStripe: {
			type: Boolean,
			default: false,
		},
		isSidebar: {
			type: Boolean,
			default: false,
		},
		callParticipantModels: {
			type: Array,
			required: true,
		},
		localMediaModel: {
			type: Object,
			required: true,
		},
		localCallParticipantModel: {
			type: Object,
			required: true,
		},
		token: {
			type: String,
			required: true,
		},
		sharedDatas: {
			type: Object,
			required: true,
		},
		isLocalVideoSelectable: {
			type: Boolean,
			default: false,
		},
		screens: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			gridWidth: 0,
			gridHeight: 0,
			// Columns of the grid at any given moment
			columns: 0,
			// Rows of the grid at any given moment
			rows: 0,
			// The current page
			currentPage: 0,
			// Videos controls and name
			showVideoOverlay: true,
			// Timer for the videos bottom bar
			showVideoOverlayTimer: null,
		}
	},

	computed: {
		stripeButtonTooltip() {
			if (this.stripeOpen) {
				return t('spreed', 'Collapse stripe')
			} else {
				return t('spreed', 'Expand stripe')
			}
		},

		// The videos array. This is the total number of grid elements.
		// Depending on `gridWidthm`, `gridHeight`, `minWidth`, `minHeight` and
		// `videosCap`, these videos are shown in one or more grid 'pages'.
		videos() {
			if (this.devMode) {
				return Array.from(Array(this.dummies).keys())
			} else {
				return this.callParticipantModels
			}
		},

		// Number of video components (it does not include the local video)
		videosCount() {
			if (!this.isStripe && this.videos.length === 0) {
				// Count the emptycontent as a grid element
				return 1
			}

			return this.videos.length
		},
		videoWidth() {
			return this.gridWidth / this.columns
		},
		videoHeight() {
			return this.gridHeight / this.rows
		},

		// Array of videos that are being displayed in the grid at any given
		// moment
		displayedVideos() {
			if (!this.slots) {
				return []
			}

			// Slice the `videos` array to display the current page of videos
			if (((this.currentPage + 1) * this.slots) >= this.videos.length) {
				return this.videos.slice(this.currentPage * this.slots)
			}

			return this.videos.slice(this.currentPage * this.slots, (this.currentPage + 1) * this.slots)
		},

		isLessThanTwoVideos() {
			// without screen share, we don't want to duplicate videos if we were to show them in the stripe
			// however, if a screen share is in progress, it means the video of the presenting user is not visible
			// so we can show it in the stripe
			return this.videos.length <= 1 && !this.screens.length
		},

		// The aspect ratio of the grid (in terms of px)
		gridAspectRatio() {
			return (this.gridWidth / this.gridHeight).toPrecision([2])
		},

		// Max number of columns possible
		columnsMax() {
			if (Math.floor(this.gridWidth / this.minWidth) < 1) {
				// Return at least 1 column
				return 1
			} else {
				return Math.floor(this.gridWidth / this.minWidth)
			}
		},

		// Max number of rows possible
		rowsMax() {
			if (Math.floor(this.gridHeight / this.minHeight) < 1) {
				// Return at least 1 row
				return 1
			} else {
				return Math.floor(this.gridHeight / this.minHeight)
			}
		},

		// Number of grid slots at any given moment
		// The local video always takes one slot if the grid view is not shown
		// as a stripe.
		slots() {
			return this.isStripe ? this.rows * this.columns : this.rows * this.columns - 1
		},

		// Grid pages at any given moment
		numberOfPages() {
			return Math.ceil(this.videosCount / this.slots)
		},

		// Hides or displays the `grid-navigation next` button
		hasNextPage() {
			if (this.displayedVideos.length !== 0 && this.hasPagination) {
				return this.displayedVideos[this.displayedVideos.length - 1] !== this.videos[this.videos.length - 1]
			} else {
				return false
			}
		},

		// Hides or displays the `grid-navigation previous` button
		hasPreviousPage() {
			if (this.displayedVideos.length !== 0 && this.hasPagination) {
				return this.displayedVideos[0] !== this.videos[0]
			} else {
				return false
			}
		},

		// TODO: rebuild the grid to have optimal for last page
		// isLastPage() {
		// return !this.hasNextPage
		// },

		// Computed css to reactively style the grid
		gridStyle() {
			let columns = this.columns
			let rows = this.rows

			// If there are no other videos the empty call view is shown above
			// the local video.
			if (this.videos.length === 0 && !this.isStripe) {
				columns = 1
				rows = 2
			}

			return {
				gridTemplateColumns: `repeat(${columns}, minmax(${this.minWidth}px, 1fr))`,
				gridTemplateRows: `repeat(${rows}, minmax(${this.minHeight}px, 1fr))` }
		},

		// Check if there's an overflow of videos (videos that don't fit in the grid)
		hasVideoOverflow() {
			return this.videosCount > this.slots
		},

		sidebarStatus() {
			return this.$store.getters.getSidebarStatus
		},
		// Current aspect ratio of each video component
		videoContainerAspectRatio() {
			return (this.gridWidth / this.columns) / (this.gridHeight / this.rows)
		},
		wrapperStyle() {
			if (this.isStripe) {
				return 'height: 250px'
			} else {
				return 'height: 100%'
			}
		},

		stripeOpen() {
			return this.$store.getters.isStripeOpen
		},
	},

	watch: {
		// If the video array size changes, rebuild the grid
		'videos.length': function() {
			this.makeGrid()
		},
		// TODO: rebuild the grid to have optimal for last page
		// Exception for when navigating in and away from the last page of the
		// grid
		/**
		isLastPage(newValue, oldValue) {
			 if (this.hasPagination) {
				 // If navigating into last page, make grid for last page
				if (newValue && this.currentPage !== 0) {
					this.makeGridForLastPage()
				} else if (!newValue) {
				// TODO: make a proper grid for when navigating away from last page
					this.makeGrid()
				}
			 }
		 },
		 */
		isStripe() {
			this.rebuildGrid()

			// Reset current page when switching between stripe and full grid,
			// as the previous page is meaningless in the new mode.
			this.currentPage = 0
		},

		stripeOpen() {
			this.rebuildGrid()
		},

		sidebarStatus() {
			// Handle the resize after the sidebar animation has completed
			setTimeout(this.handleResize, 500)
		},

		numberOfPages() {
			if (this.currentPage >= this.numberOfPages) {
				this.currentPage = Math.max(0, this.numberOfPages - 1)
			}
		},
	},

	// bind event handlers to the `handleResize` method
	mounted() {
		window.addEventListener('resize', this.handleResize)
		subscribe('navigation-toggled', this.handleResize)
		this.makeGrid()
	},
	beforeDestroy() {
		window.removeEventListener('resize', this.handleResize)
		unsubscribe('navigation-toggled', this.handleResize)
	},

	methods: {
		rebuildGrid() {
			console.debug('isStripe: ', this.isStripe)
			console.debug('stripeOpen: ', this.stripeOpen)
			console.debug('previousGridWidth: ', this.gridWidth, 'previousGridHeight: ', this.gridHeight)
			console.debug('newGridWidth: ', this.gridWidth, 'newGridHeight: ', this.gridHeight)
			if (!this.isStripe || this.stripeOpen) {
				this.$nextTick(this.makeGrid)
			}
		},

		placeholderImage(i) {
			return generateFilePath('spreed', 'docs', 'screenshotplaceholders/placeholder-' + i + '.jpeg')
		},

		placeholderName(i) {
			switch (i) {
			case 0:
				return 'Sandra McKinney'
			case 1:
				return 'Chris Wurst'
			case 2:
				return 'Edeltraut Bobb'
			case 3:
				return 'Arthur Blitz'
			case 4:
				return 'Roeland Douma'
			case 5:
				return 'Vanessa Steg'
			case 6:
				return 'Emily Grant'
			case 7:
				return 'Tobias Kaminsky'
			case 8:
				return 'Adrian Ada'
			}
		},

		placeholderModel(i) {
			return {
				attributes: {
					audioAvailable: i === 1 || i === 2 || i === 4 || i === 5 || i === 6 || i === 7 || i === 8,
					audioEnabled: i === 8,
					videoAvailable: true,
					screen: false,
					currentVolume: 0.75,
					volumeThreshold: 0.75,
					localScreen: false,
					raisedHand: {
						state: i === 0 || i === 1 || i === 6,
					},
				},
				forceMute: () => {},
				on: () => {},
				off: () => {},
				getWebRtc: () => {
					return {
						connection: {
							getSendVideoIfAvailable: () => {},
						},
					}
				},
			}
		},

		placeholderSharedData() {
			return {
				videoEnabled: true,
				screenVisible: false,
			}
		},

		// whenever the document is resized, re-set the 'clientWidth' variable
		handleResize(event) {
			// TODO: properly handle resizes when not on first page:
			// currently if the user is not on the 'first page', upon resize the
			// current position in the videos array is lost (first element
			// in the grid goes back to be first video)
			debounce(this.makeGrid(), 200)
		},

		// Find the right size if the grid in rows and columns (we already know
		// the size in px).
		makeGrid() {
			if (!this.$refs.grid) {
				return
			}
			this.gridWidth = this.$refs.grid.clientWidth
			this.gridHeight = this.$refs.grid.clientHeight
			// prevent making grid if no videos
			if (this.videos.length === 0) {
				this.columns = 0
				this.rows = 0
				return
			}

			if (this.devMode) {
				console.debug('Recreating grid: videos: ', this.videos.length, 'columns: ', this.columnsMax + ', rows: ' + this.rowsMax)
			}

			// We start by assigning the max possible value to our rows and columns
			// variables. These variables are kept in the data and represent how the
			// grid looks at any given moment. We do this based on `gridWidth`,
			// `gridHeight`, `minWidth` and `minHeight`. If the video is used in the
			// context of the promoted view, we se 1 row directly and we remove 1 column
			// (one of the participants will be in the promoted video slot)
			this.columns = this.columnsMax
			this.rows = this.rowsMax
			// This values would already work if the grid is entirely populated with
			// video elements. However, if we'd have only a couple of videos to display
			// and a very big screen, we'd now have a lot of columns and rows, and our
			// video components would occupy only the first 2 slots and be too small.
			// To solve this, we shrink this 'max grid' we've just created to fit the
			// number of videos that we have.
			if (this.videosCap !== 0) {
				this.shrinkGrid(this.videosCap)
			} else {
				this.shrinkGrid(this.videosCount)
			}
		},

		// Fine tune the number of rows and columns of the grid
		async shrinkGrid(numberOfVideos) {
			if (this.devMode) {
				console.debug('Shrinking grid: columns', this.columns + ', rows: ' + this.rows)
			}

			// No need to shrink more if 1 row and 1 column
			if (this.rows === 1 && this.columns === 1) {
				return
			}

			let currentColumns = this.columns
			let currentRows = this.rows
			let currentSlots = this.isStripe ? currentColumns * currentRows : currentColumns * currentRows - 1

			// Run this code only if we don't have an 'overflow' of videos. If the
			// videos are populating the grid, there's no point in shrinking it.
			while (numberOfVideos < currentSlots) {
				const previousColumns = currentColumns
				const previousRows = currentRows

				// Current video dimensions
				const videoWidth = this.gridWidth / currentColumns
				const videoHeight = this.gridHeight / currentRows

				// Hypothetical width/height with one column/row less than current
				const videoWidthWithOneColumnLess = this.gridWidth / (currentColumns - 1)
				const videoHeightWithOneRowLess = this.gridHeight / (currentRows - 1)

				// Hypothetical aspect ratio with one column/row less than current
				const aspectRatioWithOneColumnLess = videoWidthWithOneColumnLess / videoHeight
				const aspectRatioWithOneRowLess = videoWidth / videoHeightWithOneRowLess

				// Deltas with target aspect ratio
				const deltaAspectRatioWithOneColumnLess = Math.abs(aspectRatioWithOneColumnLess - this.targetAspectRatio)
				const deltaAspectRatioWithOneRowLess = Math.abs(aspectRatioWithOneRowLess - this.targetAspectRatio)

				if (this.devMode) {
					console.debug('deltaAspectRatioWithOneColumnLess: ', deltaAspectRatioWithOneColumnLess, 'deltaAspectRatioWithOneRowLess: ', deltaAspectRatioWithOneRowLess)
				}
				// Compare the deltas to find out whether we need to remove a column or a row
				if (deltaAspectRatioWithOneColumnLess <= deltaAspectRatioWithOneRowLess) {
					if (currentColumns >= 2) {
						currentColumns--
					}

					currentSlots = this.isStripe ? currentColumns * currentRows : currentColumns * currentRows - 1

					// Check that there are still enough slots available
					if (numberOfVideos > currentSlots) {
						// If not, revert the changes and break the loop
						currentColumns++
						break
					}
				} else {
					if (currentRows >= 2) {
						currentRows--
					}

					currentSlots = this.isStripe ? currentColumns * currentRows : currentColumns * currentRows - 1

					// Check that there are still enough slots available
					if (numberOfVideos > currentSlots) {
						// If not, revert the changes and break the loop
						currentRows++
						break
					}
				}

				if (previousColumns === currentColumns && previousRows === currentRows) {
					break
				}
			}

			this.columns = currentColumns
			this.rows = currentRows
		},

		// The last grid page is very likely not to have the same number of
		// elements as the previous pages so the grid needs to be tweaked
		// accordingly
		// makeGridForLastPage() {
		// this.columns = this.columnsMax
		// this.rows = this.rowsMax
		// // The displayed videos for the last page have already been set
		// // in `handleClickNext`
		// this.shrinkGrid(this.displayedVideos.length)
		// },

		handleClickNext() {
			this.currentPage++
			console.debug('handleclicknext, ', 'currentPage ', this.currentPage, 'slots ', this.slot, 'videos.length ', this.videos.length)
		},
		handleClickPrevious() {
			this.currentPage--
			console.debug('handleclickprevious, ', 'currentPage ', this.currentPage, 'slots ', this.slots, 'videos.length ', this.videos.length)
		},

		handleClickStripeCollapse() {
			this.$store.dispatch('setCallViewMode', { isStripeOpen: !this.stripeOpen })
		},

		handleMovement() {
			// TODO: debounce this
			this.setTimerForUiControls()
		},
		setTimerForUiControls() {
			if (this.showVideoOverlayTimer !== null) {
				clearTimeout(this.showVideoOverlayTimer)
			}
			this.showVideoOverlay = true
			this.showVideoOverlayTimer = setTimeout(() => { this.showVideoOverlay = false }, 5000)
		},

		handleClickVideo(event, peerId) {
			console.debug('selected-video peer id', peerId)
			this.$emit('select-video', peerId)
		},

		handleClickLocalVideo() {
			this.$emit('click-local-video')
		},

		isSelected(callParticipantModel) {
			return callParticipantModel.attributes.peerId === this.$store.getters.selectedVideoPeerId
		},

	},
}

</script>

<style lang="scss" scoped>
@import '../../../assets/variables.scss';

.grid-main-wrapper {
	position: relative;
	width: 100%;
}

.grid-main-wrapper.transparent {
	background: transparent;
}

.grid-main-wrapper.is-grid {
	height: 100%;
}

.wrapper {
	width: 100%;
	display: flex;
	position: relative;
	bottom: 0;
	left: 0;
	flex: 1 0 auto;
}

.grid {
	display: grid;
	height: 100%;
	width: 100%;
}

.empty-call-view {
	position: relative;
	padding: 16px;
}

.local-video-stripe {
	width: 300px;
}

.stripe-wrapper {
	width: calc(100% - 300px);
	position: relative;
}

.dev-mode-video {
	&:not(.dev-mode-screenshot) {
		border: 1px solid #00FF41;
		color: #00FF41;
	}
	font-size: 30px;
	text-align: center;
	vertical-align: middle;
	position: relative;

	&--self {
		background-size: cover !important;

		& > div {
			width: 100%;
			bottom: calc(-100% + 50px);
			position: relative;
			left: calc(50% - 88px);
		}
	}

	img {
		object-fit: cover;
		height: 100%;
		width: 100%
	}
}

.dev-mode__title {
	position: absolute;
	left: 44px;
	color: #00FF41;
	z-index: 100;
	line-height: 120px;
	font-weight: 900;
	font-size: 100px !important;
	top: 88px;
	opacity: 25%;
}

.dev-mode__data {
	font-family: monospace;
	position: fixed;
	color: #00FF41;
	left: 20px;
	bottom: 50%;
	padding: 20px;
	background: rgba(0,0,0,0.8);
	border: 1px solid #00FF41;
	width: 212px;
	font-size: 12px;
	z-index: 999999999999999;
	& p {
		text-overflow: ellipsis;
		overflow: hidden;
		white-space: nowrap;
	}
}

.video:last-child {
	grid-column-end: -1;
}

.grid-navigation {
	position: absolute;
	width: 44px;
	height: 44px;
	background-color: white;
	opacity: 0.6 !important;
	/* Center icons vertically in the grid view */
	top: calc(50% - 22px);
	z-index: 2;
	box-shadow: 0 0 4px var(--color-box-shadow);
	padding: 0;
	margin: 0;

	&:hover,
	&:focus {
		background-color: var(var(--color-primary-element-light));
		border: 1px solid white;
		opacity: 1 !important;
	}
	&__previous {
		left: 12px;
	}
	&__next {
		right: 12px;
	}
}

.stripe-wrapper .grid-navigation {
	top: 12px;
}

.pages-indicator {
	position: absolute;
	right: 50%;
	top: 4px;
	display: flex;
	background-color: var(--color-background-hover);
	height: 44px;
	padding: 0 22px;
	border-radius: 22px;
	&__dot {
		width: 8px;
		height: 8px;
		margin: auto 4px;
		border-radius: 4px;
		background-color: white;
		opacity: 80%;
		box-shadow: 0 0 4px black;

		&--active {
			opacity: 100%;
		}
	}
}

/** FIXME: replace with nextcloud-vue button */
button.stripe--collapse {
	position: absolute;
	top: -50px;
	right: 0;
	width: 44px;
	height: 44px;
	z-index: 10;
	border: 0;
	background: none;
	opacity: .7;
	padding: 0;

	.app-content:hover & {
		background-color: rgba(0, 0, 0, 0.1) !important;

		&:hover,
		&:focus {
			opacity: 1;
			background-color: rgba(0, 0, 0, 0.2) !important;
		}
	}

	&:active {
		/* needed again to override default active button style */
		background: none;
	}
}

</style>
