/**
 * @copyright Copyright (c) 2019 Joas Schilling <coding@schilljs.com>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
export const CONVERSATION = {
	START_CALL: {
		EVERYONE: 0,
		USERS: 1,
		MODERATORS: 2,
	},
	STATE: {
		READ_WRITE: 0,
		READ_ONLY: 1,
	},
	TYPE: {
		ONE_TO_ONE: 1,
		GROUP: 2,
		PUBLIC: 3,
		CHANGELOG: 4,
		NOTES: 5,
	},
}
export const PARTICIPANT = {
	CALL_FLAG: {
		DISCONNECTED: 0,
		IN_CALL: 1,
		WITH_AUDIO: 2,
		WITH_VIDEO: 4,
	},
	NOTIFY: {
		DEFAULT: 0,
		ALWAYS: 1,
		MENTION: 2,
		NEVER: 3,
	},
	TYPE: {
		OWNER: 1,
		MODERATOR: 2,
		USER: 3,
		GUEST: 4,
		USER_SELF_JOINED: 5,
		GUEST_MODERATOR: 6,
	},
}
export const WEBINAR = {
	LOBBY: {
		NONE: 0,
		NON_MODERATORS: 1,
	},
}
export const SHARE = {
	TYPE: {
		USER: 0,
		GROUP: 1,
		EMAIL: 4,
		REMOTE: 6,
		CIRCLE: 7,
	},
}
export const FLOW = {
	MESSAGE_MODES: {
		NO_MENTION: 1,
		SELF_MENTION: 2,
		ROOM_MENTION: 3,
	},
}
