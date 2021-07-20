import * as bodyPix from '@tensorflow-models/body-pix'
// import * as tf from '@tensorflow/tfjs'
import '@tensorflow/tfjs'

export default function VideoEffects() {
	this._videoSource = document.createElement('video')
	this._videoSource.muted = 'muted'
	this._temporaryCanvas = document.createElement('canvas')
	this._playing = false
	this._videoSource.addEventListener('loadeddata', (this._videoSourceListener.bind(this)))
}

VideoEffects.prototype = {
	getBlurredVideoStream(stream) {
		this._stream = stream
		this._videoSource.height = this._stream.getVideoTracks()[0].getSettings().height
		this._videoSource.width = this._stream.getVideoTracks()[0].getSettings().width
		this._temporaryCanvas.height = this._stream.getVideoTracks()[0].getSettings().height
		this._temporaryCanvas.width = this._stream.getVideoTracks()[0].getSettings().width
		this._videoSource.srcObject = this._stream
		this._videoSource.play()
		this._playing = true
		this._canvasBlurredStream = this._temporaryCanvas.captureStream()
		let extractedAudio = false
		extractedAudio = this._stream.getTracks().filter(function(track) {
			return track.kind === 'audio'
		})[0]
		if (extractedAudio) {
			this._canvasBlurredStream.addTrack(extractedAudio)
		}
		this._stopStreamBound = this._stopStream.bind(this)
		// mainStreamEnded is sent in
		this._canvasBlurredStream.addEventListener('mainStreamEnded', this._stopStreamBound)
		return this._canvasBlurredStream
	},

	_stopStream() {
		this._playing = false
		this._videoSource.removeEventListener('loadeddata', this._videoSourceListener)
		const tracks = this._stream.getTracks()
		tracks.forEach(track => {
			track.stop()
		})
		this._stream = null
	},

	_loadBodyPix() {

		const options = {
			architecture: 'MobileNetV1',
			multiplier: 0.5,
			outputStride: 16,
			// stride: 32,
			quantBytes: 2,
			internalResolution: 'low',
		}
		bodyPix.load(options)
			.then(net => this._perform(net))
			.catch(err => {
				throw err
			})
	},

	async _perform(net) {

		while (this._playing === true) {
			const segmentation = await net.segmentPerson(this._videoSource, {
				flipHorizontal: false,
				internalResolution: 'low',
				segmentationThreshold: 0.7,
			})
			const backgroundBlurAmount = 15
			const edgeBlurAmount = 2
			const flipHorizontal = false
			bodyPix.drawBokehEffect(
				this._temporaryCanvas, this._videoSource, segmentation, backgroundBlurAmount,
				edgeBlurAmount, flipHorizontal)
		}
	},

	_videoSourceListener(e) {
		this._loadBodyPix()
	},

	_stream: null,

	_canvasBlurredStream: null,

	_context: null,

	_stopBound: null,

}
