Feature: conversation/set-listable
  Background:
    Given user "creator" exists
    Given user "regular-user" exists

  Scenario Outline: Setting listable attribute
    Given user "creator" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    When user "creator" allows listing room "room" for "<listable>" with 200 (v4)
    Then user "creator" is participant of the following rooms (v4)
      | id   | type | listable   |
      | room | 2    | <listable> |
    Examples:
      | listable |
      | 0        |
      | 1        |
      | 2        |

  Scenario: Cannot set invalid listable attribute value
    Given user "creator" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    Then user "creator" allows listing room "room" for "5" with 400 (v4)

  Scenario: Only moderators and owners can change listable attribute
    Given user "creator" creates room "room" (v4)
      | roomType | 3 |
      | roomName | room |
    And user "moderator" exists
    And guest accounts can be created
    And user "user-guest@example.com" is a guest account user
    And user "creator" adds user "regular-user" to room "room" with 200 (v4)
    And user "creator" adds user "moderator" to room "room" with 200 (v4)
    And user "creator" allows listing room "room" for "all" with 200 (v4)
    When user "creator" promotes "moderator" in room "room" with 200 (v4)
    And user "user-guest@example.com" joins room "room" with 200 (v4)
    And user "guest" joins room "room" with 200 (v4)
    Then user "moderator" allows listing room "room" for "none" with 200 (v4)
    And user "regular-user" allows listing room "room" for "users" with 403 (v4)
    And user "user-guest@example.com" allows listing room "room" for "users" with 403 (v4)
    And user "guest" allows listing room "room" for "users" with 401 (v4)

  Scenario: Cannot change listable attribute of one to one conversations
    Given user "creator" creates room "room" (v4)
      | roomType | 1            |
      | invite   | regular-user |
    Then user "creator" allows listing room "room" for "all" with 400 (v4)
    And user "regular-user" allows listing room "room" for "all" with 400 (v4)

  Scenario: Cannot change listable attribute on a file conversation
    Given user "creator" logs in
    And user "creator" shares "welcome.txt" with user "regular-user" with OCS 100
    And user "regular-user" accepts last share
    When user "creator" gets the room for path "welcome.txt" with 200 (v1)
    And user "creator" joins room "file welcome.txt room" with 200 (v4)
    Then user "creator" allows listing room "file welcome.txt room" for "all" with 403 (v4)
