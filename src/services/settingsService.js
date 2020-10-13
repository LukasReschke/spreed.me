/**
 * @copyright Copyright (c) 2020 Joas Schilling <coding@schilljs.com>
 *
 * @author Joas Schilling <coding@schilljs.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

/**
 * Sets the attachment folder setting for the user
 *
 * @param {string} path The name of the folder
 * @returns {Object} The axios response
 */
const setAttachmentFolder = async function(path) {
	return axios.post(generateOcsUrl('apps/spreed/api/v1/settings', 2) + 'user', {
		key: 'attachment_folder',
		value: path,
	})
}

/**
 * Sets the flag for sending messages with shift+enter
 *
 * @param {String} key key
 * @returns {Object} The axios response
 */
const setSendMessageKey = async function(key) {
	return axios.post(generateOcsUrl('apps/spreed/api/v1/settings', 2) + 'user', {
		key: 'send_message_key',
		value: key,
	})
}

export {
	setAttachmentFolder,
	setSendMessageKey,
}
