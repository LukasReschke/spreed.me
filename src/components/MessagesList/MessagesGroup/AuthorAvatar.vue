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
	<Avatar v-if="isUser"
		class="messages__avatar__icon"
		:user="authorId"
		:display-name="displayName" />
	<div v-else-if="isDeletedUser"
		class="avatar-32px guest">
		X
	</div>
	<div v-else-if="isGuest"
		class="avatar-32px guest">
		{{ firstLetterOfGuestName }}
	</div>
	<div v-else-if="isChangelog"
		class="avatar-32px icon icon-changelog" />
	<div v-else-if="isNotes"
		class="avatar-32px icon icon-notes" />
	<div v-else
		class="avatar-32px bot">
		&gt;_
	</div>
</template>

<script>
import Avatar from '@nextcloud/vue/dist/Components/Avatar'

export default {
	name: 'AuthorAvatar',
	components: {
		Avatar,
	},
	props: {
		authorType: {
			type: String,
			required: true,
		},
		authorId: {
			type: String,
			required: true,
		},
		displayName: {
			type: String,
			required: true,
		},
	},

	computed: {
		isChangelog() {
			return this.authorType === 'bots' && this.authorId === 'changelog'
		},
		isNotes() {
			return this.authorType === 'bots' && this.authorId === 'notes'
		},
		isUser() {
			return this.authorType === 'users'
		},
		isDeletedUser() {
			return this.authorType === 'deleted_users'
		},
		isGuest() {
			return this.authorType === 'guests'
		},

		firstLetterOfGuestName() {
			const customName = this.displayName !== t('spreed', 'Guest') ? this.displayName : '?'
			return customName.charAt(0)
		},
	},
}
</script>

<style lang="scss" scoped>
@import '../../../assets/avatar.scss';

// size of avatars of chat message authors
$author-avatar-size: 32px;
@include avatar-mixin($author-avatar-size);

</style>
