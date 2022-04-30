<?php

declare(strict_types=1);
/**
 *
 * @copyright Copyright (c) 2017, Daniel Calviño Sánchez <danxuliu@gmail.com>
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

namespace OCA\Talk\Tests\php\Chat;

use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Chat\CommentsManager;
use OCA\Talk\Chat\Notifier;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\AttendeeMapper;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\AttachmentService;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Share\RoomShareProvider;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * @group DB
 */
class ChatManagerTest extends TestCase {

	/** @var CommentsManager|ICommentsManager|MockObject */
	protected $commentsManager;
	/** @var IEventDispatcher|MockObject */
	protected $dispatcher;
	/** @var INotificationManager|MockObject */
	protected $notificationManager;
	/** @var IManager|MockObject */
	protected $shareManager;
	/** @var RoomShareProvider|MockObject */
	protected $shareProvider;
	/** @var ParticipantService|MockObject */
	protected $participantService;
	/** @var Notifier|MockObject */
	protected $notifier;
	/** @var ITimeFactory|MockObject */
	protected $timeFactory;
	/** @var AttachmentService|MockObject */
	protected $attachmentService;
	protected ?ChatManager $chatManager = null;

	public function setUp(): void {
		parent::setUp();

		$this->commentsManager = $this->createMock(CommentsManager::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->shareManager = $this->createMock(IManager::class);
		$this->shareProvider = $this->createMock(RoomShareProvider::class);
		$this->participantService = $this->createMock(ParticipantService::class);
		$this->notifier = $this->createMock(Notifier::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->attachmentService = $this->createMock(AttachmentService::class);
		$cacheFactory = $this->createMock(ICacheFactory::class);

		$this->chatManager = new ChatManager(
			$this->commentsManager,
			$this->dispatcher,
			\OC::$server->getDatabaseConnection(),
			$this->notificationManager,
			$this->shareManager,
			$this->shareProvider,
			$this->participantService,
			$this->notifier,
			$cacheFactory,
			$this->timeFactory,
			$this->attachmentService
		);
	}

	/**
	 * @param string[] $methods
	 * @return ChatManager|MockObject
	 */
	protected function getManager(array $methods = []): ChatManager {
		$cacheFactory = $this->createMock(ICacheFactory::class);

		if (!empty($methods)) {
			return $this->getMockBuilder(ChatManager::class)
				->setConstructorArgs([
					$this->commentsManager,
					$this->dispatcher,
					\OC::$server->getDatabaseConnection(),
					$this->notificationManager,
					$this->shareManager,
					$this->shareProvider,
					$this->participantService,
					$this->notifier,
					$cacheFactory,
					$this->timeFactory,
					$this->attachmentService,
				])
				->onlyMethods($methods)
				->getMock();
		}

		return new ChatManager(
			$this->commentsManager,
			$this->dispatcher,
			\OC::$server->getDatabaseConnection(),
			$this->notificationManager,
			$this->shareManager,
			$this->shareProvider,
			$this->participantService,
			$this->notifier,
			$cacheFactory,
			$this->timeFactory,
			$this->attachmentService
		);
	}

	private function newComment($id, string $actorType, string $actorId, \DateTime $creationDateTime, string $message): IComment {
		$comment = $this->createMock(IComment::class);

		$id = (string) $id;

		$comment->method('getId')->willReturn($id);
		$comment->method('getActorType')->willReturn($actorType);
		$comment->method('getActorId')->willReturn($actorId);
		$comment->method('getCreationDateTime')->willReturn($creationDateTime);
		$comment->method('getMessage')->willReturn($message);

		// Used for equals comparison
		$comment->id = $id;
		$comment->actorType = $actorType;
		$comment->actorId = $actorId;
		$comment->creationDateTime = $creationDateTime;
		$comment->message = $message;

		return $comment;
	}

	/**
	 * @param array $data
	 * @return IComment|MockObject
	 */
	private function newCommentFromArray(array $data): IComment {
		$comment = $this->createMock(IComment::class);

		foreach ($data as $key => $value) {
			if ($key === 'id') {
				$value = (string) $value;
			}
			$comment->method('get' . ucfirst($key))->willReturn($value);
		}

		return $comment;
	}

	protected function assertCommentEquals(array $data, IComment $comment): void {
		if (isset($data['id'])) {
			$id = $data['id'];
			unset($data['id']);
			$this->assertEquals($id, $comment->getId());
		}

		$this->assertEquals($data, [
			'actorType' => $comment->getActorType(),
			'actorId' => $comment->getActorId(),
			'creationDateTime' => $comment->getCreationDateTime(),
			'message' => $comment->getMessage(),
			'referenceId' => $comment->getReferenceId(),
			'parentId' => $comment->getParentId(),
		]);
	}

	public function dataSendMessage(): array {
		return [
			'simple message' => ['testUser1', 'testMessage1', '', '0'],
			'reference id' => ['testUser2', 'testMessage2', 'referenceId2', '0'],
			'as a reply' => ['testUser3', 'testMessage3', '', '23'],
			'reply w/ ref' => ['testUser4', 'testMessage4', 'referenceId4', '23'],
		];
	}

	/**
	 * @dataProvider dataSendMessage
	 * @param string $userId
	 * @param string $message
	 * @param string $referenceId
	 * @param string $parentId
	 */
	public function testSendMessage(string $userId, string $message, string $referenceId, string $parentId): void {
		$creationDateTime = new \DateTime();

		$commentExpected = [
			'actorType' => 'users',
			'actorId' => $userId,
			'creationDateTime' => $creationDateTime,
			'message' => $message,
			'referenceId' => $referenceId,
			'parentId' => $parentId,
		];

		$comment = $this->newCommentFromArray($commentExpected);

		if ($parentId !== '0') {
			$replyTo = $this->newCommentFromArray([
				'id' => $parentId,
			]);

			$comment->expects($this->once())
				->method('setParentId')
				->with($parentId);
		} else {
			$replyTo = null;
		}

		$this->commentsManager->expects($this->once())
			->method('create')
			->with('users', $userId, 'chat', 1234)
			->willReturn($comment);

		$comment->expects($this->once())
			->method('setMessage')
			->with($message);

		$comment->expects($this->once())
			->method('setCreationDateTime')
			->with($creationDateTime);

		$comment->expects($referenceId === '' ? $this->never() : $this->once())
			->method('setReferenceId')
			->with($referenceId);

		$comment->expects($this->once())
			->method('setVerb')
			->with('comment');

		$this->commentsManager->expects($this->once())
			->method('save')
			->with($comment);

		$chat = $this->createMock(Room::class);
		$chat->expects($this->any())
			->method('getId')
			->willReturn(1234);

		$this->notifier->expects($this->once())
			->method('notifyMentionedUsers')
			->with($chat, $comment);

		$participant = $this->createMock(Participant::class);

		$return = $this->chatManager->sendMessage($chat, $participant, 'users', $userId, $message, $creationDateTime, $replyTo, $referenceId);

		$this->assertCommentEquals($commentExpected, $return);
	}

	public function testGetHistory(): void {
		$offset = 1;
		$limit = 42;
		$expected = [
			$this->newComment(110, 'users', 'testUnknownUser', new \DateTime('@' . 1000000042), 'testMessage3'),
			$this->newComment(109, 'guests', 'testSpreedSession', new \DateTime('@' . 1000000023), 'testMessage2'),
			$this->newComment(108, 'users', 'testUser', new \DateTime('@' . 1000000016), 'testMessage1')
		];

		$chat = $this->createMock(Room::class);
		$chat->expects($this->any())
			->method('getId')
			->willReturn(1234);

		$this->commentsManager->expects($this->once())
			->method('getForObjectSince')
			->with('chat', 1234, $offset, 'desc', $limit)
			->willReturn($expected);

		$comments = $this->chatManager->getHistory($chat, $offset, $limit, false);

		$this->assertEquals($expected, $comments);
	}

	public function testWaitForNewMessages(): void {
		$offset = 1;
		$limit = 42;
		$timeout = 23;
		$expected = [
			$this->newComment(108, 'users', 'testUser', new \DateTime('@' . 1000000016), 'testMessage1'),
			$this->newComment(109, 'guests', 'testSpreedSession', new \DateTime('@' . 1000000023), 'testMessage2'),
			$this->newComment(110, 'users', 'testUnknownUser', new \DateTime('@' . 1000000042), 'testMessage3'),
		];

		$chat = $this->createMock(Room::class);
		$chat->expects($this->any())
			->method('getId')
			->willReturn(1234);

		$this->commentsManager->expects($this->once())
			->method('getForObjectSince')
			->with('chat', 1234, $offset, 'asc', $limit)
			->willReturn($expected);

		$this->notifier->expects($this->once())
			->method('markMentionNotificationsRead')
			->with($chat, 'userId');

		/** @var IUser|MockObject $user */
		$user = $this->createMock(IUser::class);
		$user->expects($this->any())
			->method('getUID')
			->willReturn('userId');

		$comments = $this->chatManager->waitForNewMessages($chat, $offset, $limit, $timeout, $user, false);

		$this->assertEquals($expected, $comments);
	}

	public function testWaitForNewMessagesWithWaiting(): void {
		$offset = 1;
		$limit = 42;
		$timeout = 23;
		$expected = [
			$this->newComment(108, 'users', 'testUser', new \DateTime('@' . 1000000016), 'testMessage1'),
			$this->newComment(109, 'guests', 'testSpreedSession', new \DateTime('@' . 1000000023), 'testMessage2'),
			$this->newComment(110, 'users', 'testUnknownUser', new \DateTime('@' . 1000000042), 'testMessage3'),
		];

		$chat = $this->createMock(Room::class);
		$chat->expects($this->any())
			->method('getId')
			->willReturn(1234);

		$this->commentsManager->expects($this->exactly(2))
			->method('getForObjectSince')
			->with('chat', 1234, $offset, 'asc', $limit)
			->willReturnOnConsecutiveCalls(
				[],
				$expected
			);

		$this->notifier->expects($this->once())
			->method('markMentionNotificationsRead')
			->with($chat, 'userId');

		/** @var IUser|MockObject $user */
		$user = $this->createMock(IUser::class);
		$user->expects($this->any())
			->method('getUID')
			->willReturn('userId');

		$comments = $this->chatManager->waitForNewMessages($chat, $offset, $limit, $timeout, $user, false);

		$this->assertEquals($expected, $comments);
	}

	public function testGetUnreadCount(): void {
		/** @var Room|MockObject $chat */
		$chat = $this->createMock(Room::class);
		$chat->expects($this->atLeastOnce())
			->method('getId')
			->willReturn(23);

		$this->commentsManager->expects($this->once())
			->method('getNumberOfCommentsWithVerbsForObjectSinceComment')
			->with('chat', 23, 42, ['comment', 'object_shared']);

		$this->chatManager->getUnreadCount($chat, 42);
	}

	public function testDeleteMessages(): void {
		$chat = $this->createMock(Room::class);
		$chat->expects($this->any())
			->method('getId')
			->willReturn(1234);

		$this->commentsManager->expects($this->once())
			->method('deleteCommentsAtObject')
			->with('chat', 1234);

		$this->notifier->expects($this->once())
			->method('removePendingNotificationsForRoom')
			->with($chat);

		$this->chatManager->deleteMessages($chat);
	}

	public function testDeleteMessage(): void {
		$mapper = new AttendeeMapper(\OC::$server->getDatabaseConnection());
		$attendee = $mapper->createAttendeeFromRow([
			'a_id' => 1,
			'room_id' => 123,
			'actor_type' => Attendee::ACTOR_USERS,
			'actor_id' => 'user',
			'display_name' => 'user-display',
			'pin' => '',
			'participant_type' => Participant::USER,
			'favorite' => true,
			'notification_level' => Participant::NOTIFY_MENTION,
			'notification_calls' => Participant::NOTIFY_CALLS_ON,
			'last_joined_call' => 0,
			'last_read_message' => 0,
			'last_mention_message' => 0,
			'last_mention_direct' => 0,
			'read_privacy' => Participant::PRIVACY_PUBLIC,
			'permissions' => Attendee::PERMISSIONS_DEFAULT,
			'access_token' => '',
			'remote_id' => '',
		]);
		$chat = $this->createMock(Room::class);
		$chat->expects($this->any())
			->method('getId')
			->willReturn(1234);
		$participant = new Participant($chat, $attendee, null);

		$date = new \DateTime();

		$comment = $this->createMock(IComment::class);
		$comment->method('getId')
			->willReturn('123456');
		$comment->method('getVerb')
			->willReturn('comment');
		$comment->expects($this->once())
			->method('setMessage');
		$comment->expects($this->once())
			->method('setVerb')
			->with('comment_deleted');

		$this->commentsManager->expects($this->once())
			->method('save')
			->with($comment);

		$systemMessage = $this->createMock(IComment::class);

		$chatManager = $this->getManager(['addSystemMessage']);
		$chatManager->expects($this->once())
			->method('addSystemMessage')
			->with($chat, Attendee::ACTOR_USERS, 'user', $this->anything(), $this->anything(), false, null, 123456)
			->willReturn($systemMessage);

		$this->assertSame($systemMessage, $chatManager->deleteMessage($chat, $comment, $participant, $date));
	}

	public function testDeleteMessageFileShare(): void {
		$mapper = new AttendeeMapper(\OC::$server->getDatabaseConnection());
		$attendee = $mapper->createAttendeeFromRow([
			'a_id' => 1,
			'room_id' => 123,
			'actor_type' => Attendee::ACTOR_USERS,
			'actor_id' => 'user',
			'display_name' => 'user-display',
			'pin' => '',
			'participant_type' => Participant::USER,
			'favorite' => true,
			'notification_level' => Participant::NOTIFY_MENTION,
			'notification_calls' => Participant::NOTIFY_CALLS_ON,
			'last_joined_call' => 0,
			'last_read_message' => 0,
			'last_mention_message' => 0,
			'last_mention_direct' => 0,
			'read_privacy' => Participant::PRIVACY_PUBLIC,
			'permissions' => Attendee::PERMISSIONS_DEFAULT,
			'access_token' => '',
			'remote_id' => '',
		]);
		$chat = $this->createMock(Room::class);
		$chat->expects($this->any())
			->method('getId')
			->willReturn(1234);
		$chat->expects($this->any())
			->method('getToken')
			->willReturn('T0k3N');
		$participant = new Participant($chat, $attendee, null);

		$date = new \DateTime();

		$comment = $this->createMock(IComment::class);
		$comment->method('getId')
			->willReturn('123456');
		$comment->method('getVerb')
			->willReturn('object_shared');
		$comment->expects($this->once())
			->method('getMessage')
			->willReturn(json_encode(['message' => 'file_shared', 'parameters' => ['share' => '42']]));
		$comment->expects($this->once())
			->method('setMessage');
		$comment->expects($this->once())
			->method('setVerb')
			->with('comment_deleted');

		$share = $this->createMock(IShare::class);
		$share->method('getShareType')
			->willReturn(IShare::TYPE_ROOM);
		$share->method('getSharedWith')
			->willReturn('T0k3N');
		$share->method('getShareOwner')
			->willReturn('user');

		$this->shareManager->method('getShareById')
			->with('ocRoomShare:42')
			->willReturn($share);

		$this->shareManager->expects($this->once())
			->method('deleteShare')
			->willReturn($share);

		$this->commentsManager->expects($this->once())
			->method('save')
			->with($comment);

		$systemMessage = $this->createMock(IComment::class);

		$chatManager = $this->getManager(['addSystemMessage']);
		$chatManager->expects($this->once())
			->method('addSystemMessage')
			->with($chat, Attendee::ACTOR_USERS, 'user', $this->anything(), $this->anything(), false, null, 123456)
			->willReturn($systemMessage);

		$this->assertSame($systemMessage, $chatManager->deleteMessage($chat, $comment, $participant, $date));
	}

	public function testDeleteMessageFileShareNotFound(): void {
		$mapper = new AttendeeMapper(\OC::$server->getDatabaseConnection());
		$attendee = $mapper->createAttendeeFromRow([
			'a_id' => 1,
			'room_id' => 123,
			'actor_type' => Attendee::ACTOR_USERS,
			'actor_id' => 'user',
			'display_name' => 'user-display',
			'pin' => '',
			'participant_type' => Participant::USER,
			'favorite' => true,
			'notification_level' => Participant::NOTIFY_MENTION,
			'notification_calls' => Participant::NOTIFY_CALLS_ON,
			'last_joined_call' => 0,
			'last_read_message' => 0,
			'last_mention_message' => 0,
			'last_mention_direct' => 0,
			'read_privacy' => Participant::PRIVACY_PUBLIC,
			'permissions' => Attendee::PERMISSIONS_DEFAULT,
			'access_token' => '',
			'remote_id' => '',
		]);
		$chat = $this->createMock(Room::class);
		$chat->expects($this->any())
			->method('getId')
			->willReturn(1234);
		$participant = new Participant($chat, $attendee, null);

		$date = new \DateTime();

		$comment = $this->createMock(IComment::class);
		$comment->method('getId')
			->willReturn('123456');
		$comment->method('getVerb')
			->willReturn('object_shared');
		$comment->expects($this->once())
			->method('getMessage')
			->willReturn(json_encode(['message' => 'file_shared', 'parameters' => ['share' => '42']]));

		$this->shareManager->method('getShareById')
			->with('ocRoomShare:42')
			->willThrowException(new ShareNotFound());

		$this->commentsManager->expects($this->never())
			->method('save');

		$systemMessage = $this->createMock(IComment::class);

		$chatManager = $this->getManager(['addSystemMessage']);
		$chatManager->expects($this->never())
			->method('addSystemMessage');

		$this->expectException(ShareNotFound::class);
		$this->assertSame($systemMessage, $chatManager->deleteMessage($chat, $comment, $participant, $date));
	}

	public function testClearHistory(): void {
		$chat = $this->createMock(Room::class);
		$chat->expects($this->any())
			->method('getId')
			->willReturn(1234);
		$chat->expects($this->any())
			->method('getToken')
			->willReturn('t0k3n');

		$this->commentsManager->expects($this->once())
			->method('deleteCommentsAtObject')
			->with('chat', 1234);

		$this->shareProvider->expects($this->once())
			->method('deleteInRoom')
			->with('t0k3n');

		$this->notifier->expects($this->once())
			->method('removePendingNotificationsForRoom')
			->with($chat, true);

		$this->participantService->expects($this->once())
			->method('resetChatDetails')
			->with($chat);

		$date = new \DateTime();
		$this->timeFactory->method('getDateTime')
			->willReturn($date);

		$manager = $this->getManager(['addSystemMessage']);
		$manager->expects($this->once())
			->method('addSystemMessage')
			->with(
				$chat,
				'users',
				'admin',
				json_encode(['message' => 'history_cleared', 'parameters' => []]),
				$date,
				false
			);
		$manager->clearHistory($chat, 'users', 'admin');
	}

	public function dataSearchIsPartOfConversationNameOrAtAll(): array {
		return [
			['a', 'test', true],
			['h', 'test', true],
			['A', 'test', false],
			['H', 'test', false],
			['T', 'test', true],
			['t', 'test', true],
			['notbeginingall', 'test', false],
			['notbegininghere', 'test', false],
			['notbeginingtest', 'test', false]
		];
	}

	/**
	 * @dataProvider dataSearchIsPartOfConversationNameOrAtAll
	 */
	public function testSearchIsPartOfConversationNameOrAtAll(string $search, string $roomDisplayName, bool $expected): void {
		$actual = self::invokePrivate($this->chatManager, 'searchIsPartOfConversationNameOrAtAll', [$search, $roomDisplayName]);
		$this->assertEquals($expected, $actual);
	}

	public function dataAddConversationNotify(): array {
		return [
			[
				'',
				['getType' => Room::TYPE_ONE_TO_ONE],
				[],
				[]
			],
			[
				'',
				['getDisplayName' => 'test'],
				['getAttendee' => Attendee::fromRow([
					'actor_type' => Attendee::ACTOR_USERS,
					'actor_id' => 'user',
				])],
				[['id' => 'all', 'label' => 'test', 'source' => 'calls']]
			],
			[
				'all',
				['getDisplayName' => 'test'],
				['getAttendee' => Attendee::fromRow([
					'actor_type' => Attendee::ACTOR_USERS,
					'actor_id' => 'user',
				])],
				[['id' => 'all', 'label' => 'test', 'source' => 'calls']]
			],
			[
				'here',
				['getDisplayName' => 'test'],
				['getAttendee' => Attendee::fromRow([
					'actor_type' => Attendee::ACTOR_GUESTS,
					'actor_id' => 'guest',
				])],
				[['id' => 'all', 'label' => 'test', 'source' => 'calls']]
			]
		];
	}

	/**
	 * @dataProvider dataAddConversationNotify
	 */
	public function testAddConversationNotify($search, $roomMocks, $participantMocks, $expected) {
		$room = $this->createMock(Room::class);
		foreach ($roomMocks as $method => $return) {
			$room->expects($this->once())
				->method($method)
				->willReturn($return);
		}

		$participant = $this->createMock(Participant::class);
		foreach ($participantMocks as $method => $return) {
			$participant->expects($this->once())
				->method($method)
				->willReturn($return);
		}

		$actual = $this->chatManager->addConversationNotify([], $search, $room, $participant);
		$this->assertEquals($expected, $actual);
	}
}
