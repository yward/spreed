Feature: chat/public
  Background:
    Given user "participant1" exists
    Given user "participant2" exists

  Scenario: Share a file to a chat
    Given user "participant1" creates room "public room" (v4)
      | roomType | 3 |
      | roomName | room |
    When user "participant1" shares "welcome.txt" with room "public room"
    Then user "participant1" sees the following messages in room "public room" with 200
      | room        | actorType | actorId      | actorDisplayName         | message  | messageParameters |
      | public room | users     | participant1 | participant1-displayname | {file}   | "IGNORE"          |

  Scenario: Can not share a file without chat permission
    Given user "participant1" creates room "public room" (v4)
      | roomType | 3 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "public room" with 200 (v4)
    # Removing chat permission only
    Then user "participant1" sets permissions for "participant2" in room "public room" to "CSJLAVP" with 200 (v4)
    When user "participant2" shares "welcome.txt" with room "public room"
    And the OCS status code should be 404
    Then user "participant1" sees the following messages in room "public room" with 200
      | room        | actorType | actorId      | actorDisplayName         | message  | messageParameters |

  Scenario: Delete share a file message from a chat
    Given user "participant1" creates room "public room" (v4)
      | roomType | 3 |
      | roomName | room |
    When user "participant1" shares "welcome.txt" with room "public room"
    Then user "participant1" sees the following messages in room "public room" with 200
      | room        | actorType | actorId      | actorDisplayName         | message  | messageParameters |
      | public room | users     | participant1 | participant1-displayname | {file}   | "IGNORE"          |
    And user "participant1" deletes message "shared::file::welcome.txt" from room "public room" with 200
    Then user "participant1" sees the following messages in room "public room" with 200
      | room        | actorType | actorId      | actorDisplayName         | message                | messageParameters |
      | public room | users     | participant1 | participant1-displayname | Message deleted by you | {"actor":{"type":"user","id":"participant1","name":"participant1-displayname"}} |

  Scenario: Can not delete a share file message without chat permission
    Given user "participant1" creates room "public room" (v4)
      | roomType | 3 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "public room" with 200 (v4)
    When user "participant2" shares "welcome.txt" with room "public room"
    Then user "participant1" sees the following messages in room "public room" with 200
      | room        | actorType | actorId      | actorDisplayName         | message  | messageParameters |
      | public room | users     | participant2 | participant2-displayname | {file}   | "IGNORE"          |
    # Removing chat permission only
    Then user "participant1" sets permissions for "participant2" in room "public room" to "CSJLAVP" with 200 (v4)
    And user "participant2" deletes message "shared::file::welcome.txt" from room "public room" with 403
    Then user "participant1" sees the following messages in room "public room" with 200
      | room        | actorType | actorId      | actorDisplayName         | message  | messageParameters |
      | public room | users     | participant2 | participant2-displayname | {file}   | "IGNORE"          |
