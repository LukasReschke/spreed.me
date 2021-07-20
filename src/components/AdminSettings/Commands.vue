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
 - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 - GNU Affero General Public License for more details.
 -
 - You should have received a copy of the GNU Affero General Public License
 - along with this program. If not, see <http://www.gnu.org/licenses/>.
 -
 -->

<template>
	<div id="chat_commands" class="commands section">
		<h2>
			{{ t('spreed', 'Commands') }}
			<small>
				{{ t('spreed', 'Beta') }}
				<span class="icon icon-beta-feature" />
			</small>
		</h2>

		<p class="settings-hint" v-html="commandHint" />

		<div id="commands_list">
			<div class="head name">
				{{ t('spreed', 'Name') }}
			</div>
			<div class="head command">
				{{ t('spreed', 'Command') }}
			</div>
			<div class="head script">
				{{ t('spreed', 'Script') }}
			</div>
			<div class="head response">
				{{ t('spreed', 'Response to') }}
			</div>
			<div class="head enabled">
				{{ t('spreed', 'Enabled for') }}
			</div>
			<Command v-for="command in commands" :key="command.id" v-bind="command" />
		</div>
	</div>
</template>

<script>
import Command from '../../components/AdminSettings/Command'
import { loadState } from '@nextcloud/initial-state'

export default {
	name: 'Commands',

	components: {
		Command,
	},

	data() {
		return {
			commands: {},
		}
	},

	computed: {
		commandHint() {
			return t('spreed', 'Commands are a new beta feature in Nextcloud Talk. They allow you to run scripts on your Nextcloud server. You can define them with our command line interface. An example of a calculator script can be found in our {linkstart}documentation{linkend}.')
				.replace('{linkstart}', '<a  target="_blank" rel="noreferrer nofollow" class="external" href="https://nextcloud-talk.readthedocs.io/en/latest/commands/">')
				.replace('{linkend}', ' ↗</a>')
		},
	},

	mounted() {
		this.commands = loadState('spreed', 'commands')
	},
}
</script>

<style lang="scss" scoped>
.commands.section {
	#commands_list {
		display: grid;
		grid-template-columns: minmax(100px, 200px) minmax(100px, 200px)  1fr minmax(100px, 200px)  minmax(100px, 200px);
		grid-column-gap: 5px;
		grid-row-gap: 10px;
		.head {
			padding-bottom: 5px;
			border-bottom: 1px solid var(--color-border);
			font-weight: bold;
		}
	}
	small {
		color: var(--color-warning);
		border: 1px solid var(--color-warning);
		border-radius: 16px;
		padding: 0 9px;

		.icon {
			width: 16px;
			height: 16px;
			margin-bottom: 4px;
		}
	}
}
</style>
