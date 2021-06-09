import mockConsole from 'jest-mock-console'
import { createLocalVue } from '@vue/test-utils'
import Vuex from 'vuex'
import { cloneDeep } from 'lodash'
import { PARTICIPANT } from '../constants'
import {
	promoteToModerator,
	demoteFromModerator,
	setPublishingPermissions,
	removeAttendeeFromConversation,
	resendInvitations,
	joinConversation,
	leaveConversation,
	removeCurrentUserFromConversation,
} from '../services/participantsService'
import {
	joinCall,
	leaveCall,
} from '../services/callsService'
import { EventBus } from '../services/EventBus'

import participantsStore from './participantsStore'

jest.mock('../services/participantsService', () => ({
	promoteToModerator: jest.fn(),
	demoteFromModerator: jest.fn(),
	setPublishingPermissions: jest.fn(),
	removeAttendeeFromConversation: jest.fn(),
	resendInvitations: jest.fn(),
	joinConversation: jest.fn(),
	leaveConversation: jest.fn(),
	removeCurrentUserFromConversation: jest.fn(),
}))
jest.mock('../services/callsService', () => ({
	joinCall: jest.fn(),
	leaveCall: jest.fn(),
}))

describe('participantsStore', () => {
	const TOKEN = 'XXTOKENXX'
	let testStoreConfig = null
	let localVue = null
	let store = null

	beforeEach(() => {
		localVue = createLocalVue()
		localVue.use(Vuex)

		testStoreConfig = cloneDeep(participantsStore)
		store = new Vuex.Store(testStoreConfig)
	})

	afterEach(() => {
		store = null
		jest.clearAllMocks()
	})

	describe('participant list', () => {
		test('adds participant', () => {
			store.dispatch('addParticipant', {
				token: TOKEN, participant: { attendeeId: 1 },
			})

			expect(store.getters.participantsList(TOKEN)).toStrictEqual([
				{ attendeeId: 1 },
			])
		})

		test('adds participant once', () => {
			store.dispatch('addParticipantOnce', {
				token: TOKEN, participant: { attendeeId: 1 },
			})

			// does not add again
			store.dispatch('addParticipantOnce', {
				token: TOKEN, participant: { attendeeId: 1 },
			})

			expect(store.getters.participantsList(TOKEN)).toStrictEqual([
				{ attendeeId: 1 },
			])
		})

		test('does nothing when removing non-existing participant', () => {
			store.dispatch('removeParticipant', {
				token: TOKEN,
				attendeeId: 1,
			})

			expect(removeAttendeeFromConversation).not.toHaveBeenCalled()
		})

		test('removes participant', async() => {
			store.dispatch('addParticipant', {
				token: TOKEN, participant: { attendeeId: 1 },
			})

			removeAttendeeFromConversation.mockResolvedValue()

			await store.dispatch('removeParticipant', {
				token: TOKEN,
				attendeeId: 1,
			})

			expect(removeAttendeeFromConversation).toHaveBeenCalledWith(TOKEN, 1)

			expect(store.getters.participantsList(TOKEN)).toStrictEqual([])
		})

		test('purges participant list', () => {
			store.dispatch('addParticipant', {
				token: TOKEN, participant: { attendeeId: 1 },
			})
			store.dispatch('addParticipant', {
				token: 'token-2', participant: { attendeeId: 2 },
			})

			store.dispatch('purgeParticipantsStore', TOKEN)

			expect(store.getters.participantsList(TOKEN)).toStrictEqual([])
			expect(store.getters.participantsList('token-2')).toStrictEqual([
				{ attendeeId: 2 },
			])
		})

		test('gets participant index', () => {
			store.dispatch('addParticipant', {
				token: TOKEN,
				participant: {
					attendeeId: 1,
				},
			})
			store.dispatch('addParticipant', {
				token: TOKEN,
				participant: {
					attendeeId: 2,
				},
			})

			expect(store.getters.getParticipantIndex(
				TOKEN,
				{ attendeeId: 1 },
			)).toBe(0)
			expect(store.getters.getParticipantIndex(
				TOKEN,
				{ attendeeId: 2 },
			)).toBe(1)
			expect(store.getters.getParticipantIndex(
				TOKEN,
				{ attendeeId: 3 },
			)).toBe(-1)
		})

		test('updates participant data', () => {
			store.dispatch('addParticipant', {
				token: TOKEN,
				participant: {
					attendeeId: 1,
					statusMessage: 'status-message',
				},
			})

			store.dispatch('updateUser', {
				token: TOKEN,
				participantIdentifier: { attendeeId: 1 },
				updatedData: {
					statusMessage: 'new-status-message',
				},
			})

			expect(store.getters.participantsList(TOKEN)).toStrictEqual([
				{
					attendeeId: 1,
					statusMessage: 'new-status-message',
				},
			])
		})

		test('updates participant session id', () => {
			store.dispatch('addParticipant', {
				token: TOKEN,
				participant: {
					attendeeId: 1,
					sessionId: 'session-id-1',
					inCall: PARTICIPANT.CALL_FLAG.IN_CALL,
				},
			})

			store.dispatch('updateSessionId', {
				token: TOKEN,
				participantIdentifier: { attendeeId: 1 },
				sessionId: 'new-session-id',
			})

			expect(store.getters.participantsList(TOKEN)).toStrictEqual([
				{
					attendeeId: 1,
					sessionId: 'new-session-id',
					inCall: PARTICIPANT.CALL_FLAG.DISCONNECTED,
				},
			])
		})

		describe('promote to moderator', () => {
			test('does nothing when promoting not found attendee', () => {
				store.dispatch('promoteToModerator', {
					token: TOKEN,
					attendeeId: 1,
				})

				expect(promoteToModerator).not.toHaveBeenCalled()
			})

			async function testPromoteModerator(participantType, expectedParticipantType) {
				promoteToModerator.mockResolvedValue()

				store.dispatch('addParticipant', {
					token: TOKEN,
					participant: {
						attendeeId: 1,
						participantType,
					},
				})
				await store.dispatch('promoteToModerator', {
					token: TOKEN,
					attendeeId: 1,
				})
				expect(promoteToModerator)
					.toHaveBeenCalledWith(TOKEN, { attendeeId: 1 })

				expect(store.getters.participantsList(TOKEN)).toStrictEqual([
					{
						attendeeId: 1,
						participantType: expectedParticipantType,
					},
				])
			}

			test('promotes given user to moderator', async() => {
				await testPromoteModerator(PARTICIPANT.TYPE.USER, PARTICIPANT.TYPE.MODERATOR)
			})
			test('promotes given guest to guest moderator', async() => {
				await testPromoteModerator(PARTICIPANT.TYPE.GUEST, PARTICIPANT.TYPE.GUEST_MODERATOR)
			})
		})

		describe('demotes from moderator', () => {
			test('does nothing when demoting not found attendee', () => {
				store.dispatch('demoteFromModerator', {
					token: TOKEN,
					attendeeId: 1,
				})

				expect(demoteFromModerator).not.toHaveBeenCalled()
			})

			async function testDemoteModerator(participantType, expectedParticipantType) {
				promoteToModerator.mockResolvedValue()

				store.dispatch('addParticipant', {
					token: TOKEN,
					participant: {
						attendeeId: 1,
						participantType,
					},
				})
				await store.dispatch('demoteFromModerator', {
					token: TOKEN,
					attendeeId: 1,
				})
				expect(demoteFromModerator)
					.toHaveBeenCalledWith(TOKEN, { attendeeId: 1 })

				expect(store.getters.participantsList(TOKEN)).toStrictEqual([
					{
						attendeeId: 1,
						participantType: expectedParticipantType,
					},
				])
			}

			test('demotes given moderator to user', async() => {
				await testDemoteModerator(PARTICIPANT.TYPE.MODERATOR, PARTICIPANT.TYPE.USER)
			})
			test('promotes given guest to guest moderator', async() => {
				await testDemoteModerator(PARTICIPANT.TYPE.GUEST_MODERATOR, PARTICIPANT.TYPE.GUEST)
			})
		})

		describe('set publishing permissions', () => {
			test('does nothing when setting publishing permissions for not found attendee', () => {
				store.dispatch('setPublishingPermissions', {
					token: TOKEN,
					attendeeId: 1,
					state: PARTICIPANT.PUBLISHING_PERMISSIONS.ALL,
				})

				expect(setPublishingPermissions).not.toHaveBeenCalled()
			})

			async function testSetPublishingPermissions(publishingPermissions, expectedPublishingPermissions) {
				setPublishingPermissions.mockResolvedValue()

				store.dispatch('addParticipant', {
					token: TOKEN,
					participant: {
						attendeeId: 1,
						publishingPermissions,
					},
				})
				await store.dispatch('setPublishingPermissions', {
					token: TOKEN,
					attendeeId: 1,
					state: expectedPublishingPermissions,
				})
				expect(setPublishingPermissions)
					.toHaveBeenCalledWith(TOKEN, {
						attendeeId: 1,
						state: expectedPublishingPermissions,
					})

				expect(store.getters.participantsList(TOKEN)).toStrictEqual([
					{
						attendeeId: 1,
						publishingPermissions: expectedPublishingPermissions,
					},
				])
			}

			test('grants all publishing permissions', async() => {
				await testSetPublishingPermissions(PARTICIPANT.PUBLISHING_PERMISSIONS.NONE, PARTICIPANT.PUBLISHING_PERMISSIONS.ALL)
			})
			test('revokes all publishing permissions', async() => {
				await testSetPublishingPermissions(PARTICIPANT.PUBLISHING_PERMISSIONS.ALL, PARTICIPANT.PUBLISHING_PERMISSIONS.NONE)
			})
		})
	})

	describe('peers list', () => {
		test('adds peer', () => {
			store.dispatch('addPeer', {
				token: TOKEN,
				peer: { sessionId: 'session-id-1', peerData: 1 },
			})

			expect(store.getters.getPeer(TOKEN, 'session-id-1'))
				.toStrictEqual({ sessionId: 'session-id-1', peerData: 1 })

			expect(store.getters.getPeer(TOKEN, 'session-id-2'))
				.toStrictEqual({})
		})

		test('purges peers store', () => {
			store.dispatch('addPeer', {
				token: TOKEN,
				peer: { sessionId: 'session-id-1', peerData: 1 },
			})
			store.dispatch('addPeer', {
				token: 'token-2',
				peer: { sessionId: 'session-id-2', peerData: 1 },
			})

			store.dispatch('purgePeersStore', TOKEN)

			expect(store.getters.getPeer(TOKEN, 'session-id-1'))
				.toStrictEqual({})
			expect(store.getters.getPeer('token-2', 'session-id-2'))
				.toStrictEqual({ sessionId: 'session-id-2', peerData: 1 })
		})
	})

	describe('call handling', () => {
		beforeEach(() => {
			store = new Vuex.Store(testStoreConfig)
		})

		test('joins call', async() => {
			store.dispatch('addParticipant', {
				token: TOKEN,
				participant: {
					attendeeId: 1,
					sessionId: 'session-id-1',
					participantType: PARTICIPANT.TYPE.USER,
					inCall: PARTICIPANT.CALL_FLAG.DISCONNECTED,
				},
			})

			// The requested flags and the actual flags can be different if some
			// media device is not available.
			const actualFlags = PARTICIPANT.CALL_FLAG.WITH_AUDIO
			joinCall.mockResolvedValue(actualFlags)

			expect(store.getters.isInCall(TOKEN)).toBe(false)
			expect(store.getters.isConnecting(TOKEN)).toBe(false)

			const flags = PARTICIPANT.CALL_FLAG.WITH_AUDIO | PARTICIPANT.CALL_FLAG.WITH_VIDEO
			await store.dispatch('joinCall', {
				token: TOKEN,
				participantIdentifier: {
					attendeeId: 1,
					sessionId: 'session-id-1',
				},
				flags,
			})

			expect(joinCall).toHaveBeenCalledWith(TOKEN, flags)
			expect(store.getters.isInCall(TOKEN)).toBe(true)
			expect(store.getters.participantsList(TOKEN)).toStrictEqual([
				{
					attendeeId: 1,
					sessionId: 'session-id-1',
					inCall: actualFlags,
					participantType: PARTICIPANT.TYPE.USER,
				},
			])

			expect(store.getters.isConnecting(TOKEN)).toBe(true)

			EventBus.$emit('Signaling::usersInRoom')

			expect(store.getters.isInCall(TOKEN)).toBe(true)
			expect(store.getters.isConnecting(TOKEN)).toBe(false)
		})
	})

	test('joins and leaves call', async() => {
		store.dispatch('addParticipant', {
			token: TOKEN,
			participant: {
				attendeeId: 1,
				sessionId: 'session-id-1',
				participantType: PARTICIPANT.TYPE.USER,
				inCall: PARTICIPANT.CALL_FLAG.DISCONNECTED,
			},
		})

		// The requested flags and the actual flags can be different if some
		// media device is not available.
		const actualFlags = PARTICIPANT.CALL_FLAG.WITH_AUDIO
		joinCall.mockResolvedValue(actualFlags)

		expect(store.getters.isInCall(TOKEN)).toBe(false)
		expect(store.getters.isConnecting(TOKEN)).toBe(false)

		const flags = PARTICIPANT.CALL_FLAG.WITH_AUDIO | PARTICIPANT.CALL_FLAG.WITH_VIDEO
		await store.dispatch('joinCall', {
			token: TOKEN,
			participantIdentifier: {
				attendeeId: 1,
				sessionId: 'session-id-1',
			},
			flags,
		})

		expect(joinCall).toHaveBeenCalledWith(TOKEN, flags)
		expect(store.getters.isInCall(TOKEN)).toBe(true)
		expect(store.getters.participantsList(TOKEN)).toStrictEqual([
			{
				attendeeId: 1,
				sessionId: 'session-id-1',
				inCall: actualFlags,
				participantType: PARTICIPANT.TYPE.USER,
			},
		])

		expect(store.getters.isConnecting(TOKEN)).toBe(true)

		EventBus.$emit('Signaling::usersInRoom')

		expect(store.getters.isInCall(TOKEN)).toBe(true)
		expect(store.getters.isConnecting(TOKEN)).toBe(false)

		leaveCall.mockResolvedValue()

		await store.dispatch('leaveCall', {
			token: TOKEN,
			participantIdentifier: {
				attendeeId: 1,
				sessionId: 'session-id-1',
			},
		})

		expect(leaveCall).toHaveBeenCalledWith(TOKEN)
		expect(store.getters.isInCall(TOKEN)).toBe(false)
		expect(store.getters.isConnecting(TOKEN)).toBe(false)
		expect(store.getters.participantsList(TOKEN)).toStrictEqual([
			{
				attendeeId: 1,
				sessionId: 'session-id-1',
				inCall: PARTICIPANT.CALL_FLAG.DISCONNECTED,
				participantType: PARTICIPANT.TYPE.USER,
			},
		])
	})

	test('resends invitations', async() => {
		resendInvitations.mockResolvedValue()

		await store.dispatch('resendInvitations', {
			token: TOKEN,
			attendeeId: 1,
		})

		expect(resendInvitations).toHaveBeenCalledWith(TOKEN, { attendeeId: 1 })
	})

	describe('joining conversation', () => {
		let getParticipantIdentifierMock
		let participantData
		let joinedConversationEventMock

		beforeEach(() => {
			joinedConversationEventMock = jest.fn()
			EventBus.$once('joinedConversation', joinedConversationEventMock)

			getParticipantIdentifierMock = jest.fn().mockReturnValue({
				attendeeId: 1,
			})
			participantData = {
				actorId: 'actor-id',
				sessionId: 'session-id-1',
				participantType: PARTICIPANT.TYPE.USER,
				attendeeId: 1,
				inCall: PARTICIPANT.CALL_FLAG.DISCONNECTED,
			}

			testStoreConfig.getters.getParticipantIdentifier = () => getParticipantIdentifierMock
			testStoreConfig.actions.setCurrentParticipant = jest.fn()
			testStoreConfig.actions.addConversation = jest.fn().mockImplementation((context) => {
				// needed for the updateSessionId call which requires this
				context.dispatch('addParticipantOnce', {
					token: TOKEN, participant: participantData,
				})
			})
		})

		test('joins conversation', async() => {
			store = new Vuex.Store(testStoreConfig)
			const response = {
				status: 200,
				data: {
					ocs: {
						data: participantData,
					},
				},
			}
			joinConversation.mockResolvedValue(response)

			const returnedResponse = await store.dispatch('joinConversation', { token: TOKEN })

			expect(joinConversation).toHaveBeenCalledWith({ token: TOKEN, forceJoin: false })
			expect(returnedResponse).toBe(response)

			expect(testStoreConfig.actions.setCurrentParticipant).toHaveBeenCalledWith(expect.anything(), participantData)
			expect(testStoreConfig.actions.addConversation).toHaveBeenCalledWith(expect.anything(), participantData)

			expect(getParticipantIdentifierMock).toHaveBeenCalled()

			expect(store.getters.participantsList(TOKEN)[0])
				.toStrictEqual(participantData)

			expect(joinedConversationEventMock).toHaveBeenCalledWith({ token: TOKEN })
		})

		test('force join conversation', async() => {
			store = new Vuex.Store(testStoreConfig)
			const updatedParticipantData = Object.assign({}, participantData, { sessionId: 'another-session-id' })
			const response = {
				status: 200,
				data: {
					ocs: {
						data: updatedParticipantData,
					},
				},
			}
			joinConversation.mockResolvedValue(response)

			sessionStorage.getItem.mockReturnValueOnce(TOKEN)

			await store.dispatch('forceJoinConversation', { token: TOKEN })

			expect(sessionStorage.setItem).toHaveBeenCalled()
			expect(sessionStorage.setItem.mock.calls[0][0]).toMatch(/joined_conversation$/)
			expect(sessionStorage.setItem.mock.calls[0][1]).toBe(TOKEN)

			expect(joinConversation).toHaveBeenCalledWith({ token: TOKEN, forceJoin: true })

			expect(testStoreConfig.actions.setCurrentParticipant).toHaveBeenCalledWith(expect.anything(), participantData)
			expect(testStoreConfig.actions.addConversation).toHaveBeenCalledWith(expect.anything(), participantData)

			expect(getParticipantIdentifierMock).toHaveBeenCalled()

			expect(store.getters.participantsList(TOKEN)[0])
				.toStrictEqual(updatedParticipantData)

			expect(joinedConversationEventMock).toHaveBeenCalledWith({ token: TOKEN })
		})

		describe('force join on error', () => {
			let restoreConsole
			beforeEach(() => {
				restoreConsole = mockConsole(['error', 'debug'])
			})
			afterEach(() => {
				expect(testStoreConfig.actions.setCurrentParticipant).not.toHaveBeenCalled()
				expect(testStoreConfig.actions.addConversation).not.toHaveBeenCalled()
				expect(sessionStorage.setItem).not.toHaveBeenCalled()
				expect(joinedConversationEventMock).not.toHaveBeenCalled()

				restoreConsole()
			})

			function prepareTestJoinWithMaxPingAge(lastPingAge, inCall) {
				const mockDate = new Date('2020-01-01 20:00:00')
				participantData.lastPing = mockDate.getTime() / 1000 - lastPingAge
				participantData.inCall = inCall

				jest.spyOn(global, 'Date')
					.mockImplementation(() => mockDate)

				const response = {
					status: 409,
					data: {
						ocs: {
							data: participantData,
						},
					},
				}
				joinConversation.mockRejectedValue({ response })
			}

			describe('when not in call', () => {
				test('forces join when max ping age > 40s', async() => {
					prepareTestJoinWithMaxPingAge(41, PARTICIPANT.CALL_FLAG.DISCONNECTED)

					testStoreConfig.actions.forceJoinConversation = jest.fn()
					testStoreConfig.actions.confirmForceJoinConversation = jest.fn()

					store = new Vuex.Store(testStoreConfig)
					await store.dispatch('joinConversation', { token: TOKEN })

					expect(testStoreConfig.actions.confirmForceJoinConversation).not.toHaveBeenCalled()
					expect(testStoreConfig.actions.forceJoinConversation).toHaveBeenCalledWith(expect.anything(), { token: TOKEN })
				})

				test('shows force when max ping age <= 40s', async() => {
					prepareTestJoinWithMaxPingAge(40, PARTICIPANT.CALL_FLAG.DISCONNECTED)

					testStoreConfig.actions.forceJoinConversation = jest.fn()
					testStoreConfig.actions.confirmForceJoinConversation = jest.fn()

					store = new Vuex.Store(testStoreConfig)
					await store.dispatch('joinConversation', { token: TOKEN })

					expect(testStoreConfig.actions.forceJoinConversation).not.toHaveBeenCalled()
					expect(testStoreConfig.actions.confirmForceJoinConversation).toHaveBeenCalledWith(expect.anything(), { token: TOKEN })
				})
			})

			describe('when in call', () => {
				test('forces join when max ping age > 60s', async() => {
					prepareTestJoinWithMaxPingAge(61, PARTICIPANT.CALL_FLAG.IN_CALL)

					testStoreConfig.actions.forceJoinConversation = jest.fn()
					testStoreConfig.actions.confirmForceJoinConversation = jest.fn()

					store = new Vuex.Store(testStoreConfig)
					await store.dispatch('joinConversation', { token: TOKEN })

					expect(testStoreConfig.actions.confirmForceJoinConversation).not.toHaveBeenCalled()
					expect(testStoreConfig.actions.forceJoinConversation).toHaveBeenCalledWith(expect.anything(), { token: TOKEN })
				})

				test('shows force when max ping age <= 60s', async() => {
					prepareTestJoinWithMaxPingAge(60, PARTICIPANT.CALL_FLAG.IN_CALL)

					testStoreConfig.actions.forceJoinConversation = jest.fn()
					testStoreConfig.actions.confirmForceJoinConversation = jest.fn()

					store = new Vuex.Store(testStoreConfig)
					await store.dispatch('joinConversation', { token: TOKEN })

					expect(testStoreConfig.actions.forceJoinConversation).not.toHaveBeenCalled()
					expect(testStoreConfig.actions.confirmForceJoinConversation).toHaveBeenCalledWith(expect.anything(), { token: TOKEN })
				})
			})
		})
	})

	test('leaves conversation', async() => {
		leaveConversation.mockResolvedValue()

		await store.dispatch('leaveConversation', { token: TOKEN })

		expect(leaveConversation).toHaveBeenCalledWith(TOKEN)
	})

	test('removes current user from conversation', async() => {
		removeCurrentUserFromConversation.mockResolvedValue()

		testStoreConfig = cloneDeep(participantsStore)
		testStoreConfig.actions.deleteConversation = jest.fn()
		store = new Vuex.Store(testStoreConfig)

		await store.dispatch('removeCurrentUserFromConversation', { token: TOKEN })

		expect(removeCurrentUserFromConversation).toHaveBeenCalledWith(TOKEN)
		expect(testStoreConfig.actions.deleteConversation).toHaveBeenCalledWith(expect.anything(), TOKEN)
	})
})
