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

import Vue from 'vue'
import client from '../services/DavClient'
import { showError } from '@nextcloud/dialogs'
import fromStateOr from './helper'
import { findUniquePath, getFileExtension } from '../utils/fileUpload'
import moment from '@nextcloud/moment'
import { EventBus } from '../services/EventBus'
import { shareFile } from '../services/filesSharingServices'
import { setAttachmentFolder } from '../services/settingsService'

const state = {
	attachmentFolder: fromStateOr('spreed', 'attachment_folder', ''),
	attachmentFolderFreeSpace: fromStateOr('spreed', 'attachment_folder_free_space', 0),
	uploads: {
	},
	currentUploadId: undefined,
}

const getters = {

	getInitialisedUploads: (state) => (uploadId) => {
		if (state.uploads[uploadId]) {
			const initialisedUploads = {}
			for (const index in state.uploads[uploadId].files) {
				const currentFile = state.uploads[uploadId].files[index]
				if (currentFile.status === 'initialised') {
					initialisedUploads[index] = (currentFile)
				}
			}
			return initialisedUploads
		} else {
			return {}
		}
	},

	// Returns all the files that have been successfully uploaded provided an
	// upload id
	getShareableFiles: (state) => (uploadId) => {
		if (state.uploads[uploadId]) {
			const shareableFiles = {}
			for (const index in state.uploads[uploadId].files) {
				const currentFile = state.uploads[uploadId].files[index]
				if (currentFile.status === 'successUpload') {
					shareableFiles[index] = (currentFile)
				}
			}
			return shareableFiles
		} else {
			return {}
		}
	},

	// gets the current attachment folder
	getAttachmentFolder: (state) => () => {
		return state.attachmentFolder
	},

	// gets the current attachment folder
	getAttachmentFolderFreeSpace: (state) => () => {
		return state.attachmentFolderFreeSpace
	},

	uploadProgress: (state) => (uploadId, index) => {
		if (state.uploads[uploadId].files[index]) {
			return state.uploads[uploadId].files[index].uploadedSize / state.uploads[uploadId].files[index].totalSize * 100
		} else {
			return 0
		}
	},

	currentUploadId: (state) => {
		return state.currentUploadId
	},
}

const mutations = {

	// Adds a "file to be shared to the store"
	addFileToBeUploaded(state, { file, temporaryMessage }) {
		const uploadId = temporaryMessage.messageParameters.file.uploadId
		const token = temporaryMessage.messageParameters.file.token
		const index = temporaryMessage.messageParameters.file.index
		// Create upload id if not present
		if (!state.uploads[uploadId]) {
			Vue.set(state.uploads, uploadId, {
				token,
				files: {},
			})
		}
		Vue.set(state.uploads[uploadId].files, index, {
			file,
			status: 'initialised',
			totalSize: file.size,
			uploadedSize: 0,
			temporaryMessage,
		 })
	},

	// Marks a given file as failed upload
	markFileAsFailedUpload(state, { uploadId, index, status }) {
		state.uploads[uploadId].files[index].status = 'failedUpload'
	},

	// Marks a given file as uploaded
	markFileAsSuccessUpload(state, { uploadId, index, sharePath }) {
		state.uploads[uploadId].files[index].status = 'successUpload'
		Vue.set(state.uploads[uploadId].files[index], 'sharePath', sharePath)
	},

	// Marks a given file as uploading
	markFileAsUploading(state, { uploadId, index }) {
		state.uploads[uploadId].files[index].status = 'uploading'
	},

	// Marks a given file as sharing
	markFileAsSharing(state, { uploadId, index }) {
		state.uploads[uploadId].files[index].status = 'sharing'
	},

	// Marks a given file as shared
	markFileAsShared(state, { uploadId, index }) {
		state.uploads[uploadId].files[index].status = 'shared'
	},

	/**
	 * Set the attachmentFolder
	 *
	 * @param {object} state current store state;
	 * @param {string} attachmentFolder The new target location for attachments
	 */
	setAttachmentFolder(state, attachmentFolder) {
		state.attachmentFolder = attachmentFolder
	},

	// Sets uploaded amount of bytes
	setUploadedSize(state, { uploadId, index, uploadedSize }) {
		state.uploads[uploadId].files[index].uploadedSize = uploadedSize
	},

	// Set temporary message for each file
	setTemporaryMessageForFile(state, { uploadId, index, temporaryMessage }) {
		console.debug('uploadId: ' + uploadId + ' index: ' + index)
		Vue.set(state.uploads[uploadId].files[index], 'temporaryMessage', temporaryMessage)
	},

	// Sets the id of the current upload operation
	setCurrentUploadId(state, currentUploadId) {
		state.currentUploadId = currentUploadId
	},

	removeFileFromSelection(state, temporaryMessageId) {
		const uploadId = state.currentUploadId
		for (const key in state.uploads[uploadId].files) {
			if (state.uploads[uploadId].files[key].temporaryMessage.id === temporaryMessageId) {
				Vue.delete(state.uploads[uploadId].files, key)
			}
		}
	},

	discardUpload(state, { uploadId }) {
		Vue.delete(state.uploads, uploadId)
	},
}

const actions = {

	/**
	 * Initialises uploads and shares files to a conversation
	 *
	 * @param {object} context the wrapping object.
	 * @param {Function} context.commit the contexts commit function.
	 * @param {Function} context.dispatch the contexts dispatch function.
	 * @param {object} data the wrapping object;
	 * @param {object} data.files the files to be processed
	 * @param {string} data.token the conversation's token where to share the files
	 * @param {number} data.uploadId a unique id for the upload operation indexing
	 * @param {boolean} data.rename whether to rename the files (usually after pasting)
	 * @param {boolean} data.isVoiceMessage whether the file is a voice recording
	 */
	async initialiseUpload({ commit, dispatch }, { uploadId, token, files, rename = false, isVoiceMessage }) {
		// Set last upload id
		commit('setCurrentUploadId', uploadId)

		for (let i = 0; i < files.length; i++) {
			const file = files[i]

			if (rename) {
				// note: can't overwrite the original read-only name attribute
				file.newName = moment(file.lastModified || file.lastModifiedDate).format('YYYYMMDD_HHmmss')
					+ getFileExtension(file.name)
			}

			// Get localurl for some image previews
			let localUrl = ''
			if (file.type === 'image/png' || file.type === 'image/gif' || file.type === 'image/jpeg') {
				localUrl = URL.createObjectURL(file)
			} else if (isVoiceMessage) {
				localUrl = file.localUrl
			} else {
				localUrl = OC.MimeType.getIconUrl(file.type)
			}
			// Create a unique index for each file
			const date = new Date()
			const index = 'temp_' + date.getTime() + Math.random()
			// Create temporary message for the file and add it to the message list
			const temporaryMessage = await dispatch('createTemporaryMessage', {
				text: '{file}', token, uploadId, index, file, localUrl, isVoiceMessage,
			})
			console.debug('temporarymessage: ', temporaryMessage, 'uploadId', uploadId)
			commit('addFileToBeUploaded', { file, temporaryMessage })
		}
	},

	/**
	 * Discards an upload
	 *
	 * @param {object} context the wrapping object.
	 * @param {Function} context.commit the contexts commit function.
	 * @param {object} context.state the contexts state object.
	 * @param {object} uploadId The unique uploadId
	 */
	discardUpload({ commit, state }, uploadId) {
		if (state.currentUploadId === uploadId) {
			commit('setCurrentUploadId', undefined)
		}

		commit('discardUpload', { uploadId })
	},

	/**
	 * Uploads the files to the root directory of the user
	 *
	 * @param {object} context the wrapping object.
	 * @param {Function} context.commit the contexts commit function.
	 * @param {Function} context.dispatch the contexts dispatch function.
	 * @param {object} context.getters the contexts getters object.
	 * @param {object} context.state the contexts state object.
	 * @param {string} uploadId The unique uploadId
	 */
	async uploadFiles({ commit, dispatch, state, getters }, uploadId) {
		if (state.currentUploadId === uploadId) {
			commit('setCurrentUploadId', undefined)
		}

		EventBus.$emit('upload-start')

		// Tag the previously indexed files and add the temporary messages to the
		// messages list
		for (const index in state.uploads[uploadId].files) {
			// mark all files as uploading
			commit('markFileAsUploading', { uploadId, index })
			// Store the previously created temporary message
			const temporaryMessage = state.uploads[uploadId].files[index].temporaryMessage
			// Add temporary messages (files) to the messages list
			dispatch('addTemporaryMessage', temporaryMessage)
			// Scroll the message list
			EventBus.$emit('scroll-chat-to-bottom')
		}
		// Iterate again and perform the uploads
		for (const index in state.uploads[uploadId].files) {
			// currentFile to be uploaded
			const currentFile = state.uploads[uploadId].files[index].file
			// userRoot path
			const userRoot = '/files/' + getters.getUserId()
			const fileName = (currentFile.newName || currentFile.name)
			// Candidate rest of the path
			const path = getters.getAttachmentFolder() + '/' + fileName
			// Get a unique relative path based on the previous path variable
			const uniquePath = await findUniquePath(client, userRoot, path)
			try {
				// Upload the file
				await client.putFileContents(userRoot + uniquePath, currentFile, {
					onUploadProgress: progress => {
						const uploadedSize = progress.loaded
						commit('setUploadedSize', { state, uploadId, index, uploadedSize })
					},
					contentLength: currentFile.size,
				})
				// Path for the sharing request
				const sharePath = '/' + uniquePath
				// Mark the file as uploaded in the store
				commit('markFileAsSuccessUpload', { uploadId, index, sharePath })
			} catch (exception) {
				let reason = 'failed-upload'
				if (exception.response) {
					console.error(`Error while uploading file "${fileName}":` + exception, fileName, exception.response.status)
					if (exception.response.status === 507) {
						reason = 'quota'
						showError(t('spreed', 'Not enough free space to upload file "{fileName}"', { fileName }))
					} else {
						showError(t('spreed', 'Error while uploading file "{fileName}"', { fileName }))
					}
				} else {
					console.error(`Error while uploading file "${fileName}":` + exception.message, fileName)
					showError(t('spreed', 'Error while uploading file "{fileName}"', { fileName }))
				}

				const temporaryMessage = state.uploads[uploadId].files[index].temporaryMessage
				// Mark the upload as failed in the store
				commit('markFileAsFailedUpload', { uploadId, index })
				dispatch('markTemporaryMessageAsFailed', {
					message: temporaryMessage,
					reason,
				})
			}

			// Get the files that have successfully been uploaded from the store
			const shareableFiles = getters.getShareableFiles(uploadId)
			// Share each of those files to the conversation
			for (const index in shareableFiles) {
				const path = shareableFiles[index].sharePath
				const temporaryMessage = shareableFiles[index].temporaryMessage
				const metadata = JSON.stringify({ messageType: temporaryMessage.messageType })
				try {
					const token = temporaryMessage.token
					dispatch('markFileAsSharing', { uploadId, index })
					await shareFile(path, token, temporaryMessage.referenceId, metadata)
					dispatch('markFileAsShared', { uploadId, index })
				} catch (error) {
					if (error?.response?.status === 403) {
						showError(t('spreed', 'You are not allowed to share files'))
					} else {
						showError(t('spreed', 'An error happened when trying to share your file'))
					}
					dispatch('markTemporaryMessageAsFailed', {
						message: temporaryMessage,
						reason: 'failed-share',
					})
					console.error('An error happened when trying to share your file: ', error)
				}
			}
		}
		EventBus.$emit('upload-finished')
	},
	/**
	 * Set the folder to store new attachments in
	 *
	 * @param {object} context default store context;
	 * @param {string} attachmentFolder Folder to store new attachments in
	 */
	async setAttachmentFolder(context, attachmentFolder) {
		await setAttachmentFolder(attachmentFolder)
		context.commit('setAttachmentFolder', attachmentFolder)
	},

	/**
	 * Mark a file as shared
	 *
	 * @param {object} context the wrapping object.
	 * @param {Function} context.commit the contexts commit function.
	 * @param {object} context.state the contexts state object.
	 * @param {object} data the wrapping object;
	 * @param {string} data.uploadId The id of the upload process
	 * @param {number} data.index The object index inside the upload process
	 * @throws {Error} when the item is already being shared by another async call
	 */
	markFileAsSharing({ commit, state }, { uploadId, index }) {
		if (state.uploads[uploadId].files[index].status !== 'successUpload') {
			throw new Error('Item is already being shared')
		}
		commit('markFileAsSharing', { uploadId, index })
	},

	/**
	 * Mark a file as shared
	 *
	 * @param {object} context default store context;
	 * @param {object} data the wrapping object;
	 * @param {string} data.uploadId The id of the upload process
	 * @param {number} data.index The object index inside the upload process
	 */
	markFileAsShared(context, { uploadId, index }) {
		context.commit('markFileAsShared', { uploadId, index })
	},

	/**
	 * Mark a file as shared
	 *
	 * @param {object} context the wrapping object.
	 * @param {Function} context.commit the contexts commit function.
	 * @param {string} temporaryMessageId message id of the temporary message associated to the file to remove
	 */
	removeFileFromSelection({ commit }, temporaryMessageId) {
		commit('removeFileFromSelection', temporaryMessageId)
	},

}

export default { state, mutations, getters, actions }
