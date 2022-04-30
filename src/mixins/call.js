/**
 * @copyright Copyright (c) 2019 Marco Ambrosini <marcoambrosini@pm.me>
 *
 * @author Marco Ambrosini <marcoambrosini@pm.me>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */
import { localMediaModel, localCallParticipantModel, callParticipantCollection } from '../utils/webrtc/index'

const call = {

	data() {
		return {
			screens: [],
			localMediaModel,
			localCallParticipantModel,
			sharedDatas: {},
			speakingUnwatchers: {},
			screenUnwatchers: {},
			speakers: [],
			// callParticipantModelsWithScreen: [],
			localSharedData: {
				screenVisible: true,
			},
			callParticipantCollection,
		}
	},

	props: {
		token: {
			type: String,
			required: true,
		},
	},

	methods: {

		/**
		 * Updates data properties that depend on the CallParticipantModels.
		 *
		 * The data contains some properties that can not be dynamically
		 * computed but that depend on the current CallParticipantModels, so
		 * this function adds and removes elements and watchers as needed based
		 * on the given CallParticipantModels.
		 *
		 * @param {Array} models the array of CallParticipantModels
		 */
		updateDataFromCallParticipantModels(models) {
			const addedModels = models.filter(model => !this.sharedDatas[model.attributes.peerId])
			const removedModelIds = Object.keys(this.sharedDatas).filter(sharedDataId => models.find(model => model.attributes.peerId === sharedDataId) === undefined)

			removedModelIds.forEach(removedModelId => {
				this.$delete(this.sharedDatas, removedModelId)

				this.speakingUnwatchers[removedModelId]()
				// Not reactive, but not a problem
				delete this.speakingUnwatchers[removedModelId]

				this.screenUnwatchers[removedModelId]()
				// Not reactive, but not a problem
				delete this.screenUnwatchers[removedModelId]

				const index = this.speakers.findIndex(speaker => speaker.id === removedModelId)
				this.speakers.splice(index, 1)

				this._setScreenAvailable(removedModelId, false)
			})

			addedModels.forEach(addedModel => {
				const sharedData = {
					promoted: false,
					videoEnabled: true,
					screenVisible: false,
				}

				this.$set(this.sharedDatas, addedModel.attributes.peerId, sharedData)

				// Not reactive, but not a problem
				this.speakingUnwatchers[addedModel.attributes.peerId] = this.$watch(function() {
					return addedModel.attributes.speaking
				}, function(speaking) {
					this._setSpeaking(addedModel.attributes.peerId, speaking)
				})

				this.speakers.push({
					id: addedModel.attributes.peerId,
					active: false,
				})

				// Not reactive, but not a problem
				this.screenUnwatchers[addedModel.attributes.peerId] = this.$watch(function() {
					return addedModel.attributes.screen
				}, function(screen) {
					this._setScreenAvailable(addedModel.attributes.peerId, screen)
				})
			})
		},

		_setSpeaking(peerId, speaking) {
			if (speaking) {
				// Move the speaker to the first element of the list
				const index = this.speakers.findIndex(speaker => speaker.id === peerId)
				const speaker = this.speakers[index]
				speaker.active = true
				this.speakers.splice(index, 1)
				this.speakers.unshift(speaker)

				return
			}

			// Set the speaker as not speaking
			const index = this.speakers.findIndex(speaker => speaker.id === peerId)
			const speaker = this.speakers[index]
			speaker.active = false

			// Move the speaker after all the active speakers
			if (index === 0) {
				this.speakers.shift()

				const firstInactiveSpeakerIndex = this.speakers.findIndex(speaker => !speaker.active)
				if (firstInactiveSpeakerIndex === -1) {
					this.speakers.push(speaker)
				} else {
					this.speakers.splice(firstInactiveSpeakerIndex, 0, speaker)
				}
			}
		},

		_setScreenAvailable(id, screen) {
			if (screen) {
				this.screens.unshift(id)

				return
			}

			const index = this.screens.indexOf(id)
			if (index !== -1) {
				this.screens.splice(index, 1)
			}
		},

		_setPromotedParticipant() {
			Object.values(this.sharedDatas).forEach(sharedData => {
				sharedData.promoted = false
			})

			if (!this.screenSharingActive && this.speakers.length) {
				this.sharedDatas[this.speakers[0].id].promoted = true
			}
		},

		_switchScreenToId(id) {
			const index = this.screens.indexOf(id)
			if (index === -1) {
				return
			}

			this.screens.splice(index, 1)
			this.screens.unshift(id)
		},

		_setScreenVisible() {
			this.localSharedData.screenVisible = false

			Object.values(this.sharedDatas).forEach(sharedData => {
				sharedData.screenVisible = false
			})

			if (!this.screens.length) {
				return
			}

			if (this.screens[0] === this.localCallParticipantModel.attributes.peerId) {
				this.localSharedData.screenVisible = true

				return
			}

			this.sharedDatas[this.screens[0]].screenVisible = true
		},
	},

	watch: {

		localScreen(localScreen) {
			this._setScreenAvailable(localCallParticipantModel.attributes.peerId, localScreen)
		},

		callParticipantModels(models) {
			this.updateDataFromCallParticipantModels(models)
		},

		speakers() {
			this._setPromotedParticipant()
		},

		screenSharingActive() {
			this._setPromotedParticipant()
		},

		screens() {
			this._setScreenVisible()
		},

	},

	computed: {
		callParticipantModels() {
			return callParticipantCollection.callParticipantModels
		},

		reversedCallParticipantModels() {
			return this.callParticipantModels.slice().reverse()
		},

		remoteParticipantsCount() {
			return this.callParticipantModels.length
		},

		callParticipantModelsWithScreen() {
			return this.callParticipantModels.filter(callParticipantModel => callParticipantModel.attributes.screen)
		},

		localScreen() {
			return localMediaModel.attributes.localScreen
		},

		screenSharingActive() {
			return this.screens.length > 0
		},
	},
}

export default call

export { localMediaModel, localCallParticipantModel, callParticipantCollection }
