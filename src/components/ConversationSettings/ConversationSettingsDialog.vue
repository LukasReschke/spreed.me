<!--
  - @copyright Copyright (c) 2020 Vincent Petry <vincent@nextcloud.com>
  -
  - @author Vincent Petry <vincent@nextcloud.com>
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
	<AppSettingsDialog
		role="dialog"
		:aria-label="t('spreed', 'Conversation settings')"
		:open.sync="showSettings"
		:show-navigation="false">
		<AppSettingsSection
			:title="t('spreed', 'Details')">
			<GeneralConversationSettings :token="token" />
		</AppSettingsSection>
		<AppSettingsSection
			:title="t('spreed', 'Guests access')"
			class="app-settings-section">
			<LinkShareSettings ref="linkShareSettings" />
		</AppSettingsSection>
		<AppSettingsSection
			v-if="canFullModerate"
			:title="t('spreed', 'Conversation settings')"
			class="app-settings-section">
			<ListableSettings :token="token" />
			<LockingSettings :token="token" />
		</AppSettingsSection>
		<AppSettingsSection
			v-if="canFullModerate"
			:title="t('spreed', 'Meeting settings')"
			class="app-settings-section">
			<LobbySettings :token="token" />
			<SipSettings v-if="canUserEnableSIP" />
		</AppSettingsSection>
		<AppSettingsSection
			v-if="canFullModerate && matterbridgeEnabled"
			:title="t('spreed', 'Matterbridge')"
			class="app-settings-section">
			<MatterbridgeSettings />
		</AppSettingsSection>
	</AppSettingsDialog>
</template>

<script>
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { PARTICIPANT } from '../../constants'
import AppSettingsDialog from '@nextcloud/vue/dist/Components/AppSettingsDialog'
import AppSettingsSection from '@nextcloud/vue/dist/Components/AppSettingsSection'
import LinkShareSettings from './LinkShareSettings'
import ListableSettings from './ListableSettings'
import LockingSettings from './LockingSettings'
import LobbySettings from './LobbySettings'
import SipSettings from './SipSettings'
import MatterbridgeSettings from './Matterbridge/MatterbridgeSettings'
import { loadState } from '@nextcloud/initial-state'
import GeneralConversationSettings from './GeneralConversationSettings'

export default {
	name: 'ConversationSettingsDialog',

	components: {
		AppSettingsDialog,
		AppSettingsSection,
		LinkShareSettings,
		LobbySettings,
		ListableSettings,
		LockingSettings,
		SipSettings,
		MatterbridgeSettings,
		GeneralConversationSettings,
	},

	data() {
		return {
			showSettings: false,
			matterbridgeEnabled: loadState('spreed', 'enable_matterbridge'),
		}
	},

	computed: {
		canUserEnableSIP() {
			return this.conversation.canEnableSIP
		},

		token() {
			return this.$store.getters.getToken()
		},
		conversation() {
			return this.$store.getters.conversation(this.token) || this.$store.getters.dummyConversation
		},
		participantType() {
			return this.conversation.participantType
		},
		canFullModerate() {
			return this.participantType === PARTICIPANT.TYPE.OWNER || this.participantType === PARTICIPANT.TYPE.MODERATOR
		},
	},

	mounted() {
		subscribe('show-conversation-settings', this.handleShowSettings)
	},

	methods: {
		handleShowSettings() {
			this.showSettings = true
			this.$nextTick(() => {
				this.$refs.linkShareSettings.focus()
			})
		},

		beforeDestroy() {
			unsubscribe('show-conversation-settings', this.handleShowSettings)
		},
	},
}
</script>
<style lang="scss" scoped>
::v-deep button.icon {
	height: 32px;
	width: 32px;
	display: inline-block;
	margin-left: 5px;
	vertical-align: middle;
}

::v-deep .app-settings__content {
	width: 450px;
}

::v-deep .app-settings-section__hint {
	color: var(--color-text-lighter);
	padding: 8px 0;
}

::v-deep .app-settings-subsection {
	margin-top: 25px;

	&:first-child {
		margin-top: 0;
	}
}
</style>
