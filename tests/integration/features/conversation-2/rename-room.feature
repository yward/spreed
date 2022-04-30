Feature: public
  Background:
    Given user "participant1" exists
    Given user "participant2" exists
    Given user "participant3" exists

  Scenario: Owner renames
    Given user "participant1" creates room "room" (v4)
      | roomType | 3 |
      | roomName | room |
    And user "participant1" is participant of room "room" (v4)
    When user "participant1" renames room "room" to "new name" with 200 (v4)
    Then user "participant1" is participant of room "room" (v4)

  Scenario: Moderator renames
    Given user "participant1" creates room "room" (v4)
      | roomType | 3 |
      | roomName | room |
    And user "participant1" is participant of room "room" (v4)
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant2" is participant of room "room" (v4)
    And user "participant1" promotes "participant2" in room "room" with 200 (v4)
    When user "participant2" renames room "room" to "new name" with 200 (v4)

  Scenario: User renames
    Given user "participant1" creates room "room" (v4)
      | roomType | 3 |
      | roomName | room |
    And user "participant1" is participant of room "room" (v4)
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant2" is participant of room "room" (v4)
    When user "participant2" renames room "room" to "new name" with 403 (v4)

  Scenario: Stranger renames
    Given user "participant1" creates room "room" (v4)
      | roomType | 3 |
      | roomName | room |
    And user "participant1" is participant of room "room" (v4)
    And user "participant2" is not participant of room "room" (v4)
    When user "participant2" renames room "room" to "new name" with 404 (v4)
