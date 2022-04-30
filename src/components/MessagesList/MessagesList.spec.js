import Vuex from 'vuex'
import { createLocalVue, shallowMount } from '@vue/test-utils'
import { cloneDeep } from 'lodash'
import storeConfig from '../../store/storeConfig'
import { ATTENDEE } from '../../constants'

import MessagesList from './MessagesList'

describe('MessagesList.vue', () => {
	const TOKEN = 'XXTOKENXX'
	let store
	let localVue
	let testStoreConfig
	const messagesListMock = jest.fn()
	const getVisualLastReadMessageIdMock = jest.fn()

	beforeEach(() => {
		localVue = createLocalVue()
		localVue.use(Vuex)

		testStoreConfig = cloneDeep(storeConfig)
		testStoreConfig.modules.messagesStore.getters.messagesList
			= jest.fn().mockReturnValue(messagesListMock)
		testStoreConfig.modules.messagesStore.getters.getVisualLastReadMessageId
			= jest.fn().mockReturnValue(getVisualLastReadMessageIdMock)
		store = new Vuex.Store(testStoreConfig)

		// hack to catch date separators
		const oldTee = global.t
		global.t = jest.fn().mockImplementation(function(pkg, text, data) {
			if (data && data.relativeDate) {
				return data.relativeDate + ', ' + data.absoluteDate
			}
			return oldTee.apply(this, arguments)
		})
	})

	afterEach(() => {
		jest.clearAllMocks()
	})

	describe('message grouping', () => {
		test('groups consecutive messages by author', () => {
			getVisualLastReadMessageIdMock.mockReturnValue(110)
			const messagesGroup1 = [{
				id: 100,
				token: TOKEN,
				actorId: 'alice',
				actorDisplayName: 'Alice',
				actorType: ATTENDEE.ACTOR_TYPE.USERS,
				message: 'hello',
				messageType: 'comment',
				messageParameters: {},
				systemMessage: '',
				timestamp: 100,
				isReplyable: true,
			}, {
				id: 110,
				token: TOKEN,
				actorId: 'alice',
				actorDisplayName: 'Alice',
				actorType: ATTENDEE.ACTOR_TYPE.USERS,
				message: 'how are you ?',
				messageType: 'comment',
				messageParameters: {},
				systemMessage: '',
				timestamp: 200,
				isReplyable: true,
			}]

			const messagesGroup2 = [{
				id: 200,
				token: TOKEN,
				actorId: 'bob',
				actorDisplayName: 'Bob',
				actorType: ATTENDEE.ACTOR_TYPE.USERS,
				message: 'hello!',
				messageType: 'comment',
				messageParameters: {},
				systemMessage: '',
				timestamp: 300,
				isReplyable: true,
			}, {
				id: 210,
				token: TOKEN,
				actorId: 'bob',
				actorDisplayName: 'Bob',
				actorType: ATTENDEE.ACTOR_TYPE.USERS,
				message: 'fine... how abouty you ?',
				messageType: 'comment',
				messageParameters: {},
				systemMessage: '',
				timestamp: 400,
				isReplyable: true,
			}]

			const messagesGroup3 = [{
				id: 300,
				token: TOKEN,
				actorId: 'alice',
				actorDisplayName: 'Alice',
				actorType: ATTENDEE.ACTOR_TYPE.USERS,
				message: 'fine as well, thanks!',
				messageType: 'comment',
				messageParameters: {},
				systemMessage: '',
				timestamp: 0, // temporary
				isReplyable: true,
			}]

			const allMessages = messagesGroup1.concat(messagesGroup2.concat(messagesGroup3))
			messagesListMock.mockReturnValue(allMessages)

			const wrapper = shallowMount(MessagesList, {
				localVue,
				store,
				propsData: {
					token: TOKEN,
					isChatScrolledToBottom: true,
				},
			})

			const groups = wrapper.findAllComponents({ name: 'MessagesGroup' })

			expect(groups.exists()).toBe(true)

			let group = groups.at(0)
			expect(group.props('messages')).toStrictEqual(messagesGroup1)
			expect(group.props('previousMessageId')).toBe(0)
			expect(group.props('nextMessageId')).toBe(200)
			expect(group.props('lastReadMessageId')).toBe(110)
			// using attributes to access v-bind props
			expect(group.attributes('actorid')).toBe('alice')
			expect(group.attributes('actortype')).toBe(ATTENDEE.ACTOR_TYPE.USERS)

			group = groups.at(1)
			expect(group.props('messages')).toStrictEqual(messagesGroup2)
			expect(group.props('previousMessageId')).toBe(110)
			expect(group.props('nextMessageId')).toBe(300)
			expect(group.props('lastReadMessageId')).toBe(110)
			expect(group.attributes('actorid')).toBe('bob')
			expect(group.attributes('actortype')).toBe(ATTENDEE.ACTOR_TYPE.USERS)

			group = groups.at(2)
			expect(group.props('messages')).toStrictEqual(messagesGroup3)
			expect(group.props('previousMessageId')).toBe(210)
			expect(group.props('nextMessageId')).toBe(0)
			expect(group.props('lastReadMessageId')).toBe(110)
			expect(group.attributes('actorid')).toBe('alice')
			expect(group.attributes('actortype')).toBe(ATTENDEE.ACTOR_TYPE.USERS)

			expect(messagesListMock).toHaveBeenCalledWith(TOKEN)
			expect(getVisualLastReadMessageIdMock).toHaveBeenCalledWith(TOKEN)

			const placeholder = wrapper.findAllComponents({ name: 'LoadingPlaceholder' })
			expect(placeholder.exists()).toBe(false)
		})

		test('displays a date separator between days', () => {
			const messages = [{
				id: 100,
				token: TOKEN,
				actorId: 'alice',
				actorDisplayName: 'Alice',
				actorType: ATTENDEE.ACTOR_TYPE.USERS,
				message: 'hello',
				messageType: 'comment',
				messageParameters: {},
				systemMessage: '',
				timestamp: new Date('2020-05-09 13:00:00').getTime() / 1000,
				isReplyable: true,
			}, {
				id: 110,
				token: TOKEN,
				actorId: 'alice',
				actorDisplayName: 'Alice',
				actorType: ATTENDEE.ACTOR_TYPE.USERS,
				message: 'no one here ?',
				messageType: 'comment',
				messageParameters: {},
				systemMessage: '',
				timestamp: new Date('2020-05-10 13:00:00').getTime() / 1000,
				isReplyable: true,
			}, {
				id: 'temp-120',
				token: TOKEN,
				actorId: 'alice',
				actorDisplayName: 'Alice',
				actorType: ATTENDEE.ACTOR_TYPE.USERS,
				message: 'seems no one is there...',
				messageType: 'comment',
				messageParameters: {},
				systemMessage: '',
				timestamp: 0, // temporary, matches current date
				isReplyable: true,
			}]

			const mockDate = new Date('2020-05-11 13:00:00')
			jest.spyOn(global.Date, 'now').mockImplementation(() => mockDate)

			messagesListMock.mockReturnValue(messages)

			const wrapper = shallowMount(MessagesList, {
				localVue,
				store,
				propsData: {
					token: TOKEN,
					isChatScrolledToBottom: true,
				},
			})

			const groups = wrapper.findAllComponents({ name: 'MessagesGroup' })

			expect(groups.exists()).toBe(true)

			let group = groups.at(0)
			expect(group.props('messages')).toStrictEqual([messages[0]])
			// using attributes to access v-bind props
			expect(group.attributes('actorid')).toBe('alice')
			expect(group.attributes('actortype')).toBe(ATTENDEE.ACTOR_TYPE.USERS)
			expect(group.attributes('dateseparator')).toBe('2 days ago, 9 May 2020')

			group = groups.at(1)
			expect(group.props('messages')).toStrictEqual([messages[1]])
			expect(group.attributes('actorid')).toBe('alice')
			expect(group.attributes('actortype')).toBe(ATTENDEE.ACTOR_TYPE.USERS)
			expect(group.attributes('dateseparator')).toBe('Yesterday, 10 May 2020')

			group = groups.at(2)
			expect(group.props('messages')).toStrictEqual([messages[2]])
			expect(group.attributes('actorid')).toBe('alice')
			expect(group.attributes('actortype')).toBe(ATTENDEE.ACTOR_TYPE.USERS)
			expect(group.attributes('dateseparator')).toBe('Today, 11 May 2020')

			expect(messagesListMock).toHaveBeenCalledWith(TOKEN)
		})

		test('groups system messages with each other', () => {
			const messages = [{
				id: 100,
				token: TOKEN,
				actorId: 'alice',
				actorDisplayName: 'Alice',
				actorType: ATTENDEE.ACTOR_TYPE.USERS,
				message: 'Alice has entered the call',
				messageType: 'comment',
				messageParameters: {},
				systemMessage: 'call_started',
				timestamp: 100,
				isReplyable: true,
			}, {
				id: 110,
				token: TOKEN,
				actorId: 'alice',
				actorDisplayName: 'Alice',
				actorType: ATTENDEE.ACTOR_TYPE.USERS,
				message: 'Alice has exited the call',
				messageType: 'comment',
				messageParameters: {},
				systemMessage: 'call_ended',
				timestamp: 200,
				isReplyable: true,
			}]

			messagesListMock.mockReturnValue(messages)

			const wrapper = shallowMount(MessagesList, {
				localVue,
				store,
				propsData: {
					token: TOKEN,
					isChatScrolledToBottom: true,
				},
			})

			const groups = wrapper.findAllComponents({ name: 'MessagesGroup' })

			expect(groups.exists()).toBe(true)

			const group = groups.at(0)
			expect(group.props('messages')).toStrictEqual(messages)
			// using attributes to access v-bind props
			expect(group.attributes('actorid')).toBe('alice')
			expect(group.attributes('actortype')).toBe(ATTENDEE.ACTOR_TYPE.USERS)

			expect(messagesListMock).toHaveBeenCalledWith(TOKEN)
		})

		/**
		 * @param {Array} messages List of messages that should not be grouped
		 */
		function testNotGrouped(messages) {
			messagesListMock.mockReturnValue(messages)

			const wrapper = shallowMount(MessagesList, {
				localVue,
				store,
				propsData: {
					token: TOKEN,
					isChatScrolledToBottom: true,
				},
			})

			const groups = wrapper.findAllComponents({ name: 'MessagesGroup' })

			expect(groups.exists()).toBe(true)

			let group = groups.at(0)
			expect(group.props('messages')).toStrictEqual([messages[0]])
			group = groups.at(1)
			expect(group.props('messages')).toStrictEqual([messages[1]])
		}

		test('does not group system messages with regular messages from the same author', () => {
			testNotGrouped([{
				id: 100,
				token: TOKEN,
				actorId: 'alice',
				actorDisplayName: 'Alice',
				actorType: ATTENDEE.ACTOR_TYPE.USERS,
				message: 'Alice has entered the call',
				messageType: 'comment',
				messageParameters: {},
				systemMessage: 'call_started',
				timestamp: 100,
				isReplyable: true,
			}, {
				id: 110,
				token: TOKEN,
				actorId: 'alice',
				actorDisplayName: 'Alice',
				actorType: ATTENDEE.ACTOR_TYPE.USERS,
				message: 'hello',
				messageType: 'comment',
				messageParameters: {},
				systemMessage: '',
				timestamp: 200,
				isReplyable: true,
			}])
		})

		test('does not group messages of changelog bot', () => {
			testNotGrouped([{
				id: 100,
				token: TOKEN,
				actorId: ATTENDEE.CHANGELOG_BOT_ID,
				actorDisplayName: 'Changelog bot',
				actorType: ATTENDEE.ACTOR_TYPE.BOTS,
				message: 'Alice has entered the call',
				messageType: 'comment',
				messageParameters: {},
				systemMessage: 'call_started',
				timestamp: 100,
				isReplyable: true,
			}, {
				id: 110,
				token: TOKEN,
				actorId: ATTENDEE.CHANGELOG_BOT_ID,
				actorDisplayName: 'Changelog bot',
				actorType: ATTENDEE.ACTOR_TYPE.BOTS,
				message: 'hello',
				messageType: 'comment',
				messageParameters: {},
				systemMessage: '',
				timestamp: 200,
				isReplyable: true,
			}])
		})

		test('does not group messages with different actor types', () => {
			testNotGrouped([{
				id: 100,
				token: TOKEN,
				actorId: 'alice',
				actorDisplayName: 'Alice',
				actorType: ATTENDEE.ACTOR_TYPE.USERS,
				message: 'Alice has entered the call',
				messageType: 'comment',
				messageParameters: {},
				systemMessage: 'call_started',
				timestamp: 100,
				isReplyable: true,
			}, {
				id: 110,
				token: TOKEN,
				actorId: 'alice',
				actorDisplayName: 'Alice',
				actorType: ATTENDEE.ACTOR_TYPE.GUESTS,
				message: 'hello',
				messageType: 'comment',
				messageParameters: {},
				systemMessage: '',
				timestamp: 200,
				isReplyable: true,
			}])
		})

		test('renders a placeholder while loading', () => {
			messagesListMock.mockReturnValue([])

			const wrapper = shallowMount(MessagesList, {
				localVue,
				store,
				propsData: {
					token: TOKEN,
					isChatScrolledToBottom: true,
				},
			})

			const groups = wrapper.findAllComponents({ name: 'MessagesGroup' })
			expect(groups.exists()).toBe(false)

			const placeholder = wrapper.findAllComponents({ name: 'LoadingPlaceholder' })
			expect(placeholder.exists()).toBe(true)
		})

		test('skips deleted messages when grouping', () => {
			const messages = [{
				id: 100,
				token: TOKEN,
				actorId: 'alice',
				actorDisplayName: 'Alice',
				actorType: ATTENDEE.ACTOR_TYPE.USERS,
				message: 'hello',
				messageType: 'comment',
				messageParameters: {},
				systemMessage: '',
				timestamp: 100,
				isReplyable: true,
			}, {
				id: 110,
				token: TOKEN,
				actorId: 'alice',
				actorDisplayName: 'Alice',
				actorType: ATTENDEE.ACTOR_TYPE.USERS,
				message: 'deleted',
				messageType: 'comment',
				messageParameters: {},
				systemMessage: 'message_deleted',
				timestamp: 101,
				isReplyable: true,
			}, {
				id: '120',
				token: TOKEN,
				actorId: 'alice',
				actorDisplayName: 'Alice',
				actorType: ATTENDEE.ACTOR_TYPE.USERS,
				message: 'hello again',
				messageType: 'comment',
				messageParameters: {},
				systemMessage: '',
				timestamp: 102,
				isReplyable: true,
			}]

			messagesListMock.mockReturnValue(messages)

			const wrapper = shallowMount(MessagesList, {
				localVue,
				store,
				propsData: {
					token: TOKEN,
					isChatScrolledToBottom: true,
				},
			})

			const groups = wrapper.findAllComponents({ name: 'MessagesGroup' })

			expect(groups.exists()).toBe(true)

			const group = groups.at(0)
			expect(group.props('messages')).toStrictEqual([messages[0], messages[2]])

			expect(messagesListMock).toHaveBeenCalledWith(TOKEN)
		})
	})
})
