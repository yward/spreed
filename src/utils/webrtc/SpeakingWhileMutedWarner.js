/**
 *
 * @copyright Copyright (c) 2019, Daniel Calviño Sánchez (danxuliu@gmail.com)
 *
 * @license AGPL-3.0-or-later
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

/**
	* Helper to warn the user if she is talking while muted.
	*
	* The WebRTC helper emits events when it detects that the user is speaking
	* while muted; this helper shows a warning to the user based on those
	* events.
	*
	* The warning is not immediately shown, though; the WebRTC helper flags
	* even short sounds as "speaking" (provided they are strong enough), so to
	* prevent unnecesary warnings the user has to speak for a few seconds for
	* the warning to be shown. On the other hand, the warning is hidden as soon
	* as the WebRTC helper detects that the speaking has stopped; in this case
	* there is no delay, as the helper itself has a delay before emitting the
	* event.
	*
	* The way of warning the user changes depending on whether Talk is visible
	* or not; if it is visible the warning is shown in the Talk UI, but if it
	* is not it is shown using a browser notification, which will be visible
	* to the user even if the browser window is not in the foreground (provided
	* the user granted the permissions to receive notifications from the site).
	*
	* @param {object} LocalMediaModel the model that emits "speakingWhileMuted"
	* events.
	* @param {object} view the view that provides the
	* "setSpeakingWhileMutedNotification" method.
	*/
export default function SpeakingWhileMutedWarner(LocalMediaModel, view) {
	this._model = LocalMediaModel
	this._view = view

	this._handleSpeakingWhileMutedChangeBound = this._handleSpeakingWhileMutedChange.bind(this)

	this._model.on('change:speakingWhileMuted', this._handleSpeakingWhileMutedChangeBound)
}
SpeakingWhileMutedWarner.prototype = {

	destroy() {
		this._model.off('change:speakingWhileMuted', this._handleSpeakingWhileMutedChangeBound)
	},

	_handleSpeakingWhileMutedChange(model, speakingWhileMuted) {
		if (speakingWhileMuted) {
			this._handleSpeakingWhileMuted()
		} else {
			this._handleStoppedSpeakingWhileMuted()
		}
	},

	_handleSpeakingWhileMuted() {
		this._startedSpeakingTimeout = setTimeout(function() {
			delete this._startedSpeakingTimeout

			this._showWarning()
		}.bind(this), 3000)
	},

	_handleStoppedSpeakingWhileMuted() {
		if (this._startedSpeakingTimeout) {
			clearTimeout(this._startedSpeakingTimeout)
			delete this._startedSpeakingTimeout
		}

		this._hideWarning()
	},

	_showWarning() {
		const message = t('spreed', 'You seem to be talking while muted, please unmute yourself for others to hear you')

		if (!document.hidden) {
			this._showNotification(message)
		} else {
			this._pendingBrowserNotification = true

			this._showBrowserNotification(message).catch(function() {
				if (this._pendingBrowserNotification) {
					this._pendingBrowserNotification = false

					this._showNotification(message)
				}
			}.bind(this))
		}
	},

	_showNotification(message) {
		if (this._notification) {
			return
		}

		this._view.setSpeakingWhileMutedNotification(message)
		this._notification = true
	},

	_showBrowserNotification(message) {
		return new Promise(function(resolve, reject) {
			if (this._browserNotification) {
				resolve()

				return
			}

			if (!Notification) {
				// The browser does not support the Notification API.
				reject()

				return
			}

			if (Notification.permission === 'denied') {
				reject()

				return
			}

			if (Notification.permission === 'granted') {
				this._pendingBrowserNotification = false
				this._browserNotification = new Notification(message)
				resolve()

				return
			}

			Notification.requestPermission().then(function(permission) {
				if (permission === 'granted') {
					if (this._pendingBrowserNotification) {
						this._pendingBrowserNotification = false
						this._browserNotification = new Notification(message)
					}
					resolve()
				} else {
					reject()
				}
			}.bind(this))
		}.bind(this))
	},

	_hideWarning() {
		this._pendingBrowserNotification = false

		if (this._notification) {
			this._view.setSpeakingWhileMutedNotification(null)

			this._notification = false
		}

		if (this._browserNotification) {
			this._browserNotification.close()

			this._browserNotification = null
		}
	},

}
