<!--
  - @copyright Copyright (c) 2019 Julius Härtl <jus@bitgrid.net>
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
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program. If not, see <http://www.gnu.org/licenses/>.
  -
  -->

<template>
	<Modal @close="close">
		<div id="modal-inner" class="talk-modal" :class="{ 'icon-loading': loading }">
			<div id="modal-content">
				<h2>
					{{ dialogTitle }}
				</h2>
				<p v-if="dialogSubtitle" class="subtitle">
					{{ dialogSubtitle }}
				</p>
				<div id="room-list">
					<ul v-if="!loading && availableRooms.length > 0">
						<li v-for="room in availableRooms"
							:key="room.token"
							:class="{selected: selectedRoom === room.token }"
							@click="selectedRoom=room.token">
							<ConversationIcon
								:item="room"
								:hide-call="true"
								:hide-favorite="false"
								:disable-menu="true" />
							<span>{{ room.displayName }}</span>
						</li>
					</ul>
					<div v-else-if="!loading">
						{{ t('spreed', 'No conversations found') }}
					</div>
				</div>
				<div id="modal-buttons">
					<button
						v-if="!loading && availableRooms.length > 0"
						class="primary"
						:disabled="!selectedRoom"
						@click="select">
						{{ t('spreed', 'Select conversation') }}
					</button>
				</div>
			</div>
		</div>
	</Modal>
</template>

<script>
import Modal from '@nextcloud/vue/dist/Components/Modal'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { CONVERSATION } from '../constants'
import ConversationIcon from '../components/ConversationIcon'

export default {
	name: 'RoomSelector',
	components: {
		ConversationIcon,
		Modal,
	},
	props: {
		dialogTitle: {
			type: String,
			default: t('spreed', 'Link to a conversation'),
		},

		dialogSubtitle: {
			type: String,
			default: '',
		},
		/**
		 * Whether to only show conversations to which
		 * the user can post messages.
		 */
		showPostableOnly: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			rooms: [],
			selectedRoom: null,
			currentRoom: null,
			loading: true,
		}
	},
	computed: {
		availableRooms() {
			return this.rooms.filter((room) => {
				return room.type !== CONVERSATION.TYPE.CHANGELOG
					&& (!this.currentRoom || this.currentRoom !== room.token)
					&& (!this.showPostableOnly || room.readOnly === CONVERSATION.STATE.READ_WRITE)
					&& room.objectType !== 'file'
					&& room.objectType !== 'share:password'
			})
		},
	},
	beforeMount() {
		this.fetchRooms()

		const $store = OCA.Talk?.instance?.$store
		if ($store) {
			this.currentRoom = $store.getters.getToken()
		}
	},
	methods: {
		fetchRooms() {
			axios.get(generateOcsUrl('/apps/spreed/api/v4/room')).then((response) => {
				this.rooms = response.data.ocs.data.sort(this.sortConversations)
				this.loading = false
			})
		},
		sortConversations(conversation1, conversation2) {
			if (conversation1.isFavorite !== conversation2.isFavorite) {
				return conversation1.isFavorite ? -1 : 1
			}

			return conversation2.lastActivity - conversation1.lastActivity
		},
		close() {
			// FIXME: should not emit on $root but on itself
			this.$root.$emit('close')
			this.$emit('close')
		},
		select() {
			this.$root.$emit('select', this.selectedRoom)
			this.$emit('select', this.selectedRoom)
		},
	},
}
</script>

<style lang="scss" scoped>
#modal-inner {
	width: 90vw;
	max-width: 400px;
	height: 50vh;
	position: relative;

	h2 {
		margin-bottom: 4px;
	}
}

#modal-content {
	position: absolute;
	width: calc(100% - 40px);
	height: calc(100% - 40px);
	display: flex;
	flex-direction: column;
	padding: 20px;
}

#room-list {
	overflow-y: auto;
	flex: 0 1 auto;
	height: 100%;
}

li {
	padding: 6px;
	border: 1px solid transparent;
	display: flex;

	&:hover,
	&:focus {
		background-color: var(--color-background-dark);
		border-radius: var(--border-radius-pill);
	}

	&.selected {
		background-color: var(--color-primary-light);
		border-radius: var(--border-radius-pill);
	}

	& > span {
		padding: 5px 5px 5px 10px;
		vertical-align: middle;
		text-overflow: ellipsis;
		white-space: nowrap;
		overflow: hidden;
	}
}

#modal-buttons {
	overflow: hidden;
	flex-shrink: 0;
	button {
		height: 44px;
		margin: 0;
	}

	.primary {
		float: right;
	}
}

.subtitle {
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
}

</style>
