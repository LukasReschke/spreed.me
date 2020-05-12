<!--
  - @copyright Copyright (c) 2019 Joas Schilling <coding@schilljs.com>
  -
  - @author Joas Schilling <coding@schilljs.com>
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
	<div class="conversation-icon">
		<div v-if="iconClass"
			class="avatar icon"
			:class="iconClass" />
		<Avatar v-else
			:size="44"
			:user="item.name"
			:display-name="item.displayName"
			class="conversation-icon__avatar" />
		<div v-if="showCall"
			class="overlap-icon">
			<span class="icon icon-active-call" />
			<span class="hidden-visually">{{ t('spreed', 'Call in progress') }}</span>
		</div>
		<div v-else-if="showFavorite"
			class="overlap-icon">
			<span class="icon icon-favorite" />
			<span class="hidden-visually">{{ t('spreed', 'Favorite') }}</span>
		</div>
	</div>
</template>

<script>
import Avatar from '@nextcloud/vue/dist/Components/Avatar'
import { CONVERSATION } from '../constants'

export default {
	name: 'ConversationIcon',
	components: {
		Avatar,
	},
	props: {
		/**
		 * Allow to hide the favorite icon, e.g. on mentions
		 */
		hideFavorite: {
			type: Boolean,
			default: true,
		},
		hideCall: {
			type: Boolean,
			default: true,
		},
		item: {
			type: Object,
			default: function() {
				return {
					objectType: '',
					type: 0,
					displayName: '',
					isFavorite: false,
				}
			},
		},
	},
	computed: {
		showCall() {
			return !this.hideCall && this.item.hasCall
		},
		showFavorite() {
			return !this.hideFavorite && this.item.isFavorite
		},
		iconClass() {
			if (this.item.objectType === 'file') {
				return 'icon-file'
			} else if (this.item.objectType === 'share:password') {
				return 'icon-password'
			} else if (this.item.objectType === 'emails') {
				return 'icon-mail'
			} else if (this.item.type === CONVERSATION.TYPE.CHANGELOG) {
				return 'icon-changelog'
			} else if (this.item.type === CONVERSATION.TYPE.GROUP) {
				return 'icon-contacts'
			} else if (this.item.type === CONVERSATION.TYPE.PUBLIC) {
				return 'icon-public'
			} else if (this.item.type === CONVERSATION.TYPE.NOTES) {
				return 'icon-notes'
			}

			return ''
		},
	},
}
</script>

<style lang="scss" scoped>
$icon-size: 44px;

.conversation-icon {
	width: $icon-size;
	height: $icon-size;

	.avatar.icon {
		width: $icon-size;
		height: $icon-size;
		line-height: $icon-size;
		font-size: $icon-size / 2;
		background-color: var(--color-background-darker);

		&.icon-changelog {
			background-size: $icon-size;
		}

		&.icon-notes {
			background-size: $icon-size;
		}

		&.icon-public,
		&.icon-contacts,
		&.icon-password,
		&.icon-file,
		&.icon-mail {
			background-size: $icon-size / 2;
		}
	}

	.overlap-icon {
		position: absolute;
		top: 6px;
		left: $icon-size - 6px;
		line-height: 100%;

		.icon-favorite {
			display: inline-block;
			vertical-align: middle;
			background-image: var(--icon-star-dark-FC0);
		}

		.icon-active-call {
			display: inline-block;
			vertical-align: middle;
			background-image: var(--icon-video-E9322D);
		}
	}
}

</style>
