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
	<div id="turn_server" class="videocalls section">
		<h2>
			{{ t('spreed', 'TURN servers') }}
			<span v-if="saved" class="icon icon-checkmark-color" :title="t('spreed', 'Saved')" />
			<a v-else-if="!loading"
				v-tooltip.auto="t('spreed', 'Add a new server')"
				class="icon icon-add"
				@click="newServer">
				<span class="hidden-visually">{{ t('spreed', 'Add a new server') }}</span>
			</a>
			<span v-else class="icon icon-loading-small" />
		</h2>

		<p class="settings-hint" v-html="documentationHint" />

		<ul class="turn-servers">
			<transition-group name="fade" tag="li">
				<TurnServer
					v-for="(server, index) in servers"
					:key="`server${index}`"
					:schemes.sync="servers[index].schemes"
					:server.sync="servers[index].server"
					:secret.sync="servers[index].secret"
					:protocols.sync="servers[index].protocols"
					:index="index"
					:loading="loading"
					@removeServer="removeServer"
					@update:schemes="debounceUpdateServers"
					@update:server="debounceUpdateServers"
					@update:secret="debounceUpdateServers"
					@update:protocols="debounceUpdateServers" />
			</transition-group>
		</ul>
	</div>
</template>

<script>
import Tooltip from '@nextcloud/vue/dist/Directives/Tooltip'
import TurnServer from '../../components/AdminSettings/TurnServer'
import { loadState } from '@nextcloud/initial-state'
import debounce from 'debounce'

export default {
	name: 'TurnServers',

	directives: {
		tooltip: Tooltip,
	},

	components: {
		TurnServer,
	},

	data() {
		return {
			servers: [],
			loading: false,
			saved: false,
		}
	},

	computed: {
		documentationHint() {
			return t('spreed', 'A TURN server is used to proxy the traffic from participants behind a firewall. If individual participants cannot connect to others a TURN server is most likely required. See {linkstart}this documentation{linkend} for setup instructions.')
				.replace('{linkstart}', '<a  target="_blank" rel="noreferrer nofollow" class="external" href="https://nextcloud-talk.readthedocs.io/en/latest/TURN/">')
				.replace('{linkend}', ' ↗</a>')
		},
	},

	beforeMount() {
		this.servers = loadState('spreed', 'turn_servers')
	},

	methods: {
		removeServer(index) {
			this.servers.splice(index, 1)
			this.debounceUpdateServers()
		},

		newServer() {
			this.servers.push({
				schemes: 'turn', // default to turn only
				server: '',
				secret: '',
				protocols: 'udp,tcp', // default to udp AND tcp
			})
		},

		debounceUpdateServers: debounce(function() {
			this.updateServers()
		}, 1000),

		async updateServers() {
			const servers = []

			this.servers.forEach((server) => {
				const data = {
					schemes: server.schemes,
					server: server.server,
					secret: server.secret,
					protocols: server.protocols,
				}

				if (data.server.startsWith('https://')) {
					data.server = data.server.substr(8)
				} else if (data.server.startsWith('http://')) {
					data.server = data.server.substr(7)
				}

				if (data.secret === '') {
					return
				}

				servers.push(data)
			})

			const self = this

			this.loading = true
			OCP.AppConfig.setValue('spreed', 'turn_servers', JSON.stringify(servers), {
				success() {
					self.loading = false
					self.toggleSave()
				},
			})
		},

		toggleSave() {
			this.saved = true
			setTimeout(() => {
				this.saved = false
			}, 3000)
		},
	},
}
</script>
