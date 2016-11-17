// TODO(fancycode): Should load through AMD if possible.
/* global SimpleWebRTC, OC, OCA: false */

var webrtc;
var spreedMappingTable = [];

(function(OCA, OC) {
	'use strict';

	OCA.SpreedMe = OCA.SpreedMe || {};

	/**
	 * @private
	 */
	function openEventSource() {
		// Connect to the messages endpoint and pull for new messages
		var messageEventSource = new OC.EventSource(OC.generateUrl('/apps/spreed/messages'));
		var previousUsersInRoom = [];
		Array.prototype.diff = function(a) {
			return this.filter(function(i) {
				return a.indexOf(i) < 0;
			});
		};
		messageEventSource.listen('usersInRoom', function(users) {
			var currentUsersInRoom = [];
			users.forEach(function(user) {
				currentUsersInRoom.push(user['sessionId']);
				spreedMappingTable[user['sessionId']] = user['userId'];
			});

			var currentUsersNo = currentUsersInRoom.length;
			if(currentUsersNo === 0) {
				currentUsersNo = 1;
			}

			var appContentElement = $('#app-content'),
				participantsClass = 'participants-' + currentUsersNo;
			if (!appContentElement.hasClass(participantsClass)) {
				appContentElement.attr('class', '').addClass(participantsClass);
			}

			var disconnectedUsers = previousUsersInRoom.diff(currentUsersInRoom);
			disconnectedUsers.forEach(function(user) {
				console.log('XXX Remove peer', user);
				OCA.SpreedMe.webrtc.removePeers(user);
			});
			previousUsersInRoom = currentUsersInRoom;
		});

		messageEventSource.listen('message', function(message) {
			message = JSON.parse(message);
			var peers = self.webrtc.getPeers(message.from, message.roomType);
			var peer;

			if (message.type === 'offer') {
				if (peers.length) {
					peers.forEach(function(p) {
						if (p.sid === message.sid) {
							peer = p;
						}
					});
				}
				if (!peer) {
					peer = self.webrtc.createPeer({
						id: message.from,
						sid: message.sid,
						type: message.roomType,
						enableDataChannels: false,
						sharemyscreen: message.roomType === 'screen' && !message.broadcaster,
						broadcaster: message.roomType === 'screen' && !message.broadcaster ? self.connection.getSessionid() : null
					});
					OCA.SpreedMe.webrtc.emit('createdPeer', peer);
				}
				peer.handleMessage(message);
			} else if(message.type === 'speaking') {
				console.log('received speaking event from ', message.payload);
				OCA.SpreedMe.speakers.add(message.payload);
			} else if(message.type === 'stoppedSpeaking') {
				console.log('received stoppedSpeaking event from ', message.payload);
				OCA.SpreedMe.speakers.remove(message.payload);
			} else if (peers.length) {
				peers.forEach(function(peer) {
					if (message.sid) {
						if (peer.sid === message.sid) {
							peer.handleMessage(message);
						}
					} else {
						peer.handleMessage(message);
					}
				});
			}
		});
		messageEventSource.listen('__internal__', function(data) {
			if (data === 'close') {
				console.log('signaling connection closed - will reopen');
				setTimeout(openEventSource, 0);
			}
		});
	}

	function initWebRTC() {
		'use strict';
		openEventSource();

		webrtc = new SimpleWebRTC({
			localVideoEl: 'localVideo',
			remoteVideosEl: '',
			autoRequestMedia: true,
			debug: false,
			media: {
				audio: true,
				video: {
					width: { max: 1280 },
					height: { max: 720 }
				}
			},
			autoAdjustMic: false,
			detectSpeakingEvents: true,
			connection: OCA.SpreedMe.XhrConnection,
			supportDataChannel: false,
			nick: OC.getCurrentUser()['displayName']
	});
		OCA.SpreedMe.webrtc = webrtc;

		var $appContent = $('#app-content');
		var spreedListofSpeakers = {};
		var latestSpeakerId = null;
		OCA.SpreedMe.speakers = {
			sanitizeId: function(id) {
				return id.replace(/[!"#$%&'()*+,.\/:;<=>?@[\\\]^`{|}~]/g, "\\$&")
			},
			getContainerId: function(id) {
				if (id === OCA.SpreedMe.XhrConnection.getSessionid()) {
					return '#localVideoContainer';
				} else {
					var sanitizedId = OCA.SpreedMe.speakers.sanitizeId(id);
					return '#container_' + sanitizedId + '_type_incoming';
				}
			},
			switchVideoToId: function(id) {
				var videoSpeakingElement = $('#video-speaking');

				if (latestSpeakerId !== null) {
					console.log('move existing promoted user back');
					// move old video to new location
					videoSpeakingElement.find('video').detach().prependTo(OCA.SpreedMe.speakers.getContainerId(latestSpeakerId));
				}

				console.log('change promoted speaker after speaking');

				// add new user to it
				$(OCA.SpreedMe.speakers.getContainerId(id)).detach().prependTo(videoSpeakingElement);

				latestSpeakerId = id;
			},
			add: function(id) {
				var sanitizedId = OCA.SpreedMe.speakers.getContainerId(id);
				spreedListofSpeakers[sanitizedId] = (new Date()).getTime();

				if (latestSpeakerId === id) {
					console.log('latest speaker is already promoted');
					return;
				}

				console.log('change promoted speaker after speaking');
				OCA.SpreedMe.speakers.switchVideoToId(id);
			},
			remove: function(id) {
				var sanitizedId = OCA.SpreedMe.speakers.getContainerId(id);
				spreedListofSpeakers[sanitizedId] = -1;

				if (latestSpeakerId !== id) {
					console.log('stopped speaker is not promoted');
					return;
				}

				console.log('change promoted speaker after speakingStopped');

				var mostRecentTime = 0,
					mostRecentId = null;
				for (var currentId in spreedListofSpeakers) {
					// skip loop if the property is from prototype
					if (!spreedListofSpeakers.hasOwnProperty(currentId)) continue;

					var currentTime = spreedListofSpeakers[currentId];
					if (currentTime > mostRecentTime) {
						mostRecentTime = currentTime;
						mostRecentId = currentId
					}
				}

				if (mostRecentId !== null) {
					console.log('promoted new speaker');
					OCA.SpreedMe.speakers.switchVideoToId(mostRecentId);
				} else {
					console.log('no recent speaker to promote');
				}
			}
		};

		OCA.SpreedMe.webrtc.on('createdPeer', function (peer) {
			peer.pc.on('PeerConnectionTrace', function (event) {
				console.log('trace', event);
			});
		});

		OCA.SpreedMe.webrtc.on('localMediaError', function(error) {
			console.log('Access to microphone & camera failed', error);
			var message, messageAdditional;
			if (error.name === "NotAllowedError") {
				if (error.message && error.message.indexOf("Only secure origins") !== -1) {
					message = t('spreed', 'Access to microphone & camera is only possible with HTTPS');
					messageAdditional = t('spreed', 'Please adjust your configuration');
				} else {
					message = t('spreed', 'Access to microphone & camera was denied');
					$('#emptycontent p').hide();
				}
			} else {
				message = t('spreed', 'Error while accessing microphone & camera: {error}', {error: error.message || error.name});
				$('#emptycontent p').hide();
			}
			$('#emptycontent h2').text(message);
			$('#emptycontent p').text(messageAdditional);
		});

		OCA.SpreedMe.webrtc.on('joinedRoom', function(name) {
			$('#app-content').removeClass('icon-loading');
			$('.videoView').removeClass('hidden');
			OCA.SpreedMe.app.syncAndSetActiveRoom(name);
		});

		OCA.SpreedMe.webrtc.on('videoAdded', function(video, peer) {
			console.log('video added', peer);
			var remotes = document.getElementById('videos');
			if (remotes) {
				// Indicator for username
				var userIndicator = document.createElement('div');
				userIndicator.className = 'nameIndicator';
				userIndicator.textContent = peer.nick;

				// Avatar for username
				var avatar = document.createElement('div');
				avatar.className = 'avatar hidden';

				// Mute indicator
				var muteIndicator = document.createElement('div');
				muteIndicator.className = 'muteIndicator hidden';
				muteIndicator.textContent = 'muted';

				// Generic container
				var container = document.createElement('div');
				container.className = 'videoContainer';
				container.id = 'container_' + OCA.SpreedMe.webrtc.getDomId(peer);
				container.appendChild(video);
				container.appendChild(avatar);
				container.appendChild(userIndicator);
				container.appendChild(muteIndicator);
				video.oncontextmenu = function() {
					return false;
				};

				// show the ice connection state
				if (peer && peer.pc) {
					peer.pc.on('iceConnectionStateChange', function () {
						switch (peer.pc.iceConnectionState) {
							case 'checking':
								console.log('Connecting to peer...');
								break;
							case 'connected':
							case 'completed': // on caller side
								console.log('Connection established.');
								break;
							case 'disconnected':
								// If the peer is still disconnected after 5 seconds
								// we close the video connection.
								setTimeout(function() {
									if(peer.pc.iceConnectionState === 'disconnected') {
										OCA.SpreedMe.webrtc.removePeers(peer.id);
									}
								}, 5000);
								console.log('Disconnected.');
								break;
							case 'failed':
								console.log('Connection failed.');
								break;
							case 'closed':
								console.log('Connection closed.');
								break;
						}
					});
				}

				$(container).prependTo($('#videos'));
			}
		});

		OCA.SpreedMe.webrtc.on('speaking', function(){
			console.log('local speaking');
			OCA.SpreedMe.webrtc.sendToAll('speaking', OCA.SpreedMe.XhrConnection.getSessionid());
		});
		OCA.SpreedMe.webrtc.on('stoppedSpeaking', function(){
			console.log('local stoppedSpeaking');
			OCA.SpreedMe.webrtc.sendToAll('stoppedSpeaking', OCA.SpreedMe.XhrConnection.getSessionid());
		});

		// a peer was removed
		OCA.SpreedMe.webrtc.on('videoRemoved', function(video, peer) {
			var remotes = document.getElementById('videos');
			var el = document.getElementById(peer ? 'container_' + OCA.SpreedMe.webrtc.getDomId(peer) : 'localScreenContainer');
			if (remotes && el) {
				remotes.removeChild(el);
			}
		});

		// Peer is muted
		OCA.SpreedMe.webrtc.on('mute', function(data) {
			var el = document.getElementById('container_' + OCA.SpreedMe.webrtc.getDomId({
					id: data.id,
					type: 'type',
					broadcaster: false
				}));
			var $el = $(el);

			if (data.name === 'video') {
				var avatar = $el.find('.avatar');
				avatar.avatar(spreedMappingTable[data.id], 128);
				avatar.removeClass('hidden');
				avatar.show();
				$el.find('video').hide();
			} else {
				var muteIndicator = $el.find('.muteIndicator');
				muteIndicator.removeClass('hidden');
				muteIndicator.show();
			}
		});

		// Peer is umuted
		OCA.SpreedMe.webrtc.on('unmute', function(data) {
			var el = document.getElementById('container_' + OCA.SpreedMe.webrtc.getDomId({
					id: data.id,
					type: 'type',
					broadcaster: false
				}));
			var $el = $(el);

			if (data.name === 'video') {
				$el.find('.avatar').hide();
				$el.find('video').show();
			} else {
				$el.find('.muteIndicator').hide();
			}
		});

	}

	OCA.SpreedMe.initWebRTC = initWebRTC;

})(OCA, OC);
