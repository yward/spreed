/* global module */

const util = require('util')
const getScreenMedia = require('./getscreenmedia')
const WildEmitter = require('wildemitter')
const mockconsole = require('mockconsole')
// Only mediaDevicesManager is used, but it can not be assigned here due to not
// being initialized yet.
const webrtcIndex = require('../index.js')
const MediaDevicesSource = require('../../media/pipeline/MediaDevicesSource.js').default
const SpeakingMonitor = require('../../media/pipeline/SpeakingMonitor.js').default
const TrackConstrainer = require('../../media/pipeline/TrackConstrainer.js').default
const TrackEnabler = require('../../media/pipeline/TrackEnabler.js').default
const TrackToStream = require('../../media/pipeline/TrackToStream.js').default
const VirtualBackground = require('../../media/pipeline/VirtualBackground.js').default

/**
 * @param {object} opts the options object.
 */
function LocalMedia(opts) {
	WildEmitter.call(this)

	const config = this.config = {
		audioFallback: false,
		logger: mockconsole,
	}

	let item
	for (item in opts) {
		if (Object.prototype.hasOwnProperty.call(opts, item)) {
			this.config[item] = opts[item]
		}
	}

	this.logger = config.logger
	this._log = this.logger.log.bind(this.logger, 'LocalMedia:')
	this._logerror = this.logger.error.bind(this.logger, 'LocalMedia:')

	this._localMediaActive = false

	this.localStreams = []
	this.localScreens = []

	if (!webrtcIndex.mediaDevicesManager.isSupported()) {
		this._logerror('Your browser does not support local media capture.')
	}

	this._mediaDevicesSource = new MediaDevicesSource()

	this._audioTrackEnabler = new TrackEnabler()
	this._videoTrackEnabler = new TrackEnabler()

	this._videoTrackConstrainer = new TrackConstrainer()

	this._virtualBackground = new VirtualBackground()
	this._virtualBackground.on('loadFailed', () => {
		this.emit('virtualBackgroundLoadFailed')
	})

	this._speakingMonitor = new SpeakingMonitor()
	this._speakingMonitor.on('speaking', () => {
		this.emit('speaking')
	})
	this._speakingMonitor.on('speakingWhileMuted', () => {
		this.emit('speakingWhileMuted')
	})
	this._speakingMonitor.on('stoppedSpeaking', () => {
		this.emit('stoppedSpeaking')
	})
	this._speakingMonitor.on('stoppedSpeakingWhileMuted', () => {
		this.emit('stoppedSpeakingWhileMuted')
	})
	this._speakingMonitor.on('volumeChange', (speakingMonitor, volume, threshold) => {
		this.emit('volumeChange', volume, threshold)
	})

	this._trackToStream = new TrackToStream()
	this._trackToStream.addInputTrackSlot('audio')
	this._trackToStream.addInputTrackSlot('video')

	this._handleStreamSetBound = this._handleStreamSet.bind(this)
	this._handleTrackReplacedBound = this._handleTrackReplaced.bind(this)
	this._handleTrackEnabledBound = this._handleTrackEnabled.bind(this)

	this._mediaDevicesSource.connectTrackSink('audio', this._audioTrackEnabler)
	this._mediaDevicesSource.connectTrackSink('video', this._videoTrackEnabler)

	this._audioTrackEnabler.connectTrackSink('default', this._speakingMonitor)
	this._audioTrackEnabler.connectTrackSink('default', this._trackToStream, 'audio')

	this._videoTrackEnabler.connectTrackSink('default', this._videoTrackConstrainer)

	this._videoTrackConstrainer.connectTrackSink('default', this._virtualBackground)

	this._virtualBackground.connectTrackSink('default', this._trackToStream, 'video')
}

util.inherits(LocalMedia, WildEmitter)

/**
 * Returns whether the local media is active or not.
 *
 * The local media is active if it has been started and not stopped yet, even if
 * no media was available when started. An active local media will automatically
 * react to changes in the selected media devices.
 *
 * @return {boolean} true if the local media is active, false otherwise
 */
LocalMedia.prototype.isLocalMediaActive = function() {
	return this._localMediaActive
}

LocalMedia.prototype.hasAudioTrack = function() {
	return this._trackToStream.getStream() && this._trackToStream.getStream().getAudioTracks().length > 0
}

LocalMedia.prototype.hasVideoTrack = function() {
	return this._trackToStream.getStream() && this._trackToStream.getStream().getVideoTracks().length > 0
}

LocalMedia.prototype.start = function(mediaConstraints, cb, context) {
	const self = this
	const constraints = mediaConstraints || { audio: true, video: true }

	if (constraints.audio) {
		this.allowAudio()
	} else {
		this.disallowAudio()
	}
	if (constraints.video) {
		this.allowVideo()
	} else {
		this.disallowVideo()
	}

	// If local media is started with neither audio nor video the local media
	// will not be active (it will not react to changes in the selected media
	// devices). It is just a special case in which starting succeeds with a
	// null stream.
	if (!constraints.audio && !constraints.video) {
		self.emit('localStream', null)

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

	this.emit('localStreamRequested', context)

	const retryNoVideoCallback = (error) => {
		self.emit('localStreamRequestFailedRetryNoVideo', error)
	}

	this._mediaDevicesSource.start(retryNoVideoCallback).then(() => {
		self.localStreams.push(self._trackToStream.getStream())

		self.emit('localStream', self._trackToStream.getStream())

		self._trackToStream.on('streamSet', self._handleStreamSetBound)
		self._trackToStream.on('trackReplaced', self._handleTrackReplacedBound)
		self._trackToStream.on('trackEnabled', self._handleTrackEnabledBound)

		self._localMediaActive = true

		if (cb) {
			const actualConstraints = {
				audio: self._trackToStream.getStream().getAudioTracks().length > 0,
				video: self._trackToStream.getStream().getVideoTracks().length > 0,
			}

			return cb(null, self._trackToStream.getStream(), actualConstraints)
		}
	}).catch(err => {
		self.emit('localStreamRequestFailed')

		self._trackToStream.on('streamSet', self._handleStreamSetBound)
		self._trackToStream.on('trackReplaced', self._handleTrackReplacedBound)
		self._trackToStream.on('trackEnabled', self._handleTrackEnabledBound)

		self._localMediaActive = true

		if (cb) {
			return cb(err, null)
		}
	})
}

LocalMedia.prototype._handleStreamSet = function(trackToStream, newStream, oldStream) {
	if (oldStream) {
		this._removeStream(oldStream)
	}

	if (newStream) {
		this.localStreams.push(newStream)
	}

	// "streamSet" is always emitted along with "trackReplaced", so the
	// "localStreamChanged" only needs to be relayed on "trackReplaced".
}

LocalMedia.prototype._handleTrackReplaced = function(trackToStream, newTrack, oldTrack) {
	// "localStreamChanged" is expected to be emitted also when the tracks of
	// the stream change, even if the stream itself is the same.
	this.emit('localStreamChanged', trackToStream.getStream())
	this.emit('localTrackReplaced', newTrack, oldTrack, trackToStream.getStream())
}

LocalMedia.prototype._handleTrackEnabled = function(trackToStream, track) {
	// MediaStreamTrack does not emit an event when the enabled property
	// changes, so it needs to be explicitly notified.
	this.emit('localTrackEnabledChanged', track, trackToStream.getStream())
}

LocalMedia.prototype.stop = function() {
	// Handlers need to be removed before stopping the stream to prevent
	// relaying no longer needed events.
	this._trackToStream.off('streamSet', this._handleStreamSetBound)
	this._trackToStream.off('trackReplaced', this._handleTrackReplacedBound)
	this._trackToStream.off('trackEnabled', this._handleTrackEnabledBound)

	this.stopStream()
	this.stopScreenShare()

	this._localMediaActive = false
}

LocalMedia.prototype.stopStream = function() {
	const stream = this._trackToStream.getStream()

	this._mediaDevicesSource.stop()

	if (stream) {
		this._removeStream(stream)
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

LocalMedia.prototype.stopScreenShare = function() {
	const self = this

	this.localScreens.forEach(function(stream) {
		stream.getTracks().forEach(function(track) { track.stop() })
		self._removeStream(stream)
	})
}

// Audio controls
LocalMedia.prototype.isAudioAllowed = function() {
	return this._mediaDevicesSource.isAudioAllowed()
}

LocalMedia.prototype.disallowAudio = function() {
	this._mediaDevicesSource.setAudioAllowed(false)
	this.emit('audioDisallowed')
}

LocalMedia.prototype.allowAudio = function() {
	this._mediaDevicesSource.setAudioAllowed(true)
	this.emit('audioAllowed')
}

LocalMedia.prototype.mute = function() {
	this._setAudioEnabled(false)
	this.emit('audioOff')
}

LocalMedia.prototype.unmute = function() {
	this._setAudioEnabled(true)
	this.emit('audioOn')
}

// Video controls
LocalMedia.prototype.isVideoAllowed = function() {
	return this._mediaDevicesSource.isVideoAllowed()
}

LocalMedia.prototype.disallowVideo = function() {
	this._mediaDevicesSource.setVideoAllowed(false)
	this.emit('videoDisallowed')
}

LocalMedia.prototype.allowVideo = function() {
	this._mediaDevicesSource.setVideoAllowed(true)
	this.emit('videoAllowed')
}

LocalMedia.prototype.pauseVideo = function() {
	this._setVideoEnabled(false)
	this.emit('videoOff')
}
LocalMedia.prototype.resumeVideo = function() {
	this._setVideoEnabled(true)
	this.emit('videoOn')
}

LocalMedia.prototype.enableVirtualBackground = function() {
	this._virtualBackground.setEnabled(true)
	this.emit('virtualBackgroundOn')
}

LocalMedia.prototype.disableVirtualBackground = function() {
	this._virtualBackground.setEnabled(false)
	this.emit('virtualBackgroundOff')
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
	this._audioTrackEnabler.setEnabled(bool)
}
LocalMedia.prototype._setVideoEnabled = function(bool) {
	this._videoTrackEnabler.setEnabled(bool)
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

LocalMedia.prototype.isVirtualBackgroundAvailable = function() {
	return this._virtualBackground.isAvailable()
}

LocalMedia.prototype.isVirtualBackgroundEnabled = function() {
	return this._virtualBackground.isEnabled()
}

LocalMedia.prototype._removeStream = function(stream) {
	let idx = this.localStreams.indexOf(stream)
	if (idx > -1) {
		this.localStreams.splice(idx, 1)
		this.emit('localStreamStopped', stream)
	} else {
		idx = this.localScreens.indexOf(stream)
		if (idx > -1) {
			this.localScreens.splice(idx, 1)
			this.emit('localScreenStopped', stream)
		}
	}
}

// fallback for old .localScreen behaviour
Object.defineProperty(LocalMedia.prototype, 'localScreen', {
	get() {
		return this.localScreens.length > 0 ? this.localScreens[0] : null
	},
})

module.exports = LocalMedia
