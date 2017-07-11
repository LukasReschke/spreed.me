<?php
/**
 * @copyright Copyright (c) 2016 Lukas Reschke <lukas@statuscode.ch>
 *
 * @author Lukas Reschke <lukas@statuscode.ch>
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

return [
	'routes' => [
		[
			'name' => 'Page#index',
			'url' => '/',
			'verb' => 'GET',
		],
		[
			'name' => 'Signalling#signalling',
			'url' => '/signalling',
			'verb' => 'POST',
		],
		[
			'name' => 'Signalling#pullMessages',
			'url' => '/messages',
			'verb' => 'GET',
		],
		[
			'name' => 'AppSettings#setSpreedSettings',
			'url' => '/settings/admin',
			'verb' => 'POST',
		],
	],
	'ocs' => [
		[
			'name' => 'Call#getPeersForCall',
			'url' => '/api/{apiVersion}/call/{token}',
			'verb' => 'GET',
			'requirements' => [
				'apiVersion' => 'v1',
				'token' => '^[a-z0-9]{4,30}$',
			],
		],
		[
			'name' => 'Call#joinCall',
			'url' => '/api/{apiVersion}/call/{token}',
			'verb' => 'POST',
			'requirements' => [
				'apiVersion' => 'v1',
				'token' => '^[a-z0-9]{4,30}$',
			],
		],
		[
			'name' => 'Call#pingCall',
			'url' => '/api/{apiVersion}/call/{token}',
			'verb' => 'PUT',
			'requirements' => [
				'apiVersion' => 'v1',
				'token' => '^[a-z0-9]{4,30}$',
			],
		],
		[
			'name' => 'Call#leaveCall',
			'url' => '/api/{apiVersion}/call',
			'verb' => 'DELETE',
			'requirements' => ['apiVersion' => 'v1'],
		],

		[
			'name' => 'Room#getRooms',
			'url' => '/api/{apiVersion}/room',
			'verb' => 'GET',
			'requirements' => ['apiVersion' => 'v1'],
		],
		[
			'name' => 'Room#getRoom',
			'url' => '/api/{apiVersion}/room/{token}',
			'verb' => 'GET',
			'requirements' => [
				'apiVersion' => 'v1',
				'token' => '^[a-z0-9]{4,30}$',
			],
		],
		[
			'name' => 'Room#createOneToOneRoom',
			'url' => '/api/{apiVersion}/oneToOne',
			'verb' => 'PUT',
			'requirements' => ['apiVersion' => 'v1'],
		],
		[
			'name' => 'Room#createGroupRoom',
			'url' => '/api/{apiVersion}/group',
			'verb' => 'PUT',
			'requirements' => ['apiVersion' => 'v1'],
		],
		[
			'name' => 'Room#createPublicRoom',
			'url' => '/api/{apiVersion}/public',
			'verb' => 'PUT',
			'requirements' => ['apiVersion' => 'v1'],
		],
		[
			'name' => 'Room#renameRoom',
			'url' => '/api/{apiVersion}/room/{roomId}',
			'verb' => 'PUT',
			'requirements' => [
				'apiVersion' => 'v1',
				'roomId' => '\d+'
			],
		],
		[
			'name' => 'Room#makePublic',
			'url' => '/api/{apiVersion}/room/public',
			'verb' => 'POST',
			'requirements' => ['apiVersion' => 'v1'],
		],
		[
			'name' => 'Room#makePrivate',
			'url' => '/api/{apiVersion}/room/public',
			'verb' => 'DELETE',
			'requirements' => ['apiVersion' => 'v1'],
		],
		[
			'name' => 'Room#addParticipantToRoom',
			'url' => '/api/{apiVersion}/room/{roomId}',
			'verb' => 'POST',
			'requirements' => [
				'apiVersion' => 'v1',
				'roomId' => '\d+'
			],
		],
		[
			'name' => 'Room#removeSelfFromRoom',
			'url' => '/api/{apiVersion}/room/{roomId}',
			'verb' => 'DELETE',
			'requirements' => [
				'apiVersion' => 'v1',
				'roomId' => '\d+'
			],
		],
	],
];

