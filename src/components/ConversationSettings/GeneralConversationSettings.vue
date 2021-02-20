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
	<div class="general-settings">
		<ConversationPictureEditor
			v-if="isSettingConversationPicture"
			:token="token"
			@close="isSettingConversationPicture = false" />
		<button class="general-settings__picture icon-user"
			@click="setConversationPicture">
			<img v-if="hasImage" src="" alt="">
		</button>
		<div class="general-settings__details">
			<h4
				class="details__name">
				{{ conversationName }}
			</h4>
		<!-- TODO: add the conversation description editor here -->
		</div>
	</div>
</template>

<script>
import ConversationPictureEditor from '../ConversationPictureEditor/ConversationPictureEditor'
export default {
	name: 'GeneralConversationSettings',

	components: {
		ConversationPictureEditor,
	},

	props: {
		token: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			isSettingConversationPicture: false,
		}
	},

	computed: {
		conversation() {
			return this.$store.getters.conversation(this.token)
		},

		conversationName() {
			return this.conversation.displayName
		},

		hasImage() {
			return this.conversation.displayName
		},
	},

	methods: {
		setConversationPicture() {
			this.isSettingConversationPicture = true
		},
	},

}
</script>

<style lang="scss" scoped>

.general-settings {
	display: flex;

	&__picture {
		height: 80px;
		width: 80px;
		flex: 0 0 auto;
		border-radius: 120px;
	}

	&__details {
		margin-left: 16px;
		display: flex;
		flex-direction: column;
		justify-content: center;
	}
}

.details {

	&__name {
		font-size: 18px;
		font-weight: bold;
	}
}
</style>
