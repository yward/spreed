/**
 * @copyright Copyright (c) 2019 Marco Ambrosini <marcoambrosini@pm.me>
 *
 * @author Marco Ambrosini <marcoambrosini@pm.me>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

const state = {
	show: true,
	chat: false,
	unread: false,
}

const getters = {
	getSidebarStatus: (state) => () => {
		return state.show
	},
	isChatInSidebar: (state) => () => {
		return state.chat
	},
	hiddenChatHasUnreadMessages: (state) => () => {
		return !state.show && state.chat && state.unread
	},
}

const mutations = {
	/**
	 * Shows the sidebar
	 *
	 * @param {object} state current store state;
	 */
	showSidebar(state) {
		state.show = true
	},
	/**
	 * Hides the sidebar
	 *
	 * @param {object} state current store state;
	 */
	hideSidebar(state) {
		state.show = false
	},

	/**
	 * Sets the current visibility state of the chat
	 *
	 * @param {object} state current store state;
	 * @param {boolean} value the value;
	 */
	setChatInSidebar(state, value) {
		state.chat = value
	},

	/**
	 * @param {object} state current store state;
	 * @param {boolean} value the value;
	 */
	setHasUnreadMessages(state, value) {
		state.unread = value
	},
}

const actions = {

	/**
	 * Shows the sidebar
	 *
	 * @param {object} context default store context;
	 */
	showSidebar(context) {
		context.commit('showSidebar')
		context.commit('setHasUnreadMessages', false)
	},
	/**
	 * Hides the sidebar
	 *
	 * @param {object} context default store context;
	 */
	hideSidebar(context) {
		context.commit('hideSidebar')
	},

	/**
	 * Sets the current visibility state of the chat
	 *
	 * @param {object} context the context object;
	 * @param {boolean} value the value;
	 */
	setChatInSidebar(context, value) {
		context.commit('setChatInSidebar', value)
		context.commit('setHasUnreadMessages', false)
	},

	/**
	 * @param {object} context the context object;
	 * @param {boolean} value the value;
	 */
	setHasUnreadMessages(context, value) {
		context.commit('setHasUnreadMessages', value)
	},
}

export default { state, mutations, getters, actions }
