<?php

/**
 *
 * @copyright Copyright (c) 2017, Daniel Calviño Sánchez (danxuliu@gmail.com)
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

use OC\Comments\Comment;
use OCA\Talk\Chat\Notifier;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Files\Util;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Session;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\IConfig;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class NotifierTest extends TestCase {

	/** @var INotificationManager|MockObject */
	protected $notificationManager;
	/** @var IUserManager|MockObject */
	protected $userManager;
	/** @var ParticipantService|MockObject */
	protected $participantService;
	/** @var Manager|MockObject */
	protected $manager;
	/** @var IConfig|MockObject */
	protected $config;
	/** @var ITimeFactory|MockObject */
	protected $timeFactory;
	/** @var Util|MockObject */
	protected $util;

	public function setUp(): void {
		parent::setUp();

		$this->notificationManager = $this->createMock(INotificationManager::class);

		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager
			->method('userExists')
			->willReturnCallback(function ($userId) {
				return $userId !== 'unknownUser';
			});

		$this->participantService = $this->createMock(ParticipantService::class);
		$this->manager = $this->createMock(Manager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->util = $this->createMock(Util::class);
	}

	/**
	 * @param string[] $methods
	 * @return Notifier|MockObject
	 */
	protected function getNotifier(array $methods = []) {
		if (!empty($methods)) {
			return $this->getMockBuilder(Notifier::class)
				->setConstructorArgs([
					$this->notificationManager,
					$this->userManager,
					$this->participantService,
					$this->manager,
					$this->config,
					$this->timeFactory,
					$this->util,
				])
				->onlyMethods($methods)
				->getMock();
		}
		return new Notifier(
			$this->notificationManager,
			$this->userManager,
			$this->participantService,
			$this->manager,
			$this->config,
			$this->timeFactory,
			$this->util
		);
	}

	private function newComment($id, $actorType, $actorId, $creationDateTime, $message): IComment {
		$comment = new Comment([
			'id' => $id,
			'object_id' => '1234',
			'object_type' => 'chat',
			'actor_type' => $actorType,
			'actor_id' => $actorId,
			'creation_date_time' => $creationDateTime,
			'message' => $message,
			'verb' => 'comment',
		]);

		return $comment;
	}

	/**
	 * @return Room|MockObject
	 */
	private function getRoom($settings = []) {
		/** @var Room|MockObject */
		$room = $this->createMock(Room::class);

		$room->expects($this->any())
			->method('getParticipant')
			->willReturnCallback(function (string $actorId) use ($room, $settings): Participant {
				if ($actorId === 'userNotInOneToOneChat') {
					throw new ParticipantNotFoundException();
				}
				$attendeeRow = [
					'actor_type' => 'user',
					'actor_id' => $actorId,
				];
				if (isset($settings['attendee'][$actorId])) {
					$attendeeRow = array_merge($attendeeRow, $settings['attendee'][$actorId]);
				}
				$attendee = Attendee::fromRow($attendeeRow);
				return new Participant($room, $attendee, null);
			});

		$this->manager->expects($this->any())
			->method('getRoomById')
			->willReturn($room);
		return $room;
	}

	/**
	 * @dataProvider dataNotifyMentionedUsers
	 */
	public function testNotifyMentionedUsers(string $message, array $alreadyNotifiedUsers, array $notify, array $expectedReturn): void {
		if (count($notify)) {
			$this->notificationManager->expects($this->exactly(count($notify)))
			->method('notify');
		}

		$room = $this->getRoom();
		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016), $message);
		$notifier = $this->getNotifier([]);
		$alreadyNotifiedUsers = array_map(function ($user): array {
			return [
				'id' => $user,
				'type' => Attendee::ACTOR_USERS,
			];
		}, $alreadyNotifiedUsers);
		$expectedReturn = array_map(function ($user): array {
			return [
				'id' => $user,
				'type' => Attendee::ACTOR_USERS,
			];
		}, $expectedReturn);
		$actual = $notifier->notifyMentionedUsers($room, $comment, $alreadyNotifiedUsers);

		$this->assertEqualsCanonicalizing($expectedReturn, $actual);
	}

	public function dataNotifyMentionedUsers(): array {
		return [
			'no notifications' => [
				'No mentions', [], [], [],
			],
			'notify an unotified user' => [
				'Mention @anotherUser', [], ['anotherUser'], ['anotherUser'],
			],
			'not notify mentioned user if already notified' => [
				'Mention @anotherUser', ['anotherUser'], [], ['anotherUser'],
			],
			'notify mentioned Users With Long Message Start Mention' => [
				'123456789 @anotherUserWithOddLengthName 123456789-123456789-123456789-123456789-123456789-123456789', [], ['anotherUserWithOddLengthName'], ['anotherUserWithOddLengthName'],
			],
			'notify mentioned users with long message middle mention' => [
				'123456789-123456789-123456789-1234 @anotherUserWithOddLengthName 6789-123456789-123456789-123456789', [], ['anotherUserWithOddLengthName'], ['anotherUserWithOddLengthName'],
			],
			'notify mentioned users with long message end mention' => [
				'123456789-123456789-123456789-123456789-123456789-123456789 @anotherUserWithOddLengthName 123456789', [], ['anotherUserWithOddLengthName'], ['anotherUserWithOddLengthName'],
			],
			'mention herself' => [
				'Mention @testUser', [], [], [],
			],
			'not notify unknownuser' => [
				'Mention @unknownUser', [], [], [],
			],
			'notify mentioned users several mentions' => [
				'Mention @anotherUser, and @unknownUser, and @testUser, and @userAbleToJoin', [], ['anotherUser', 'userAbleToJoin'], ['anotherUser', 'userAbleToJoin'],
			],
			'notify mentioned users to user not invited to chat' => [
				'Mention @userNotInOneToOneChat', [], [], [],
			]
		];
	}

	public function dataShouldParticipantBeNotified(): array {
		return [
			[Attendee::ACTOR_GROUPS, 'test1', null, Attendee::ACTOR_USERS, 'test1', [], false],
			[Attendee::ACTOR_USERS, 'test1', null, Attendee::ACTOR_USERS, 'test1', [], false],
			[Attendee::ACTOR_USERS, 'test1', null, Attendee::ACTOR_USERS, 'test2', [], true],
			[Attendee::ACTOR_USERS, 'test1', null, Attendee::ACTOR_USERS, 'test2', [['id' => 'test1', 'type' => Attendee::ACTOR_USERS]], false],
			[Attendee::ACTOR_USERS, 'test1', null, Attendee::ACTOR_USERS, 'test2', [['id' => 'test1', 'type' => Attendee::ACTOR_FEDERATED_USERS]], true],
			[Attendee::ACTOR_USERS, 'test1', Session::SESSION_TIMEOUT - 5, Attendee::ACTOR_USERS, 'test2', [], false],
			[Attendee::ACTOR_USERS, 'test1', Session::SESSION_TIMEOUT + 5, Attendee::ACTOR_USERS, 'test2', [], true],
		];
	}

	/**
	 * @dataProvider dataShouldParticipantBeNotified
	 * @param string $actorType
	 * @param string $actorId
	 * @param int|null $sessionAge
	 * @param string $commentActorType
	 * @param string $commentActorId
	 * @param array $alreadyNotifiedUsers
	 * @param bool $expected
	 */
	public function testShouldParticipantBeNotified(string $actorType, string $actorId, ?int $sessionAge, string $commentActorType, string $commentActorId, array $alreadyNotifiedUsers, bool $expected): void {
		$comment = $this->createMock(IComment::class);
		$comment->method('getActorType')
			->willReturn($commentActorType);
		$comment->method('getActorId')
			->willReturn($commentActorId);

		$room = $this->createMock(Room::class);
		$attendee = Attendee::fromRow([
			'actor_type' => $actorType,
			'actor_id' => $actorId,
		]);
		$session = null;
		if ($sessionAge !== null) {
			$current = 1234567;
			$this->timeFactory->method('getTime')
				->willReturn($current);

			$session = Session::fromRow([
				'last_ping' => $current - $sessionAge,
			]);
		}
		$participant = new Participant($room, $attendee, $session);

		self::assertSame($expected, self::invokePrivate($this->getNotifier(), 'shouldParticipantBeNotified', [$participant, $comment, $alreadyNotifiedUsers]));
	}

	public function testRemovePendingNotificationsForRoom(): void {
		$notification = $this->createMock(INotification::class);

		$room = $this->createMock(Room::class);
		$room->expects($this->any())
			->method('getToken')
			->willReturn('Token123');

		$this->notificationManager->expects($this->once())
			->method('createNotification')
			->willReturn($notification);

		$notification->expects($this->once())
			->method('setApp')
			->with('spreed')
			->willReturnSelf();

		$notification->expects($this->exactly(3))
			->method('setObject')
			->withConsecutive(
				['chat', 'Token123'],
				['room', 'Token123'],
				['call', 'Token123']
			)
			->willReturnSelf();

		$this->notificationManager->expects($this->exactly(3))
			->method('markProcessed')
			->with($notification);

		$this->getNotifier()->removePendingNotificationsForRoom($room);
	}

	public function testRemovePendingNotificationsForChatOnly(): void {
		$notification = $this->createMock(INotification::class);

		$room = $this->createMock(Room::class);
		$room->expects($this->any())
			->method('getToken')
			->willReturn('Token123');

		$this->notificationManager->expects($this->once())
			->method('createNotification')
			->willReturn($notification);

		$notification->expects($this->once())
			->method('setApp')
			->with('spreed')
			->willReturnSelf();

		$notification->expects($this->exactly(1))
			->method('setObject')
			->with('chat', 'Token123')
			->willReturnSelf();

		$this->notificationManager->expects($this->exactly(1))
			->method('markProcessed')
			->with($notification);

		$this->getNotifier()->removePendingNotificationsForRoom($room, true);
	}

	/**
	 * @dataProvider dataAddMentionAllToList
	 */
	public function testAddMentionAllToList(array $usersToNotify, array $participants, array $return): void {
		$room = $this->createMock(Room::class);
		$this->participantService
			->method('getActorsByType')
			->willReturn($participants);

		$actual = $this->invokePrivate($this->getNotifier(), 'addMentionAllToList', [$room, $usersToNotify]);
		$this->assertCount(count($return), $actual);
		foreach ($actual as $key => $value) {
			$this->assertIsArray($value);
			if (key_exists('attendee', $value)) {
				$this->assertInstanceOf(Attendee::class, $value['attendee']);
				unset($value['attendee']);
			}
			$this->assertEqualsCanonicalizing($return[$key], $value);
		}
	}

	public function dataAddMentionAllToList(): array {
		return [
			'not notify' => [
				[],
				[],
				[],
			],
			'preserve notify list and do not notify all' => [
				[
					['id' => 'user1', 'type' => Attendee::ACTOR_USERS],
				],
				[],
				[
					['id' => 'user1', 'type' => Attendee::ACTOR_USERS],
				],
			],
			'mention all' => [
				[
					['id' => 'user1', 'type' => Attendee::ACTOR_USERS],
					['id' => 'all'],
				],
				[
					Attendee::fromRow(['actor_id' => 'user1', 'actor_type' => Attendee::ACTOR_USERS]),
					Attendee::fromRow(['actor_id' => 'user2', 'actor_type' => Attendee::ACTOR_USERS]),
				],
				[
					['id' => 'user1', 'type' => Attendee::ACTOR_USERS],
					['id' => 'user2', 'type' => Attendee::ACTOR_USERS],
				],
			],
		];
	}

	/**
	 * @dataProvider dataNotifyReacted
	 */
	public function testNotifyReacted(int $notify, int $notifyType, int $roomType, string $authorId): void {
		$this->notificationManager->expects($this->exactly($notify))
			->method('notify');

		$room = $this->getRoom([
			'attendee' => [
				'testUser' => [
					'notificationLevel' => $notifyType,
				]
			]
		]);
		$room->method('getType')
			->willReturn($roomType);
		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016), 'message');
		$reaction = $this->newComment('108', 'users', $authorId, new \DateTime('@' . 1000000016), 'message');

		$notifier = $this->getNotifier([]);
		$notifier->notifyReacted($room, $comment, $reaction);
	}

	public function dataNotifyReacted(): array {
		return [
			'author react to own message' =>
				[0, Participant::NOTIFY_MENTION, Room::TYPE_GROUP, 'testUser'],
			'notify never' =>
				[0, Participant::NOTIFY_NEVER, Room::TYPE_GROUP, 'testUser2'],
			'notify default, not one to one' =>
				[0, Participant::NOTIFY_DEFAULT, Room::TYPE_GROUP, 'testUser2'],
			'notify default, one to one' =>
				[1, Participant::NOTIFY_DEFAULT, Room::TYPE_ONE_TO_ONE, 'testUser2'],
			'notify always' =>
				[1, Participant::NOTIFY_ALWAYS, Room::TYPE_GROUP, 'testUser2'],
		];
	}

	/**
	 * @dataProvider dataGetMentionedUsers
	 */
	public function testGetMentionedUsers(string $message, array $expectedReturn): void {
		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016), $message);
		$actual = $this->invokePrivate($this->getNotifier(), 'getMentionedUsers', [$comment]);
		$this->assertEqualsCanonicalizing($expectedReturn, $actual);
	}

	public function dataGetMentionedUsers(): array {
		return [
			'mention one user' => [
				'Mention @anotherUser',
				[
					['id' => 'anotherUser', 'type' => 'users'],
				],
			],
			'mention two user' => [
				'Mention @anotherUser, and @unknownUser',
				[
					['id' => 'anotherUser', 'type' => 'users'],
					['id' => 'unknownUser', 'type' => 'users'],
				],
			],
			'mention all' => [
				'Mention @all',
				[
					['id' => 'all', 'type' => 'users'],
				],
			],
			'mention user, all, guest and group' => [
				'mention @test, @all, @"guest/1" @"group/1"',
				[
					['id' => 'test', 'type' => 'users'],
					['id' => 'all', 'type' => 'users'],
				],
			],
		];
	}

	/**
	 * @dataProvider dataGetMentionedUserIds
	 */
	public function testGetMentionedUserIds(string $message, array $expectedReturn): void {
		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016), $message);
		$actual = $this->invokePrivate($this->getNotifier(), 'getMentionedUserIds', [$comment]);
		$this->assertEqualsCanonicalizing($expectedReturn, $actual);
	}

	public function dataGetMentionedUserIds(): array {
		$return = $this->dataGetMentionedUsers();
		array_walk($return, function (array &$scenario) {
			array_walk($scenario[1], function (array &$params) {
				$params = $params['id'];
			});
			return $scenario;
		});
		return $return;
	}
}
