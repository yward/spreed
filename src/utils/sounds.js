/**
 * @copyright Copyright (c) 2020 Joas Schilling <coding@schilljs.com>
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

import { generateFilePath } from '@nextcloud/router'
import store from '../store'

export const Sounds = {
	BLOCK_SOUND_TIMEOUT: 3000,

	isInCall: false,
	lastPlayedJoin: 0,
	lastPlayedLeave: 0,
	playedWaiting: 0,
	backgroundAudio: null,
	backgroundInterval: null,

	_playSoundOnce(soundFile) {
		const file = generateFilePath('spreed', 'img', soundFile)
		const audio = new Audio(file)
		audio.volume = 0.75
		audio.play()
	},

	_stopWaiting() {
		console.debug('Stop waiting sound')
		if (this.backgroundAudio) {
			this.backgroundAudio.pause()
			this.backgroundAudio = null
		}
		clearInterval(this.backgroundInterval)
	},

	async playWaiting() {
		if (!store.getters.playSounds) {
			return
		}

		if (!this.backgroundAudio) {
			console.debug('Loading waiting sound')
			const file = generateFilePath('spreed', 'img', 'LibremPhoneCall.ogg')
			this.backgroundAudio = new Audio(file)
			this.backgroundAudio.volume = 0.5
		}

		console.debug('Playing waiting sound')
		this.backgroundAudio.play()

		this.playedWaiting = 0
		this.backgroundInterval = setInterval(() => {
			if (!store.getters.playSounds) {
				this._stopWaiting()
				return
			}

			if (this.playedWaiting >= 3) {
				// Played 3 times, so we stop now.
				this._stopWaiting()
				return
			}

			console.debug('Playing waiting sound')
			this.backgroundAudio.play()
			this.playedWaiting++

		}, 15000)
	},

	async playJoin(force, playWaitingSound) {
		this._stopWaiting()

		if (!store.getters.playSounds) {
			return
		}

		if (force) {
			this.isInCall = true
		} else if (!this.isInCall) {
			return
		}

		const currentTime = (new Date()).getTime()
		if (!force && this.lastPlayedJoin >= (currentTime - this.BLOCK_SOUND_TIMEOUT)) {
			if (this.lastPlayedJoin >= (currentTime - this.BLOCK_SOUND_TIMEOUT)) {
				console.debug('Skipping join sound because it was played %.2f seconds ago', currentTime - this.lastPlayedJoin)
			}
			return
		}

		if (force) {
			console.debug('Playing join sound because of self joining')
		} else {
			this.lastPlayedJoin = currentTime
			console.debug('Playing join sound')
		}

		if (playWaitingSound) {
			await this.playWaiting()
		} else {
			this._playSoundOnce('join_call.ogg')
		}
	},

	async playLeave(force, playWaitingSound) {
		this._stopWaiting()

		if (!store.getters.playSounds) {
			return
		}

		if (!this.isInCall) {
			return
		}

		const currentTime = (new Date()).getTime()
		if (!force && this.lastPlayedLeave >= (currentTime - this.BLOCK_SOUND_TIMEOUT)) {
			if (this.lastPlayedLeave >= (currentTime - this.BLOCK_SOUND_TIMEOUT)) {
				console.debug('Skipping leave sound because it was played %f.2 seconds ago', currentTime - this.lastPlayedLeave)
			}
			return
		}

		if (force) {
			console.debug('Playing leave sound because of self leaving')
			this.isInCall = false
		} else {
			console.debug('Playing leave sound')
		}
		this.lastPlayedLeave = currentTime

		this._playSoundOnce('leave_call.ogg')

		if (playWaitingSound) {
			this.playWaiting()
		}
	},
}
