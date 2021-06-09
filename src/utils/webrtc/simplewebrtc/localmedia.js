/* global module */

const util = require('util')
const hark = require('hark')
const getScreenMedia = require('./getscreenmedia')
const WildEmitter = require('wildemitter')
const mockconsole = require('mockconsole')
// Only mediaDevicesManager is used, but it can not be assigned here due to not
// being initialized yet.
const webrtcIndex = require('../index.js')

function isAllTracksEnded(stream) {
	let isAllTracksEnded = true
	stream.getTracks().forEach(function(t) {
		isAllTracksEnded = t.readyState === 'ended' && isAllTracksEnded
	})
	return isAllTracksEnded
}

function isAllAudioTracksEnded(stream) {
	let isAllAudioTracksEnded = true
	stream.getAudioTracks().forEach(function(t) {
		isAllAudioTracksEnded = t.readyState === 'ended' && isAllAudioTracksEnded
	})
	return isAllAudioTracksEnded
}

function LocalMedia(opts) {
	WildEmitter.call(this)

	const config = this.config = {
		detectSpeakingEvents: false,
		audioFallback: false,
		harkOptions: null,
		logger: mockconsole,
	}

	let item
	for (item in opts) {
		if (opts.hasOwnProperty(item)) {
			this.config[item] = opts[item]
		}
	}

	this.logger = config.logger
	this._log = this.logger.log.bind(this.logger, 'LocalMedia:')
	this._logerror = this.logger.error.bind(this.logger, 'LocalMedia:')

	this._audioEnabled = true
	this._videoEnabled = true

	this._localMediaActive = false

	this.localStreams = []
	this._audioMonitorStreams = []
	this.localScreens = []

	if (!webrtcIndex.mediaDevicesManager.isSupported()) {
		this._logerror('Your browser does not support local media capture.')
	}

	this._audioMonitors = []
	this.on('localScreenStopped', this._stopAudioMonitor.bind(this))

	this._handleAudioInputIdChangedBound = this._handleAudioInputIdChanged.bind(this)
	this._handleVideoInputIdChangedBound = this._handleVideoInputIdChanged.bind(this)
}

util.inherits(LocalMedia, WildEmitter)

/**
 * Clones a MediaStreamTrack that will be ended when the original
 * MediaStreamTrack is ended.
 *
 * @param {MediaStreamTrack} track the track to clone
 * @returns {MediaStreamTrack} the linked track
 */
const cloneLinkedTrack = function(track) {
	const linkedTrack = track.clone()

	// Keep a reference of all the linked clones of a track to be able to
	// remove them when the source track is removed.
	if (!track.linkedTracks) {
		track.linkedTracks = []
	}
	track.linkedTracks.push(linkedTrack)

	track.addEventListener('ended', function() {
		linkedTrack.stop()
	})

	return linkedTrack
}

/**
 * Clones a MediaStream that will be ended when the original MediaStream is
 * ended.
 *
 * @param {MediaStream} stream the stream to clone
 * @returns {MediaStream} the linked stream
 */
const cloneLinkedStream = function(stream) {
	const linkedStream = new MediaStream()

	stream.getTracks().forEach(function(track) {
		linkedStream.addTrack(cloneLinkedTrack(track))
	})

	stream.addEventListener('addtrack', function(event) {
		linkedStream.addTrack(cloneLinkedTrack(event.track))
	})

	stream.addEventListener('removetrack', function(event) {
		event.track.linkedTracks.forEach(linkedTrack => {
			linkedStream.removeTrack(linkedTrack)
		})
	})

	return linkedStream
}

/**
 * Returns whether the local media is active or not.
 *
 * The local media is active if it has been started and not stopped yet, even if
 * no media was available when started. An active local media will automatically
 * react to changes in the selected media devices.
 *
 * @returns {bool} true if the local media is active, false otherwise
 */
LocalMedia.prototype.isLocalMediaActive = function() {
	return this._localMediaActive
}

LocalMedia.prototype.start = function(mediaConstraints, cb, context) {
	const self = this
	const constraints = mediaConstraints || { audio: true, video: true }

	// If local media is started with neither audio nor video the local media
	// will not be active (it will not react to changes in the selected media
	// devices). It is just a special case in which starting succeeds with a null
	// stream.
	if (!constraints.audio && !constraints.video) {
		self.emit('localStream', constraints, null)

		if (cb) {
			return cb(null, null, constraints)
		}

		return
	}

	if (!webrtcIndex.mediaDevicesManager.isSupported()) {
		const error = new Error('MediaStreamError')
		error.name = 'NotSupportedError'

		if (cb) {
			return cb(error, null)
		}

		return
	}

	this.emit('localStreamRequested', constraints, context)

	if (!context) {
		// Try to get the devices list before getting user media.
		webrtcIndex.mediaDevicesManager.enableDeviceEvents()
		webrtcIndex.mediaDevicesManager.disableDeviceEvents()
	}

	// The handlers for "change:audioInputId" and "change:videoInputId" events
	// expect the initial "getUserMedia" call to have been completed before
	// being used, so they must be set when the promise is resolved or rejected.

	webrtcIndex.mediaDevicesManager.getUserMedia(constraints).then(function(stream) {
		// Although the promise should be resolved only if all the constraints
		// are met Edge resolves it if both audio and video are requested but
		// only audio is available.
		if (constraints.video && stream.getVideoTracks().length === 0) {
			self.emit('localStreamRequestFailedRetryNoVideo', constraints)
			constraints.video = false
			self.start(constraints, cb, 'retry-no-video')
			return
		}

		// The audio monitor stream is never disabled to be able to analyze it
		// even when the stream sent is muted.
		const audioMonitorStream = cloneLinkedStream(stream)
		if (constraints.audio && self.config.detectSpeakingEvents) {
			self._setupAudioMonitor(audioMonitorStream, self.config.harkOptions)
		}
		self.localStreams.push(stream)
		self._audioMonitorStreams.push(audioMonitorStream)

		stream.getTracks().forEach(function(track) {
			if ((track.kind === 'audio' && !self._audioEnabled)
				|| (track.kind === 'video' && !self._videoEnabled)) {
				track.enabled = false
			}

			track.addEventListener('ended', function() {
				if (isAllTracksEnded(stream) && !self._pendingAudioInputIdChangedCount && !self._pendingVideoInputIdChangedCount) {
					self._removeStream(stream)
				}
			})
		})

		self.emit('localStream', constraints, stream)

		webrtcIndex.mediaDevicesManager.on('change:audioInputId', self._handleAudioInputIdChangedBound)
		webrtcIndex.mediaDevicesManager.on('change:videoInputId', self._handleVideoInputIdChangedBound)

		self._localMediaActive = true

		if (cb) {
			return cb(null, stream, constraints)
		}
	}).catch(function(err) {
		// Fallback for users without a camera or with a camera that can not be
		// accessed, but only if audio is meant to be used.
		if (constraints.audio !== false && self.config.audioFallback && constraints.video !== false) {
			self.emit('localStreamRequestFailedRetryNoVideo', constraints, err)
			constraints.video = false
			self.start(constraints, cb, 'retry-no-video')
			return
		}

		self.emit('localStreamRequestFailed', constraints)

		webrtcIndex.mediaDevicesManager.on('change:audioInputId', self._handleAudioInputIdChangedBound)
		webrtcIndex.mediaDevicesManager.on('change:videoInputId', self._handleVideoInputIdChangedBound)

		self._localMediaActive = true

		if (cb) {
			return cb(err, null)
		}
	})
}

LocalMedia.prototype._handleAudioInputIdChanged = function(mediaDevicesManager, audioInputId) {
	if (this._pendingAudioInputIdChangedCount) {
		this._pendingAudioInputIdChangedCount++

		return
	}

	this._pendingAudioInputIdChangedCount = 1

	const resetPendingAudioInputIdChangedCount = () => {
		const audioInputIdChangedAgain = this._pendingAudioInputIdChangedCount > 1

		this._pendingAudioInputIdChangedCount = 0

		if (audioInputIdChangedAgain) {
			this._handleAudioInputIdChanged(webrtcIndex.mediaDevicesManager.get('audioInputId'))
		}

		if (!this._pendingAudioInputIdChangedCount && !this._pendingVideoInputIdChangedCount) {
			this.localStreams.forEach(stream => {
				if (isAllTracksEnded(stream)) {
					this._removeStream(stream)
				}
			})
		}
	}

	const localStreamsChanged = []
	const localTracksReplaced = []

	if (this.localStreams.length === 0 && audioInputId) {
		// Force the creation of a new stream to add a new audio track to it.
		localTracksReplaced.push({ track: null, stream: null })
	}

	this.localStreams.forEach(stream => {
		if (stream.getAudioTracks().length === 0) {
			localStreamsChanged.push(stream)

			localTracksReplaced.push({ track: null, stream })
		}

		stream.getAudioTracks().forEach(track => {
			const settings = track.getSettings()
			if (track.kind === 'audio' && settings && settings.deviceId !== audioInputId) {
				track.stop()

				stream.removeTrack(track)

				if (!localStreamsChanged.includes(stream)) {
					localStreamsChanged.push(stream)
				}

				localTracksReplaced.push({ track, stream })
			}
		})
	})

	if (audioInputId === null) {
		localStreamsChanged.forEach(stream => {
			this.emit('localStreamChanged', stream)
		})

		localTracksReplaced.forEach(trackStreamPair => {
			this.emit('localTrackReplaced', null, trackStreamPair.track, trackStreamPair.stream)
		})

		resetPendingAudioInputIdChangedCount()

		return
	}

	if (localTracksReplaced.length === 0) {
		resetPendingAudioInputIdChangedCount()

		return
	}

	webrtcIndex.mediaDevicesManager.getUserMedia({ audio: true }).then(stream => {
		// According to the specification "getUserMedia({ audio: true })" will
		// return a single audio track.
		const track = stream.getTracks()[0]
		if (stream.getTracks().length > 1) {
			console.error('More than a single audio track returned by getUserMedia, only the first one will be used')
		}

		localTracksReplaced.forEach(trackStreamPair => {
			const clonedTrack = track.clone()

			let stream = trackStreamPair.stream
			let streamIndex = this.localStreams.indexOf(stream)
			if (streamIndex < 0) {
				stream = new MediaStream()
				this.localStreams.push(stream)
				streamIndex = this.localStreams.length - 1
			}

			stream.addTrack(clonedTrack)

			// The audio monitor stream is never disabled to be able to analyze
			// it even when the stream sent is muted.
			let audioMonitorStream
			if (streamIndex > this._audioMonitorStreams.length - 1) {
				audioMonitorStream = cloneLinkedStream(stream)
				this._audioMonitorStreams.push(audioMonitorStream)
			} else {
				audioMonitorStream = this._audioMonitorStreams[streamIndex]
			}

			if (this.config.detectSpeakingEvents) {
				this._setupAudioMonitor(audioMonitorStream, this.config.harkOptions)
			}

			if (!this._audioEnabled) {
				clonedTrack.enabled = false
			}

			clonedTrack.addEventListener('ended', () => {
				if (isAllTracksEnded(stream) && !this._pendingAudioInputIdChangedCount && !this._pendingVideoInputIdChangedCount) {
					this._removeStream(stream)
				}
			})

			this.emit('localStreamChanged', stream)
			this.emit('localTrackReplaced', clonedTrack, trackStreamPair.track, trackStreamPair.stream)
		})

		// After the clones were added to the local streams the original track
		// is no longer needed.
		track.stop()

		resetPendingAudioInputIdChangedCount()
	}).catch(() => {
		localStreamsChanged.forEach(stream => {
			this.emit('localStreamChanged', stream)
		})

		localTracksReplaced.forEach(trackStreamPair => {
			this.emit('localTrackReplaced', null, trackStreamPair.track, trackStreamPair.stream)
		})

		resetPendingAudioInputIdChangedCount()
	})
}

LocalMedia.prototype._handleVideoInputIdChanged = function(mediaDevicesManager, videoInputId) {
	if (this._pendingVideoInputIdChangedCount) {
		this._pendingVideoInputIdChangedCount++

		return
	}

	this._pendingVideoInputIdChangedCount = 1

	const resetPendingVideoInputIdChangedCount = () => {
		const videoInputIdChangedAgain = this._pendingVideoInputIdChangedCount > 1

		this._pendingVideoInputIdChangedCount = 0

		if (videoInputIdChangedAgain) {
			this._handleVideoInputIdChanged(webrtcIndex.mediaDevicesManager.get('videoInputId'))
		}

		if (!this._pendingAudioInputIdChangedCount && !this._pendingVideoInputIdChangedCount) {
			this.localStreams.forEach(stream => {
				if (isAllTracksEnded(stream)) {
					this._removeStream(stream)
				}
			})
		}
	}

	const localStreamsChanged = []
	const localTracksReplaced = []

	if (this.localStreams.length === 0 && videoInputId) {
		// Force the creation of a new stream to add a new video track to it.
		localTracksReplaced.push({ track: null, stream: null })
	}

	this.localStreams.forEach(stream => {
		if (stream.getVideoTracks().length === 0) {
			localStreamsChanged.push(stream)

			localTracksReplaced.push({ track: null, stream })
		}

		stream.getVideoTracks().forEach(track => {
			const settings = track.getSettings()
			if (track.kind === 'video' && settings && settings.deviceId !== videoInputId) {
				track.stop()

				stream.removeTrack(track)

				if (!localStreamsChanged.includes(stream)) {
					localStreamsChanged.push(stream)
				}

				localTracksReplaced.push({ track, stream })
			}
		})
	})

	if (videoInputId === null) {
		localStreamsChanged.forEach(stream => {
			this.emit('localStreamChanged', stream)
		})

		localTracksReplaced.forEach(trackStreamPair => {
			this.emit('localTrackReplaced', null, trackStreamPair.track, trackStreamPair.stream)
		})

		resetPendingVideoInputIdChangedCount()

		return
	}

	if (localTracksReplaced.length === 0) {
		resetPendingVideoInputIdChangedCount()

		return
	}

	webrtcIndex.mediaDevicesManager.getUserMedia({ video: true }).then(stream => {
		// According to the specification "getUserMedia({ video: true })" will
		// return a single video track.
		const track = stream.getTracks()[0]
		if (stream.getTracks().length > 1) {
			console.error('More than a single video track returned by getUserMedia, only the first one will be used')
		}

		localTracksReplaced.forEach(trackStreamPair => {
			const clonedTrack = track.clone()

			let stream = trackStreamPair.stream
			if (!this.localStreams.includes(stream)) {
				stream = new MediaStream()
				this.localStreams.push(stream)

				const audioMonitorStream = cloneLinkedStream(stream)
				this._audioMonitorStreams.push(audioMonitorStream)
			}

			stream.addTrack(clonedTrack)

			if (!this._videoEnabled) {
				clonedTrack.enabled = false
			}

			clonedTrack.addEventListener('ended', () => {
				if (isAllTracksEnded(stream) && !this._pendingAudioInputIdChangedCount && !this._pendingVideoInputIdChangedCount) {
					this._removeStream(stream)
				}
			})

			this.emit('localStreamChanged', stream)
			this.emit('localTrackReplaced', clonedTrack, trackStreamPair.track, trackStreamPair.stream)
		})

		// After the clones were added to the local streams the original track
		// is no longer needed.
		track.stop()

		resetPendingVideoInputIdChangedCount()
	}).catch(() => {
		localStreamsChanged.forEach(stream => {
			this.emit('localStreamChanged', stream)
		})

		localTracksReplaced.forEach(trackStreamPair => {
			this.emit('localTrackReplaced', null, trackStreamPair.track, trackStreamPair.stream)
		})

		resetPendingVideoInputIdChangedCount()
	})
}

LocalMedia.prototype.stop = function(stream) {
	this.stopStream(stream)
	this.stopScreenShare(stream)

	webrtcIndex.mediaDevicesManager.off('change:audioInputId', this._handleAudioInputIdChangedBound)
	webrtcIndex.mediaDevicesManager.off('change:videoInputId', this._handleVideoInputIdChangedBound)

	this._localMediaActive = false
}

LocalMedia.prototype.stopStream = function(stream) {
	if (stream) {
		const idx = this.localStreams.indexOf(stream)
		if (idx > -1) {
			stream.getTracks().forEach(function(track) {
				track.stop()
			})
		}
	} else {
		this.localStreams.forEach(function(stream) {
			stream.getTracks().forEach(function(track) {
				track.stop()
			})
		})
	}
}

LocalMedia.prototype.startScreenShare = function(mode, constraints, cb) {
	const self = this

	this.emit('localScreenRequested')

	if (typeof constraints === 'function' && !cb) {
		cb = constraints
		constraints = null
	}

	getScreenMedia(mode, constraints, function(err, stream) {
		if (!err) {
			self.localScreens.push(stream)

			stream.getTracks().forEach(function(track) {
				track.addEventListener('ended', function() {
					let isAllTracksEnded = true
					stream.getTracks().forEach(function(t) {
						isAllTracksEnded = t.readyState === 'ended' && isAllTracksEnded
					})

					if (isAllTracksEnded) {
						self._removeStream(stream)
					}
				})
			})

			self.emit('localScreen', stream)
		} else {
			self.emit('localScreenRequestFailed')
		}

		// enable the callback
		if (cb) {
			return cb(err, stream)
		}
	})
}

LocalMedia.prototype.stopScreenShare = function(stream) {
	const self = this

	if (stream) {
		const idx = this.localScreens.indexOf(stream)
		if (idx > -1) {
			stream.getTracks().forEach(function(track) { track.stop() })
			this._removeStream(stream)
		}
	} else {
		this.localScreens.forEach(function(stream) {
			stream.getTracks().forEach(function(track) { track.stop() })
			self._removeStream(stream)
		})
	}
}

// Audio controls
LocalMedia.prototype.mute = function() {
	this._setAudioEnabled(false)
	this.emit('audioOff')
}

LocalMedia.prototype.unmute = function() {
	this._setAudioEnabled(true)
	this.emit('audioOn')
}

// Video controls
LocalMedia.prototype.pauseVideo = function() {
	this._setVideoEnabled(false)
	this.emit('videoOff')
}
LocalMedia.prototype.resumeVideo = function() {
	this._setVideoEnabled(true)
	this.emit('videoOn')
}

// Combined controls
LocalMedia.prototype.pause = function() {
	this.mute()
	this.pauseVideo()
}
LocalMedia.prototype.resume = function() {
	this.unmute()
	this.resumeVideo()
}

// Internal methods for enabling/disabling audio/video
LocalMedia.prototype._setAudioEnabled = function(bool) {
	this._audioEnabled = bool

	this.localStreams.forEach(function(stream) {
		stream.getAudioTracks().forEach(function(track) {
			track.enabled = !!bool
		})
	})
}
LocalMedia.prototype._setVideoEnabled = function(bool) {
	this._videoEnabled = bool

	this.localStreams.forEach(function(stream) {
		stream.getVideoTracks().forEach(function(track) {
			track.enabled = !!bool
		})
	})
}

// check if all audio streams are enabled
LocalMedia.prototype.isAudioEnabled = function() {
	let enabled = true
	let hasAudioTracks = false
	this.localStreams.forEach(function(stream) {
		const audioTracks = stream.getAudioTracks()
		if (audioTracks.length > 0) {
			hasAudioTracks = true
			audioTracks.forEach(function(track) {
				enabled = enabled && track.enabled
			})
		}
	})

	// If no audioTracks were found, that means there is no microphone device.
	// In that case, isAudioEnabled should return false.
	if (!hasAudioTracks) {
		return false
	}

	return enabled
}

// check if all video streams are enabled
LocalMedia.prototype.isVideoEnabled = function() {
	let enabled = true
	let hasVideoTracks = false
	this.localStreams.forEach(function(stream) {
		const videoTracks = stream.getVideoTracks()
		if (videoTracks.length > 0) {
			hasVideoTracks = true
			videoTracks.forEach(function(track) {
				enabled = enabled && track.enabled
			})
		}
	})

	// If no videoTracks were found, that means there is no camera device.
	// In that case, isVideoEnabled should return false.
	if (!hasVideoTracks) {
		return false
	}

	return enabled
}

LocalMedia.prototype._removeStream = function(stream) {
	let idx = this.localStreams.indexOf(stream)
	if (idx > -1) {
		this.localStreams.splice(idx, 1)
		this._audioMonitorStreams.splice(idx, 1)
		this.emit('localStreamStopped', stream)
	} else {
		idx = this.localScreens.indexOf(stream)
		if (idx > -1) {
			this.localScreens.splice(idx, 1)
			this.emit('localScreenStopped', stream)
		}
	}
}

LocalMedia.prototype._setupAudioMonitor = function(stream, harkOptions) {
	this._log('Setup audio')
	const audio = hark(stream, harkOptions)
	const self = this
	let timeout

	stream.getAudioTracks().forEach(function(track) {
		track.addEventListener('ended', function() {
			if (isAllAudioTracksEnded(stream)) {
				self._stopAudioMonitor(stream)
			}
		})
	})

	audio.on('speaking', function() {
		self._speaking = true

		if (self._audioEnabled) {
			self.emit('speaking')
		} else {
			self.emit('speakingWhileMuted')
		}
	})

	audio.on('stopped_speaking', function() {
		if (timeout) {
			clearTimeout(timeout)
		}

		timeout = setTimeout(function() {
			self._speaking = false

			if (self._audioEnabled) {
				self.emit('stoppedSpeaking')
			} else {
				self.emit('stoppedSpeakingWhileMuted')
			}
		}, 1000)
	})

	self.on('audioOn', function() {
		if (self._speaking) {
			self.emit('stoppedSpeakingWhileMuted')
			self.emit('speaking')
		}
	})

	self.on('audioOff', function() {
		if (self._speaking) {
			self.emit('stoppedSpeaking')
			self.emit('speakingWhileMuted')
		}
	})

	audio.on('volume_change', function(volume, threshold) {
		self.emit('volumeChange', volume, threshold)
	})

	this._audioMonitors.push({ audio: audio, stream: stream })
}

LocalMedia.prototype._stopAudioMonitor = function(stream) {
	let idx = -1
	this._audioMonitors.forEach(function(monitors, i) {
		if (monitors.stream === stream) {
			idx = i
		}
	})

	if (idx > -1) {
		this._audioMonitors[idx].audio.stop()
		this._audioMonitors.splice(idx, 1)
	}
}

// fallback for old .localScreen behaviour
Object.defineProperty(LocalMedia.prototype, 'localScreen', {
	get: function() {
		return this.localScreens.length > 0 ? this.localScreens[0] : null
	},
})

module.exports = LocalMedia
