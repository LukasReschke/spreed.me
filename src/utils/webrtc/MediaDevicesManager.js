/**
 *
 * @copyright Copyright (c) 2020, Daniel Calviño Sánchez (danxuliu@gmail.com)
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

import BrowserStorage from '../../services/BrowserStorage'
import VideoEffects from './VideoEffects'
/**
 * Special string to set null device ids in local storage (as only strings are
 * allowed).
 */
const LOCAL_STORAGE_NULL_DEVICE_ID = 'local-storage-null-device-id'

/**
 * Wrapper for MediaDevices to simplify its use.
 *
 * The MediaDevicesManager keeps an updated list of devices that can be accessed
 * from "attributes.devices". Clients of this class must call
 * "enableDeviceEvents()" to start keeping track of the devices, and
 * "disableDeviceEvents()" once it is no longer needed. Eventually there must be
 * one call to "disableDeviceEvents()" for each call to "enableDeviceEvents()",
 * but several clients can be active at the same time.
 *
 * Each element of "attributes.devices" is an object with the following fields:
 * - deviceId: the unique identifier for the device
 * - groupId: two or more devices have the same groupId if they belong to the
 *   same physical device
 * - kind: either "audioinput", "videoinput" or "audiooutput"
 * - label: a human readable identifier for the device
 * - fallbackLabel: a generated label if the actual label is empty
 *
 * Note that the list may not contain some kind of devices due to browser
 * limitations (for example, currently Firefox does not list "audiooutput"
 * devices).
 *
 * In some browsers if persistent media permissions have not been granted and a
 * MediaStream is not active the list may contain at most one device of each
 * kind, and all of them with empty attributes except for the kind.
 *
 * In other browsers just the label may not be available if persistent media
 * permissions have not been granted and a MediaStream has not been active. In
 * those cases the fallback label can be used instead.
 *
 * "attributes.audioInputId" and "attributes.videoInputId" define the devices
 * that will be used when calling "getUserMedia(constraints)". Clients of this
 * class must modify them using "set('audioInputId', value)" and
 * "set('videoInputId', value)" to ensure that change events are triggered.
 * However, note that change events are not triggered when the devices are
 * modified.
 *
 * The selected devices will be automatically cleared if they are no longer
 * available, and they will be restored once they are again available
 * (immediately if events are enabled, or otherwise the next time that devices
 * are got). When no device of certain kind is selected and there are other
 * devices of that kind the selected device will fall back to the first one
 * found, or to the one with the "default" id (if any). It is possible to
 * explicitly disable devices of certain kind by setting xxxInputId to "null"
 * (in that case the fallback devices will not be taken into account).
 */
export default function MediaDevicesManager() {
	this.attributes = {
		devices: [],

		audioInputId: undefined,
		videoInputId: undefined,
	}

	this._handlers = []

	this._enabledCount = 0

	this._knownDevices = {}

	this._fallbackAudioInputId = undefined
	this._fallbackVideoInputId = undefined

	this._tracks = []

	this._updateDevicesBound = this._updateDevices.bind(this)

	this._pendingEnumerateDevicesPromise = null

	if (BrowserStorage.getItem('audioInputId') === LOCAL_STORAGE_NULL_DEVICE_ID) {
		this.attributes.audioInputId = null
	}
	if (BrowserStorage.getItem('videoInputId') === LOCAL_STORAGE_NULL_DEVICE_ID) {
		this.attributes.videoInputId = null
	}
}
MediaDevicesManager.prototype = {

	get(key) {
		return this.attributes[key]
	},

	set(key, value) {
		this.attributes[key] = value

		this._trigger('change:' + key, [value])

		this._storeDeviceId(key, value)
	},

	_storeDeviceId(key, value) {
		if (key !== 'audioInputId' && key !== 'videoInputId') {
			return
		}

		if (value === null) {
			value = LOCAL_STORAGE_NULL_DEVICE_ID
		}

		if (value) {
			BrowserStorage.setItem(key, value)
		} else {
			BrowserStorage.removeItem(key)
		}
	},

	on(event, handler) {
		if (!Object.prototype.hasOwnProperty.call(this._handlers, event)) {
			this._handlers[event] = [handler]
		} else {
			this._handlers[event].push(handler)
		}
	},

	off(event, handler) {
		const handlers = this._handlers[event]
		if (!handlers) {
			return
		}

		const index = handlers.indexOf(handler)
		if (index !== -1) {
			handlers.splice(index, 1)
		}
	},

	_trigger(event, args) {
		let handlers = this._handlers[event]
		if (!handlers) {
			return
		}

		args.unshift(this)

		handlers = handlers.slice(0)
		for (let i = 0; i < handlers.length; i++) {
			const handler = handlers[i]
			handler.apply(handler, args)
		}
	},

	/**
	 * Returns whether getting user media and enumerating media devices is
	 * supported or not.
	 *
	 * Note that even if false is returned the MediaDevices interface could be
	 * technically supported by the browser but not available due to the page
	 * being loaded in an insecure context.
	 *
	 * @returns {boolean} true if MediaDevices interface is supported, false
	 *          otherwise.
	 */
	isSupported() {
		return navigator && navigator.mediaDevices && navigator.mediaDevices.getUserMedia && navigator.mediaDevices.enumerateDevices
	},

	enableDeviceEvents() {
		if (!this.isSupported()) {
			return
		}

		this._enabledCount++

		this._updateDevices()

		navigator.mediaDevices.addEventListener('devicechange', this._updateDevicesBound)
	},

	disableDeviceEvents() {
		if (!this.isSupported()) {
			return
		}

		this._enabledCount--

		if (!this._enabledCount) {
			navigator.mediaDevices.removeEventListener('devicechange', this._updateDevicesBound)
		}
	},

	_updateDevices() {
		this._pendingEnumerateDevicesPromise = navigator.mediaDevices.enumerateDevices().then(devices => {
			const previousAudioInputId = this.attributes.audioInputId
			const previousVideoInputId = this.attributes.videoInputId

			const removedDevices = this.attributes.devices.filter(oldDevice => !devices.find(device => oldDevice.deviceId === device.deviceId && oldDevice.kind === device.kind))
			const updatedDevices = devices.filter(device => this.attributes.devices.find(oldDevice => device.deviceId === oldDevice.deviceId && device.kind === oldDevice.kind))
			const addedDevices = devices.filter(device => !this.attributes.devices.find(oldDevice => device.deviceId === oldDevice.deviceId && device.kind === oldDevice.kind))

			removedDevices.forEach(removedDevice => {
				this._removeDevice(removedDevice)
			})
			updatedDevices.forEach(updatedDevice => {
				this._updateDevice(updatedDevice)
			})
			addedDevices.forEach(addedDevice => {
				this._addDevice(addedDevice)
			})

			// Trigger change events after all the devices are processed to
			// prevent change events for intermediate states.
			if (previousAudioInputId !== this.attributes.audioInputId) {
				this._trigger('change:audioInputId', [this.attributes.audioInputId])
			}
			if (previousVideoInputId !== this.attributes.videoInputId) {
				this._trigger('change:videoInputId', [this.attributes.videoInputId])
			}

			this._pendingEnumerateDevicesPromise = null
		}).catch(function(error) {
			console.error('Could not update known media devices: ' + error.name + ': ' + error.message)

			this._pendingEnumerateDevicesPromise = null
		})
	},

	_removeDevice(removedDevice) {
		const removedDeviceIndex = this.attributes.devices.findIndex(oldDevice => oldDevice.deviceId === removedDevice.deviceId && oldDevice.kind === removedDevice.kind)
		if (removedDeviceIndex >= 0) {
			this.attributes.devices.splice(removedDeviceIndex, 1)
		}

		if (removedDevice.kind === 'audioinput') {
			if (this._fallbackAudioInputId === removedDevice.deviceId) {
				const firstAudioInputDevice = this.attributes.devices.find(device => device.kind === 'audioinput')
				this._fallbackAudioInputId = firstAudioInputDevice ? firstAudioInputDevice.deviceId : undefined
			}
			if (this.attributes.audioInputId === removedDevice.deviceId) {
				this.attributes.audioInputId = this._fallbackAudioInputId
			}
		} else if (removedDevice.kind === 'videoinput') {
			if (this._fallbackVideoInputId === removedDevice.deviceId) {
				const firstVideoInputDevice = this.attributes.devices.find(device => device.kind === 'videoinput')
				this._fallbackVideoInputId = firstVideoInputDevice ? firstVideoInputDevice.deviceId : undefined
			}
			if (this.attributes.videoInputId === removedDevice.deviceId) {
				this.attributes.videoInputId = this._fallbackVideoInputId
			}
		}
	},

	_updateDevice(updatedDevice) {
		const oldDevice = this.attributes.devices.find(oldDevice => oldDevice.deviceId === updatedDevice.deviceId && oldDevice.kind === updatedDevice.kind)

		// Only update the label if it has a value, as it may have been
		// removed if there is currently no active stream.
		if (updatedDevice.label) {
			oldDevice.label = updatedDevice.label
		}

		// These should not have changed, but just in case
		oldDevice.groupId = updatedDevice.groupId
		oldDevice.kind = updatedDevice.kind
	},

	_addDevice(addedDevice) {
		// Copy the device to add, as its properties are read only and
		// thus they can not be updated later.
		addedDevice = {
			deviceId: addedDevice.deviceId,
			groupId: addedDevice.groupId,
			kind: addedDevice.kind,
			label: addedDevice.label,
		}

		const knownDevice = this._knownDevices[addedDevice.kind + '-' + addedDevice.deviceId]
		if (knownDevice) {
			addedDevice.fallbackLabel = knownDevice.fallbackLabel
			// If the added device has a label keep it; otherwise use
			// the previously known one, if any.
			addedDevice.label = addedDevice.label ? addedDevice.label : knownDevice.label
		} else {
			// Generate a fallback label to be used when the actual label is
			// not available.
			if (addedDevice.deviceId === 'default' || addedDevice.deviceId === '') {
				addedDevice.fallbackLabel = t('spreed', 'Default')
			} else if (addedDevice.kind === 'audioinput') {
				addedDevice.fallbackLabel = t('spreed', 'Microphone {number}', { number: Object.values(this._knownDevices).filter(device => device.kind === 'audioinput' && device.deviceId !== '').length + 1 })
			} else if (addedDevice.kind === 'videoinput') {
				addedDevice.fallbackLabel = t('spreed', 'Camera {number}', { number: Object.values(this._knownDevices).filter(device => device.kind === 'videoinput' && device.deviceId !== '').length + 1 })
			} else if (addedDevice.kind === 'audiooutput') {
				addedDevice.fallbackLabel = t('spreed', 'Speaker {number}', { number: Object.values(this._knownDevices).filter(device => device.kind === 'audioutput' && device.deviceId !== '').length + 1 })
			}
		}

		// Always refresh the known device with the latest values.
		this._knownDevices[addedDevice.kind + '-' + addedDevice.deviceId] = addedDevice

		// Restore previously selected device if it becomes available again.
		// Additionally, set first available device as fallback, and override
		// any fallback previously set if the default device is added.
		if (addedDevice.kind === 'audioinput') {
			if (BrowserStorage.getItem('audioInputId') === addedDevice.deviceId) {
				this.attributes.audioInputId = addedDevice.deviceId
			}
			if (!this._fallbackAudioInputId || addedDevice.deviceId === 'default') {
				this._fallbackAudioInputId = addedDevice.deviceId
			}
			if (this.attributes.audioInputId === undefined) {
				this.attributes.audioInputId = this._fallbackAudioInputId
			}
		} else if (addedDevice.kind === 'videoinput') {
			if (BrowserStorage.getItem('videoInputId') === addedDevice.deviceId) {
				this.attributes.videoInputId = addedDevice.deviceId
			}
			if (!this._fallbackVideoInputId || addedDevice.deviceId === 'default') {
				this._fallbackVideoInputId = addedDevice.deviceId
			}
			if (this.attributes.videoInputId === undefined) {
				this.attributes.videoInputId = this._fallbackVideoInputId
			}
		}

		this.attributes.devices.push(addedDevice)
	},

	/**
	 * Wrapper for MediaDevices.getUserMedia to use the selected audio and video
	 * input devices.
	 *
	 * The selected audio and video input devices are used only if the
	 * constraints do not specify a device already. Otherwise the devices in the
	 * constraints are respected.
	 *
	 * For compatibility with older browsers "finally" should not be used on the
	 * returned Promise.
	 *
	 * @param {MediaStreamConstraints} constraints the constraints specifying
	 *        the media to request
	 * @returns {Promise} resolved with a MediaStream object when successful, or
	 *          rejected with a DOMException in case of error
	 */
	getUserMedia(constraints) {
		if (!this.isSupported()) {
			return new Promise((resolve, reject) => {
				reject(new DOMException('MediaDevicesManager is not supported', 'NotSupportedError'))
			})
		}

		if (!this._pendingEnumerateDevicesPromise) {
			return this._getUserMediaInternal(constraints)
		}

		return this._pendingEnumerateDevicesPromise.then(() => {
			return this._getUserMediaInternal(constraints)
		}).catch(() => {
			return this._getUserMediaInternal(constraints)
		})
	},

	_getUserMediaInternal(constraints) {
		if (constraints.audio && !constraints.audio.deviceId) {
			if (this.attributes.audioInputId) {
				if (!(constraints.audio instanceof Object)) {
					constraints.audio = {}
				}
				constraints.audio.deviceId = { exact: this.attributes.audioInputId }
			} else if (this.attributes.audioInputId === null) {
				constraints.audio = false
			}
		}

		if (constraints.video && !constraints.video.deviceId) {
			if (this.attributes.videoInputId) {
				if (!(constraints.video instanceof Object)) {
					constraints.video = {}
				}
				constraints.video.deviceId = { exact: this.attributes.videoInputId }
			} else if (this.attributes.videoInputId === null) {
				constraints.video = false
			}
		}

		this._stopIncompatibleTracks(constraints)

		return navigator.mediaDevices.getUserMedia(constraints).then(stream => {
			const videoEffect = new VideoEffects()
			const registeredStream = videoEffect.getBlurredVideoStream(stream)
			this._registerStream(registeredStream)
			// In Firefox the dialog to grant media permissions allows the user
			// to change the device to use, overriding the device that was
			// originally requested.
			this._updateSelectedDevicesFromGetUserMediaResult(stream)

			// The list of devices is always updated when a stream is started as
			// that is the only time at which the full device information is
			// guaranteed to be available.
			this._updateDevices()

			return registeredStream
		}).catch(error => {
			// The list of devices is also updated in case of failure, as even
			// if getting the stream failed the permissions may have been
			// permanently granted.
			this._updateDevices()

			throw error
		})
	},

	_stopIncompatibleTracks(constraints) {
		this._tracks.forEach(track => {
			if (constraints.audio && constraints.audio.deviceId && track.kind === 'audio') {
				const constraintsAudioDeviceId = constraints.audio.deviceId.exact || constraints.audio.deviceId.ideal || constraints.audio.deviceId
				const settings = track.getSettings()
				if (settings && settings.deviceId !== constraintsAudioDeviceId) {
					track.stop()
				}
			}

			if (constraints.video && constraints.video.deviceId && track.kind === 'video') {
				const constraintsVideoDeviceId = constraints.video.deviceId.exact || constraints.video.deviceId.ideal || constraints.video.deviceId
				const settings = track.getSettings()
				if (settings && settings.deviceId !== constraintsVideoDeviceId) {
					track.stop()
				}
			}
		})
	},

	_registerStream(stream) {
		stream.getTracks().forEach(track => {
			this._registerTrack(track)
		})
	},

	_registerTrack(track) {
		this._tracks.push(track)

		track.addEventListener('ended', () => {
			const index = this._tracks.indexOf(track)
			if (index >= 0) {
				this._tracks.splice(index, 1)
			}
		})

		track.addEventListener('cloned', event => {
			this._registerTrack(event.detail)
		})
	},

	_updateSelectedDevicesFromGetUserMediaResult(stream) {
		if (this.attributes.audioInputId) {
			const audioTracks = stream.getAudioTracks()
			const audioTrackSettings = audioTracks.length > 0 ? audioTracks[0].getSettings() : null
			if (audioTrackSettings && audioTrackSettings.deviceId && this.attributes.audioInputId !== audioTrackSettings.deviceId) {
				console.debug('Input audio device overridden in getUserMedia: Expected: ' + this.attributes.audioInputId + ' Found: ' + audioTrackSettings.deviceId)

				this.set('audioInputId', audioTrackSettings.deviceId)
			}
		}

		if (this.attributes.videoInputId) {
			const videoTracks = stream.getVideoTracks()
			const videoTrackSettings = videoTracks.length > 0 ? videoTracks[0].getSettings() : null
			if (videoTrackSettings && videoTrackSettings.deviceId && this.attributes.videoInputId !== videoTrackSettings.deviceId) {
				console.debug('Input video device overridden in getUserMedia: Expected: ' + this.attributes.videoInputId + ' Found: ' + videoTrackSettings.deviceId)

				this.set('videoInputId', videoTrackSettings.deviceId)
			}
		}
	},
}
