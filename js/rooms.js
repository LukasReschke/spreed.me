// TODO(fancycode): Should load through AMD if possible.
/* global OC, OCA */

(function(OCA, OC, $) {
	'use strict';

	OCA.SpreedMe = OCA.SpreedMe || {};

	function initRooms() {

		var editRoomname = $('#edit-roomname');
		editRoomname.keyup(function () {
			editRoomname.tooltip('hide');
			editRoomname.removeClass('error');
		});
	}

	var currentRoom = '';
	var pingFails = 0;
	Backbone.Radio.channel('rooms');

	OCA.SpreedMe.Rooms = {
		showCamera: function() {
			$('.videoView').removeClass('hidden');
		},
		_createRoomSuccessHandle: function(ocsResponse) {
			var data = ocsResponse.ocs.data;
			OC.Util.History.pushState({
				token: data.token
			}, OC.generateUrl('/call/' + data.token));
			this.join(data.token);
		},
		createOneToOneVideoCall: function(recipientUserId) {
			console.log(recipientUserId);
			$.ajax({
				url: OC.linkToOCS('apps/spreed/api/v1', 2) + 'room',
				type: 'POST',
				data: {
					invite: recipientUserId,
					roomType: 1
				},
				beforeSend: function (request) {
					request.setRequestHeader('Accept', 'application/json');
				},
				success: _.bind(this._createRoomSuccessHandle, this)
			});
		},
		createGroupVideoCall: function(groupId) {
			console.log(groupId);
			$.ajax({
				url: OC.linkToOCS('apps/spreed/api/v1', 2) + 'room',
				type: 'POST',
				data: {
					invite: groupId,
					roomType: 2
				},
				beforeSend: function (request) {
					request.setRequestHeader('Accept', 'application/json');
				},
				success: _.bind(this._createRoomSuccessHandle, this)
			});
		},
		createPublicVideoCall: function() {
			console.log("Creating a new public room.");
			$.ajax({
				url: OC.linkToOCS('apps/spreed/api/v1', 2) + 'room',
				type: 'POST',
				data: {
					roomType: 3
				},
				beforeSend: function (request) {
					request.setRequestHeader('Accept', 'application/json');
				},
				success: _.bind(this._createRoomSuccessHandle, this)
			});
		},
		join: function(token) {
			if (OCA.SpreedMe.Rooms.currentRoom() === token) {
				return;
			}

			$('#emptycontent').hide();
			$('.videoView').addClass('hidden');
			$('#app-content').addClass('icon-loading');

			OCA.SpreedMe.webrtc.leaveRoom();

			currentRoom = token;
			OCA.SpreedMe.webrtc.joinRoom(token);
			OCA.SpreedMe.Rooms.ping();
		},
		leaveCurrentRoom: function() {
			OCA.SpreedMe.webrtc.leaveRoom();
			OC.Util.History.pushState({}, OC.generateUrl('/apps/spreed'));
			$('#app-content').removeClass('incall');
			currentRoom = '';
		},
		currentRoom: function() {
			return currentRoom;
		},
		peers: function(token) {
			return $.ajax({
				beforeSend: function (request) {
					request.setRequestHeader('Accept', 'application/json');
				},
				url: OC.linkToOCS('apps/spreed/api/v1/call', 2) + token
			});
		},
		ping: function() {
			if (OCA.SpreedMe.Rooms.currentRoom() === '') {
				return;
			}

			$.ajax({
				url: OC.linkToOCS('apps/spreed/api/v1/call', 2) + OCA.SpreedMe.Rooms.currentRoom(),
				method: 'PUT'
			}).done(function() {
				pingFails = 0;
			}).fail(function(xhr) {
				// If there is an error when pinging, retry for 3 times.
				if (xhr.status !== 404 && pingFails < 3) {
					pingFails++;
					return;
				}
				OCA.SpreedMe.Rooms.leaveCurrentRoom();
				OCA.SpreedMe.Rooms.showRoomDeletedMessage(false);
			});
		},
		leaveAllRooms: function() {
			$.ajax({
				url: OC.linkToOCS('apps/spreed/api/v1/call', 2),
				method: 'DELETE',
				async: false
			});
		},
		showRoomDeletedMessage: function(deleter) {
			if (deleter) {
				OCA.SpreedMe.app.setEmptyContentMessage(
					'icon-video',
					t('spreed', 'Looking great today! :)'),
					t('spreed', 'Time to call your friends')
				);
			} else {
				OCA.SpreedMe.app.setEmptyContentMessage(
					'icon-video-off',
					t('spreed', 'This call has ended')
				);
			}
		}
	};

	OCA.SpreedMe.initRooms = initRooms;

})(OCA, OC, $);
