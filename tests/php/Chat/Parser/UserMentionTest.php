<?php

declare(strict_types=1);
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

namespace OCA\Talk\Tests\php\Chat\Parser;

use OCA\Talk\Chat\Parser\UserMention;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\GuestManager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Message;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class UserMentionTest extends TestCase {

	/** @var ICommentsManager|MockObject */
	protected $commentsManager;
	/** @var IUserManager|MockObject */
	protected $userManager;
	/** @var GuestManager|MockObject */
	protected $guestManager;
	/** @var IL10N|MockObject */
	protected $l;

	protected ?UserMention $parser = null;

	public function setUp(): void {
		parent::setUp();

		$this->commentsManager = $this->createMock(ICommentsManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->guestManager = $this->createMock(GuestManager::class);
		$this->l = $this->createMock(IL10N::class);

		$this->parser = new UserMention(
			$this->commentsManager,
			$this->userManager,
			$this->guestManager,
			$this->l);
	}

	/**
	 * @param array $mentions
	 * @return MockObject|IComment
	 */
	private function newComment(array $mentions): IComment {
		$comment = $this->createMock(IComment::class);

		$comment->method('getMentions')->willReturn($mentions);

		return $comment;
	}

	public function testGetRichMessageWithoutEnrichableReferences() {
		$comment = $this->newComment([]);

		/** @var Room|MockObject $room */
		$room = $this->createMock(Room::class);
		/** @var Participant|MockObject $participant */
		$participant = $this->createMock(Participant::class);
		/** @var IL10N|MockObject $l */
		$l = $this->createMock(IL10N::class);
		$chatMessage = new Message($room, $participant, $comment, $l);
		$chatMessage->setMessage('Message without enrichable references', []);

		$this->parser->parseMessage($chatMessage);

		$this->assertEquals('Message without enrichable references', $chatMessage->getMessage());
		$this->assertEquals([], $chatMessage->getMessageParameters());
	}

	public function testGetRichMessageWithSingleMention() {
		$mentions = [
			['type' => 'user', 'id' => 'testUser'],
		];
		$comment = $this->newComment($mentions);

		$this->commentsManager->expects($this->once())
			->method('resolveDisplayName')
			->with('user', 'testUser')
			->willReturn('testUser display name');

		$this->userManager->expects($this->once())
			->method('get')
			->with('testUser')
			->willReturn($this->createMock(IUser::class));

		/** @var Room|MockObject $room */
		$room = $this->createMock(Room::class);
		/** @var Participant|MockObject $participant */
		$participant = $this->createMock(Participant::class);
		/** @var IL10N|MockObject $l */
		$l = $this->createMock(IL10N::class);
		$chatMessage = new Message($room, $participant, $comment, $l);
		$chatMessage->setMessage('Mention to @testUser', []);

		$this->parser->parseMessage($chatMessage);

		$expectedMessageParameters = [
			'mention-user1' => [
				'type' => 'user',
				'id' => 'testUser',
				'name' => 'testUser display name'
			]
		];

		$this->assertEquals('Mention to {mention-user1}', $chatMessage->getMessage());
		$this->assertEquals($expectedMessageParameters, $chatMessage->getMessageParameters());
	}

	public function testGetRichMessageWithDuplicatedMention() {
		$mentions = [
			['type' => 'user', 'id' => 'testUser'],
		];
		$comment = $this->newComment($mentions);

		$this->commentsManager->expects($this->once())
			->method('resolveDisplayName')
			->with('user', 'testUser')
			->willReturn('testUser display name');

		$this->userManager->expects($this->once())
			->method('get')
			->with('testUser')
			->willReturn($this->createMock(IUser::class));

		/** @var Room|MockObject $room */
		$room = $this->createMock(Room::class);
		/** @var Participant|MockObject $participant */
		$participant = $this->createMock(Participant::class);
		/** @var IL10N|MockObject $l */
		$l = $this->createMock(IL10N::class);
		$chatMessage = new Message($room, $participant, $comment, $l);
		$chatMessage->setMessage('Mention to @testUser and @testUser again', []);

		$this->parser->parseMessage($chatMessage);

		$expectedMessageParameters = [
			'mention-user1' => [
				'type' => 'user',
				'id' => 'testUser',
				'name' => 'testUser display name'
			]
		];

		$this->assertEquals('Mention to {mention-user1} and {mention-user1} again', $chatMessage->getMessage());
		$this->assertEquals($expectedMessageParameters, $chatMessage->getMessageParameters());
	}

	public function dataGetRichMessageWithMentionsFullyIncludedInOtherMentions() {
		// Based on valid characters from server/lib/private/User/Manager.php
		return [
			['testUser', 'testUser1', false],
			['testUser', 'testUser1', true],
			['testUser', 'testUser_1', false],
			['testUser', 'testUser_1', true],
			['testUser', 'testUser.1', false],
			['testUser', 'testUser.1', true],
			['testUser', 'testUser@1', false],
			['testUser', 'testUser@1', true],
			['testUser', 'testUser-1', false],
			['testUser', 'testUser-1', true],
			['testUser', 'testUser\'1', false],
			['testUser', 'testUser\'1', true],
		];
	}

	/**
	 * @dataProvider dataGetRichMessageWithMentionsFullyIncludedInOtherMentions
	 */
	public function testGetRichMessageWithMentionsFullyIncludedInOtherMentions(string $baseId, string $longerId, bool $quoted) {
		$mentions = [
			['type' => 'user', 'id' => $baseId],
			['type' => 'user', 'id' => $longerId],
		];
		$comment = $this->newComment($mentions);

		$this->commentsManager->expects($this->exactly(2))
			->method('resolveDisplayName')
			->willReturnCallback(function ($type, $id) {
				return $id . ' display name';
			});

		$this->userManager->expects($this->exactly(2))
			->method('get')
			->withConsecutive(
				[$longerId],
				[$baseId]
			)
			->willReturn($this->createMock(IUser::class));

		/** @var Room|MockObject $room */
		$room = $this->createMock(Room::class);
		/** @var Participant|MockObject $participant */
		$participant = $this->createMock(Participant::class);
		/** @var IL10N|MockObject $l */
		$l = $this->createMock(IL10N::class);
		$chatMessage = new Message($room, $participant, $comment, $l);
		if ($quoted) {
			$chatMessage->setMessage('Mention to @"' . $baseId . '" and @"' . $longerId . '"', []);
		} else {
			$chatMessage->setMessage('Mention to @' . $baseId . ' and @' . $longerId, []);
		}

		$this->parser->parseMessage($chatMessage);

		$expectedMessageParameters = [
			'mention-user1' => [
				'type' => 'user',
				'id' => $longerId,
				'name' => $longerId . ' display name'
			],
			'mention-user2' => [
				'type' => 'user',
				'id' => $baseId,
				'name' => $baseId . ' display name'
			],
		];

		$this->assertEquals('Mention to {mention-user2} and {mention-user1}', $chatMessage->getMessage());
		$this->assertEquals($expectedMessageParameters, $chatMessage->getMessageParameters());
	}

	public function testGetRichMessageWithSeveralMentions() {
		$mentions = [
			['type' => 'user', 'id' => 'testUser1'],
			['type' => 'user', 'id' => 'testUser2'],
			['type' => 'user', 'id' => 'testUser3']
		];
		$comment = $this->newComment($mentions);

		$this->commentsManager->expects($this->exactly(3))
			->method('resolveDisplayName')
			->withConsecutive(
				['user', 'testUser1'],
				['user', 'testUser2'],
				['user', 'testUser3']
			)
			->willReturn(
				'testUser1 display name',
				'testUser2 display name',
				'testUser3 display name'
			);

		$this->userManager->expects($this->exactly(3))
			->method('get')
			->withConsecutive(
				['testUser1'],
				['testUser2'],
				['testUser3']
			)
			->willReturn($this->createMock(IUser::class));

		/** @var Room|MockObject $room */
		$room = $this->createMock(Room::class);
		/** @var Participant|MockObject $participant */
		$participant = $this->createMock(Participant::class);
		/** @var IL10N|MockObject $l */
		$l = $this->createMock(IL10N::class);
		$chatMessage = new Message($room, $participant, $comment, $l);
		$chatMessage->setMessage('Mention to @testUser1, @testUser2, @testUser1 again and @testUser3', []);

		$this->parser->parseMessage($chatMessage);

		$expectedMessageParameters = [
			'mention-user1' => [
				'type' => 'user',
				'id' => 'testUser1',
				'name' => 'testUser1 display name'
			],
			'mention-user2' => [
				'type' => 'user',
				'id' => 'testUser2',
				'name' => 'testUser2 display name'
			],
			'mention-user3' => [
				'type' => 'user',
				'id' => 'testUser3',
				'name' => 'testUser3 display name'
			]
		];

		$this->assertEquals('Mention to {mention-user1}, {mention-user2}, {mention-user1} again and {mention-user3}', $chatMessage->getMessage());
		$this->assertEquals($expectedMessageParameters, $chatMessage->getMessageParameters());
	}

	public function testGetRichMessageWithNonExistingUserMention() {
		$mentions = [
			['type' => 'user', 'id' => 'me'],
			['type' => 'user', 'id' => 'testUser'],
		];
		$comment = $this->newComment($mentions);

		$this->commentsManager->expects($this->once())
			->method('resolveDisplayName')
			->with('user', 'testUser')
			->willReturn('testUser display name');

		$this->userManager->expects($this->exactly(2))
			->method('get')
			->willReturnMap([
				['me', null],
				['testUser', $this->createMock(IUser::class)],
			]);

		/** @var Room|MockObject $room */
		$room = $this->createMock(Room::class);
		/** @var Participant|MockObject $participant */
		$participant = $this->createMock(Participant::class);
		/** @var IL10N|MockObject $l */
		$l = $this->createMock(IL10N::class);
		$chatMessage = new Message($room, $participant, $comment, $l);
		$chatMessage->setMessage('Mention @me to @testUser', []);

		$this->parser->parseMessage($chatMessage);

		$expectedMessageParameters = [
			'mention-user1' => [
				'type' => 'user',
				'id' => 'testUser',
				'name' => 'testUser display name'
			]
		];

		$this->assertEquals('Mention @me to {mention-user1}', $chatMessage->getMessage());
		$this->assertEquals($expectedMessageParameters, $chatMessage->getMessageParameters());
	}

	public function testGetRichMessageWhenDisplayNameCanNotBeResolved() {
		$mentions = [
			['type' => 'user', 'id' => 'testUser'],
		];
		$comment = $this->newComment($mentions);

		$this->commentsManager->expects($this->once())
			->method('resolveDisplayName')
			->willThrowException(new \OutOfBoundsException());

		$this->userManager->expects($this->once())
			->method('get')
			->with('testUser')
			->willReturn($this->createMock(IUser::class));

		/** @var Room|MockObject $room */
		$room = $this->createMock(Room::class);
		/** @var Participant|MockObject $participant */
		$participant = $this->createMock(Participant::class);
		/** @var IL10N|MockObject $l */
		$l = $this->createMock(IL10N::class);
		$chatMessage = new Message($room, $participant, $comment, $l);
		$chatMessage->setMessage('Mention to @testUser', []);

		$this->parser->parseMessage($chatMessage);

		$expectedMessageParameters = [
			'mention-user1' => [
				'type' => 'user',
				'id' => 'testUser',
				'name' => ''
			]
		];

		$this->assertEquals('Mention to {mention-user1}', $chatMessage->getMessage());
		$this->assertEquals($expectedMessageParameters, $chatMessage->getMessageParameters());
	}

	public function testGetRichMessageWithAtAll(): void {
		$mentions = [
			['type' => 'user', 'id' => 'all'],
		];
		$comment = $this->newComment($mentions);

		/** @var Room|MockObject $room */
		$room = $this->createMock(Room::class);
		$room->expects($this->once())
			->method('getType')
			->willReturn(Room::TYPE_GROUP);
		$room->expects($this->once())
			->method('getToken')
			->willReturn('token');
		$room->expects($this->once())
			->method('getDisplayName')
			->willReturn('name');
		/** @var Participant|MockObject $participant */
		$participant = $this->createMock(Participant::class);
		/** @var IL10N|MockObject $l */
		$l = $this->createMock(IL10N::class);
		$chatMessage = new Message($room, $participant, $comment, $l);
		$chatMessage->setMessage('Mention to @all', []);

		$this->parser->parseMessage($chatMessage);

		$expectedMessageParameters = [
			'mention-call1' => [
				'type' => 'call',
				'id' => 'token',
				'name' => 'name',
				'call-type' => 'group',
			]
		];

		$this->assertEquals('Mention to {mention-call1}', $chatMessage->getMessage());
		$this->assertEquals($expectedMessageParameters, $chatMessage->getMessageParameters());
	}

	public function testGetRichMessageWhenAGuestWithoutNameIsMentioned(): void {
		$mentions = [
			['type' => 'guest', 'id' => 'guest/123456'],
		];
		$comment = $this->newComment($mentions);

		/** @var Room|MockObject $room */
		$room = $this->createMock(Room::class);
		/** @var Participant|MockObject $participant */
		$participant = $this->createMock(Participant::class);
		/** @var IL10N|MockObject $l */
		$l = $this->createMock(IL10N::class);

		$room->method('getParticipantByActor')
			->with(Attendee::ACTOR_GUESTS, '123456')
			->willThrowException(new ParticipantNotFoundException());
		$this->l->expects($this->any())
			->method('t')
			->willReturnCallback(function ($text, $parameters = []) {
				return vsprintf($text, $parameters);
			});

		$chatMessage = new Message($room, $participant, $comment, $l);
		$chatMessage->setMessage('Mention to @"guest/123456"', []);

		$this->parser->parseMessage($chatMessage);

		$expectedMessageParameters = [
			'mention-guest1' => [
				'type' => 'guest',
				'id' => 'guest/123456',
				'name' => 'Guest',
			]
		];

		$this->assertEquals('Mention to {mention-guest1}', $chatMessage->getMessage());
		$this->assertEquals($expectedMessageParameters, $chatMessage->getMessageParameters());
	}

	public function testGetRichMessageWhenAGuestWithoutNameIsMentionedMultipleTimes(): void {
		$mentions = [
			['type' => 'guest', 'id' => 'guest/123456'],
		];
		$comment = $this->newComment($mentions);

		/** @var Room|MockObject $room */
		$room = $this->createMock(Room::class);
		/** @var Participant|MockObject $participant */
		$participant = $this->createMock(Participant::class);
		/** @var IL10N|MockObject $l */
		$l = $this->createMock(IL10N::class);

		$room->method('getParticipantByActor')
			->with(Attendee::ACTOR_GUESTS, '123456')
			->willThrowException(new ParticipantNotFoundException());
		$this->l->expects($this->any())
			->method('t')
			->willReturnCallback(function ($text, $parameters = []) {
				return vsprintf($text, $parameters);
			});

		$chatMessage = new Message($room, $participant, $comment, $l);
		$chatMessage->setMessage('Mention to @"guest/123456", and again @"guest/123456"', []);

		$this->parser->parseMessage($chatMessage);

		$expectedMessageParameters = [
			'mention-guest1' => [
				'type' => 'guest',
				'id' => 'guest/123456',
				'name' => 'Guest',
			]
		];

		$this->assertEquals('Mention to {mention-guest1}, and again {mention-guest1}', $chatMessage->getMessage());
		$this->assertEquals($expectedMessageParameters, $chatMessage->getMessageParameters());
	}

	public function testGetRichMessageWhenAGuestWithANameIsMentionedMultipleTimes(): void {
		$mentions = [
			['type' => 'guest', 'id' => 'guest/abcdef'],
		];
		$comment = $this->newComment($mentions);

		/** @var Room|MockObject $room */
		$room = $this->createMock(Room::class);
		/** @var Participant|MockObject $participant */
		$participant = $this->createMock(Participant::class);
		/** @var IL10N|MockObject $l */
		$l = $this->createMock(IL10N::class);

		$attendee = Attendee::fromRow([
			'actor_type' => 'guests',
			'actor_id' => 'abcdef',
			'display_name' => 'Name',
		]);
		$participant->method('getAttendee')
			->willReturn($attendee);

		$room->method('getParticipantByActor')
			->with(Attendee::ACTOR_GUESTS, 'abcdef')
			->willReturn($participant);
		$this->l->expects($this->any())
			->method('t')
			->willReturnCallback(function ($text, $parameters = []) {
				return vsprintf($text, $parameters);
			});

		$chatMessage = new Message($room, $participant, $comment, $l);
		$chatMessage->setMessage('Mention to @"guest/abcdef", and again @"guest/abcdef"', []);

		$this->parser->parseMessage($chatMessage);

		$expectedMessageParameters = [
			'mention-guest1' => [
				'type' => 'guest',
				'id' => 'guest/abcdef',
				'name' => 'Name',
			]
		];

		$this->assertEquals('Mention to {mention-guest1}, and again {mention-guest1}', $chatMessage->getMessage());
		$this->assertEquals($expectedMessageParameters, $chatMessage->getMessageParameters());
	}
}
