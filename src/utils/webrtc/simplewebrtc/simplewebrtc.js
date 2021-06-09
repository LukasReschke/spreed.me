/* global module */

const WebRTC = require('./webrtc')
const WildEmitter = require('wildemitter')
const webrtcSupport = require('webrtcsupport')
const attachMediaStream = require('attachmediastream')
const mockconsole = require('mockconsole')

function SimpleWebRTC(opts) {
	const self = this
	const options = opts || {}
	const config = this.config = {
		socketio: {/* 'force new connection':true */},
		connection: null,
		debug: false,
		localVideoEl: '',
		remoteVideosEl: '',
		enableDataChannels: true,
		autoRequestMedia: false,
		autoRemoveVideos: true,
		adjustPeerVolume: false,
		peerVolumeWhenSpeaking: 0.25,
		receiveMedia: {
			offerToReceiveAudio: 1,
			offerToReceiveVideo: 1,
		},
		localVideo: {
			autoplay: true,
			mirror: true,
			muted: true,
		},
	}
	let item, connection

	// We also allow a 'logger' option. It can be any object that implements
	// log, warn, and error methods.
	// We log nothing by default, following "the rule of silence":
	// http://www.linfo.org/rule_of_silence.html
	this.logger = (function() {
		// we assume that if you're in debug mode and you didn't
		// pass in a logger, you actually want to log as much as
		// possible.
		if (opts.debug) {
			return opts.logger || console
		} else {
		// or we'll use your logger which should have its own logic
		// for output. Or we'll return the no-op.
			return opts.logger || mockconsole
		}
	}())

	// set our config from options
	for (item in options) {
		if (options.hasOwnProperty(item)) {
			this.config[item] = options[item]
		}
	}

	// Override screensharing support detection to fit the custom
	// "getScreenMedia" module.
	// Note that this is a coarse check; calling "getScreenMedia" may fail even
	// if "supportScreenSharing" is true.
	const screenSharingSupported
			= (window.navigator.mediaDevices && window.navigator.mediaDevices.getDisplayMedia)
			|| (window.navigator.webkitGetUserMedia)
			|| (window.navigator.userAgent.match('Firefox'))
	webrtcSupport.supportScreenSharing = window.location.protocol === 'https:' && screenSharingSupported

	// attach detected support for convenience
	this.capabilities = webrtcSupport

	// call WildEmitter constructor
	WildEmitter.call(this)

	if (this.config.connection === null) {
		throw new Error('no connection object given in the configuration')
	} else {
		connection = this.connection = this.config.connection
	}

	connection.on('message', function(message) {
		const peers = self.webrtc.getPeers(message.from, message.roomType)
		let peer

		if (message.type === 'offer') {
			if (peers.length) {
				peers.forEach(function(p) {
					if (p.sid === message.sid) {
						peer = p
					}
				})
				// if (!peer) peer = peers[0]; // fallback for old protocol versions
			}
			if (!peer) {
				peer = self.webrtc.createPeer({
					id: message.from,
					sid: message.sid,
					type: message.roomType,
					enableDataChannels: self.config.enableDataChannels && message.roomType !== 'screen',
					sharemyscreen: message.roomType === 'screen' && !message.broadcaster,
					broadcaster: message.roomType === 'screen' && !message.broadcaster ? self.connection.getSessionId() : null,
					sendVideoIfAvailable: self.connection.getSendVideoIfAvailable(),
					receiverOnly: self.connection.hasFeature('mcu'),
				})
				self.emit('createdPeer', peer)
			}
			peer.handleMessage(message)
		} else if (message.type === 'control') {
			if (message.payload.action === 'forceMute') {
				if (message.payload.peerId === self.connection.getSessionId()) {
					if (self.webrtc.isAudioEnabled()) {
						self.mute()
						self.emit('forcedMute')
					}
				} else {
					self.emit('mute', { id: message.payload.peerId })
				}
			}
		} else if (message.type === 'nickChanged') {
			// "nickChanged" can be received from a participant without a Peer
			// object if that participant is not sending audio nor video.
			self.emit('nick', { id: message.from, name: message.payload.name })
		} else if (message.type === 'raiseHand') {
			// "raisedHand" can be received from a participant without a Peer
			// object if that participant is not sending audio nor video.
			self.emit('raisedHand', { id: message.from, raised: message.payload })
		} else if (peers.length) {
			peers.forEach(function(peer) {
				if (message.sid && !self.connection.hasFeature('mcu')) {
					if (peer.sid === message.sid) {
						peer.handleMessage(message)
					}
				} else {
					peer.handleMessage(message)
				}
			})
		}
	})

	connection.on('remove', function(room) {
		if (room.id !== self.connection.getSessionId()) {
			self.webrtc.removePeers(room.id, room.type)
		}
	})

	// instantiate our main WebRTC helper
	// using same logger from logic here
	opts.logger = this.logger
	opts.debug = false
	this.webrtc = new WebRTC(opts);

	// attach a few methods from underlying lib to simple.
	['mute', 'unmute', 'pauseVideo', 'resumeVideo', 'pause', 'resume', 'sendToAll', 'sendDirectlyToAll', 'getPeers', 'createPeer', 'removePeers'].forEach(function(method) {
		self[method] = self.webrtc[method].bind(self.webrtc)
	})

	// proxy events from WebRTC
	this.webrtc.on('*', function() {
		self.emit.apply(self, arguments)
	})

	// log all events in debug mode
	if (config.debug) {
		this.on('*', this.logger.log.bind(this.logger, 'SimpleWebRTC event:'))
	}

	this.webrtc.on('message', function(payload) {
		self.connection.emit('message', payload)
	})

	this.webrtc.on('peerStreamAdded', this.handlePeerStreamAdded.bind(this))
	this.webrtc.on('peerStreamRemoved', this.handlePeerStreamRemoved.bind(this))

	// echo cancellation attempts
	if (this.config.adjustPeerVolume) {
		this.webrtc.on('speaking', this.setVolumeForAll.bind(this, this.config.peerVolumeWhenSpeaking))
		this.webrtc.on('stoppedSpeaking', this.setVolumeForAll.bind(this, 1))
	}

	connection.on('stunservers', function(args) {
		// resets/overrides the config
		self.webrtc.config.peerConnectionConfig.iceServers = args
		self.emit('stunservers', args)
	})
	connection.on('turnservers', function(args) {
		// appends to the config
		self.webrtc.config.peerConnectionConfig.iceServers = self.webrtc.config.peerConnectionConfig.iceServers.concat(args)
		self.emit('turnservers', args)
	})

	this.webrtc.on('iceFailed', function(/* peer */) {
		// local ice failure
	})
	this.webrtc.on('connectivityError', function(/* peer */) {
		// remote ice failure
	})

	// sending mute/unmute to all peers
	this.webrtc.on('audioOn', function() {
		self.webrtc.sendToAll('unmute', { name: 'audio' })
	})
	this.webrtc.on('audioOff', function() {
		self.webrtc.sendToAll('mute', { name: 'audio' })
	})
	this.webrtc.on('videoOn', function() {
		self.webrtc.sendToAll('unmute', { name: 'video' })
	})
	this.webrtc.on('videoOff', function() {
		self.webrtc.sendToAll('mute', { name: 'video' })
	})

	// screensharing events
	this.webrtc.on('localScreen', function(stream) {
		const el = document.createElement('video')
		const container = self.getRemoteVideoContainer()

		el.oncontextmenu = function() { return false }
		el.id = 'localScreen'
		attachMediaStream(stream, el)
		if (container) {
			container.appendChild(el)
		}

		self.emit('localScreenAdded', el)
		self.connection.emit('shareScreen')

		// NOTE: we don't create screen peers for existing video peers here,
		// this is done by the application code in "webrtc.js".
	})
	this.webrtc.on('localScreenStopped', function(/* stream */) {
		self.stopScreenShare()
		/*
		self.connection.emit('unshareScreen');
		self.webrtc.peers.forEach(function (peer) {
			if (peer.sharemyscreen) {
				peer.end();
			}
		});
		*/
	})
}

SimpleWebRTC.prototype = Object.create(WildEmitter.prototype, {
	constructor: {
		value: SimpleWebRTC,
	},
})

SimpleWebRTC.prototype.leaveCall = function() {
	if (this.roomName) {
		while (this.webrtc.peers.length) {
			this.webrtc.peers[0].end()
		}
		if (this.getLocalScreen()) {
			this.stopScreenShare()
		}
		this.emit('leftRoom', this.roomName)
		this.stopLocalVideo()
		this.roomName = undefined
	}
}

SimpleWebRTC.prototype.disconnect = function() {
	this.emit('disconnected')
}

SimpleWebRTC.prototype.handlePeerStreamAdded = function(peer) {
	const container = this.getRemoteVideoContainer()
	if (container) {
		// If there is a video track Chromium does not play audio in a video element
		// until the video track starts to play; an audio element is thus needed to
		// play audio when the remote peer starts with the camera available but
		// disabled.
		const audio = attachMediaStream(peer.stream, null, { audio: true })
		const video = attachMediaStream(peer.stream)

		video.muted = true

		// At least Firefox, Opera and Edge move the video to a wrong position
		// instead of keeping it unchanged when "transform: scaleX(1)" is used
		// ("transform: scaleX(-1)" is fine); as it should have no effect the
		// transform is removed.
		if (video.style.transform === 'scaleX(1)') {
			video.style.transform = ''
		}

		// store video element as part of peer for easy removal
		peer.audioEl = audio
		peer.videoEl = video
		audio.id = this.getDomId(peer) + '-audio'
		video.id = this.getDomId(peer)

		container.appendChild(audio)
		container.appendChild(video)

		this.emit('videoAdded', video, audio, peer)
	}
}

SimpleWebRTC.prototype.handlePeerStreamRemoved = function(peer) {
	const container = this.getRemoteVideoContainer()
	const audioEl = peer.audioEl
	const videoEl = peer.videoEl
	if (this.config.autoRemoveVideos && container && audioEl) {
		container.removeChild(audioEl)
	}
	if (this.config.autoRemoveVideos && container && videoEl) {
		container.removeChild(videoEl)
	}
	if (videoEl) {
		this.emit('videoRemoved', videoEl, peer)
	}
}

SimpleWebRTC.prototype.getDomId = function(peer) {
	return [peer.id, peer.type, peer.broadcaster ? 'broadcasting' : 'incoming'].join('_')
}

// set volume on video tag for all peers takse a value between 0 and 1
SimpleWebRTC.prototype.setVolumeForAll = function(volume) {
	this.webrtc.peers.forEach(function(peer) {
		if (peer.audioEl) {
			peer.audioEl.volume = volume
		}
	})
}

SimpleWebRTC.prototype.joinCall = function(name, mediaConstraints) {
	if (this.config.autoRequestMedia) {
		this.startLocalVideo(mediaConstraints)
	}
	this.roomName = name
	this.emit('joinedRoom', name)
}

SimpleWebRTC.prototype.getEl = function(idOrEl) {
	if (typeof idOrEl === 'string') {
		return document.getElementById(idOrEl)
	} else {
		return idOrEl
	}
}

SimpleWebRTC.prototype.startLocalVideo = function(mediaConstraints) {
	const self = this
	this.webrtc.start(mediaConstraints, function(err, stream, actualConstraints) {
		if (err) {
			self.emit('localMediaError', err)
		} else {
			self.emit('localMediaStarted', actualConstraints)

			const localVideoContainer = self.getLocalVideoContainer()
			if (localVideoContainer) {
				attachMediaStream(stream, localVideoContainer, self.config.localVideo)
			}
		}
	})
}

SimpleWebRTC.prototype.stopLocalVideo = function() {
	this.webrtc.stop()
}

// this accepts either element ID or element
// and either the video tag itself or a container
// that will be used to put the video tag into.
SimpleWebRTC.prototype.getLocalVideoContainer = function() {
	const el = this.getEl(this.config.localVideoEl)
	if (el && el.tagName === 'VIDEO') {
		el.oncontextmenu = function() { return false }
		return el
	} else if (el) {
		const video = document.createElement('video')
		video.oncontextmenu = function() { return false }
		el.appendChild(video)
		return video
	}
}

SimpleWebRTC.prototype.getRemoteVideoContainer = function() {
	return this.getEl(this.config.remoteVideosEl)
}

SimpleWebRTC.prototype.shareScreen = function(mode, cb) {
	this.webrtc.startScreenShare(mode, cb)
}

SimpleWebRTC.prototype.getLocalScreen = function() {
	return this.webrtc.localScreen
}

SimpleWebRTC.prototype.stopScreenShare = function() {
	this.connection.emit('unshareScreen')
	const videoEl = document.getElementById('localScreen')
	const container = this.getRemoteVideoContainer()

	if (this.config.autoRemoveVideos && container && videoEl) {
		container.removeChild(videoEl)
	}

	// a hack to emit the event the removes the video
	// element that we want
	if (videoEl) {
		this.emit('videoRemoved', videoEl)
	}
	if (this.getLocalScreen()) {
		this.webrtc.stopScreenShare()
	}
	// Notify peers were sending to.
	this.webrtc.peers.forEach(function(peer) {
		if (peer.type === 'screen' && peer.sharemyscreen) {
			peer.send('unshareScreen')
		}
		if (peer.broadcaster) {
			peer.end()
		}
	})
}

SimpleWebRTC.prototype.createRoom = function(name, cb) {
	this.roomName = name
	if (arguments.length === 2) {
		this.connection.emit('create', name, cb)
	} else {
		this.connection.emit('create', name)
	}
}

module.exports = SimpleWebRTC
