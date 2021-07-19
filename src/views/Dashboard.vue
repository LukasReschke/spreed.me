<!--
  - @copyright Copyright (c) 2020 Julius Härtl <jus@bitgrid.net>
  -
  - @author Julius Härtl <jus@bitgrid.net>
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
  -
  -->

<template>
	<DashboardWidget id="talk-panel"
		:items="roomOptions"
		:show-more-url="''"
		:loading="loading"
		:show-items-and-empty-content="!hasImportantConversations"
		:half-empty-content-message="t('spreed', 'No unread mentions')">
		<template #default="{ item }">
			<DashboardWidgetItem
				:target-url="getItemTargetUrl(item)"
				:main-text="getMainText(item)"
				:sub-text="getSubText(item)"
				:item="item"
				:item-menu="itemMenu"
				@markAsRead="onMarkAsRead(item)"
				v-on="handlers">
				<template #avatar>
					<ConversationIcon
						:item="item"
						:hide-favorite="true"
						:hide-call="false"
						:disable-menu="true" />
				</template>
			</DashboardWidgetItem>
		</template>
		<template #empty-content>
			<EmptyContent icon="icon-talk">
				<template #desc>
					{{ t('spreed', 'Say hi to your friends and colleagues!') }}
					<button
						@click="clickStartNew">
						{{ t('spreed', 'Start a conversation') }}
					</button>
				</template>
			</EmptyContent>
		</template>
	</DashboardWidget>
</template>

<script>
import { DashboardWidget, DashboardWidgetItem } from '@nextcloud/vue-dashboard'
import ConversationIcon from './../components/ConversationIcon'
import EmptyContent from '@nextcloud/vue/dist/Components/EmptyContent'
import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { CONVERSATION } from '../constants'

const ROOM_POLLING_INTERVAL = 30

const propertySort = (properties) => (a, b) => properties.map(obj => {
	let dir = 1
	if (obj[0] === '-') {
		dir = -1
		obj = obj.substring(1)
	}
	return a[obj] > b[obj] ? dir : a[obj] < b[obj] ? -(dir) : 0
}).reduce((p, n) => p || n, 0)

export default {
	name: 'Dashboard',
	components: { DashboardWidget, DashboardWidgetItem, ConversationIcon, EmptyContent },

	data() {
		return {
			roomOptions: [],
			hasImportantConversations: false,
			loading: true,
			windowVisibility: true,
			itemMenu: {
				markAsRead: {
					text: t('app', 'Mark as read'),
					icon: 'icon-checkmark',
				},
			},
		}
	},

	computed: {
		callLink() {
			return (conversation) => {
				return generateUrl('call/' + conversation.token)
			}
		},

		/**
		 * This is a simplified version of the last chat message.
		 * Parameters are parsed without markup (just replaced with the name),
		 * e.g. no avatars on mentions.
		 * @returns {string} A simple message to show below the conversation name
		 */
		simpleLastChatMessage() {
			return (lastChatMessage) => {
				if (!Object.keys(lastChatMessage).length) {
					return ''
				}

				const params = lastChatMessage.messageParameters
				let subtitle = lastChatMessage.message.trim()

				// We don't really use rich objects in the subtitle, instead we fall back to the name of the item
				Object.keys(params).forEach((parameterKey) => {
					subtitle = subtitle.replace('{' + parameterKey + '}', params[parameterKey].name)
				})

				return subtitle
			}
		},

		getItemTargetUrl() {
			return (conversation) => {
				return generateUrl(`call/${conversation.token}`)
			}
		},

		getMainText() {
			return (conversation) => {
				return conversation.displayName
			}
		},

		getSubText() {
			return (conversation) => {
				if (conversation.hasCall) {
					return t('spreed', 'Call in progress')
				}

				if (conversation.unreadMention) {
					return t('spreed', 'You were mentioned')
				}

				return this.simpleLastChatMessage(conversation.lastMessage)
			}
		},
	},

	watch: {
		windowVisibility(newValue) {
			if (newValue) {
				this.fetchRooms()
			}
		},
	},

	beforeDestroy() {
		document.removeEventListener('visibilitychange', this.changeWindowVisibility)
	},

	beforeMount() {
		this.fetchRooms()
		setInterval(this.fetchRooms, ROOM_POLLING_INTERVAL * 1000)
		document.addEventListener('visibilitychange', this.changeWindowVisibility)
	},

	methods: {
		fetchRooms() {
			if (!this.windowVisibility) {
				// Dashboard is not visible, so don't update the room list
				return
			}

			axios.get(generateOcsUrl('apps/spreed/api/v4/room')).then((response) => {
				const rooms = response.data.ocs.data
				const importantRooms = rooms.filter((conversation) => {
					return conversation.hasCall
						|| conversation.unreadMention
						|| (conversation.unreadMessages > 0 && conversation.type === CONVERSATION.TYPE.ONE_TO_ONE)
				})

				if (importantRooms.length) {
					importantRooms.sort(propertySort(['-hasCall', '-unreadMention', '-lastActivity']))
					this.roomOptions = importantRooms.slice(0, 7)
					this.hasImportantConversations = true
				} else {
					this.roomOptions = rooms.sort(propertySort(['-isFavorite', '-lastActivity'])).slice(0, 5)
					this.hasImportantConversations = false
				}

				this.loading = false
			})
		},

		changeWindowVisibility() {
			this.windowVisibility = !document.hidden
		},

		clickStartNew() {
			window.location = generateUrl('/apps/spreed')
		},

		onMarkAsRead(item) {
			this.$store.dispatch('clearLastReadMessage', { token: item.token, updateVisually: true })
			this.fetchRooms()
		},
	},
}
</script>

<style lang="scss" scoped>
	::v-deep .item-list__entry {
		position: relative;
	}

	.empty-content {
		text-align: center;
		margin-top: 5vh;

		&.half-screen {
			margin-top: 0;
			margin-bottom: 2vh;
		}
	}
</style>
