Feature: set-publishing-permissions
  Background:
    Given user "owner" exists
    Given user "moderator" exists
    Given user "invited user" exists
    Given user "not invited user" exists
    Given user "not invited but joined user" exists
    Given user "not joined user" exists

  Scenario: owner can not set publishing permissions in one-to-one room
    Given user "owner" creates room "one-to-one room" (v4)
      | roomType | 1 |
      | invite   | moderator |
    And user "owner" loads attendees attendee ids in room "one-to-one room" (v4)
    When user "owner" sets publishing permissions for "owner" in room "one-to-one room" to "NONE" with 400 (v4)
    And user "owner" sets publishing permissions for "moderator" in room "one-to-one room" to "NONE" with 400 (v4)
    And user "moderator" sets publishing permissions for "owner" in room "one-to-one room" to "NONE" with 400 (v4)
    And user "moderator" sets publishing permissions for "moderator" in room "one-to-one room" to "NONE" with 400 (v4)
    Then user "owner" sees the following attendees in room "one-to-one room" with 200 (v4)
      | actorType  | actorId   | publishingPermissions |
      | users      | owner     | ALL                   |
      | users      | moderator | ALL                   |
    And user "moderator" sees the following attendees in room "one-to-one room" with 200 (v4)
      | actorType  | actorId   | publishingPermissions |
      | users      | owner     | ALL                   |
      | users      | moderator | ALL                   |



  Scenario: owner can set publishing permissions in group room
    Given user "owner" creates room "group room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "owner" adds user "moderator" to room "group room" with 200 (v4)
    And user "owner" promotes "moderator" in room "group room" with 200 (v4)
    And user "owner" adds user "invited user" to room "group room" with 200 (v4)
    And user "owner" loads attendees attendee ids in room "group room" (v4)
    When user "owner" sets publishing permissions for "owner" in room "group room" to "NONE" with 200 (v4)
    And user "owner" sets publishing permissions for "moderator" in room "group room" to "NONE" with 200 (v4)
    And user "owner" sets publishing permissions for "invited user" in room "group room" to "NONE" with 200 (v4)
    Then user "owner" sees the following attendees in room "group room" with 200 (v4)
      | actorType  | actorId      | publishingPermissions |
      | users      | owner        | NONE                  |
      | users      | moderator    | NONE                  |
      | users      | invited user | NONE                  |
    And user "moderator" sees the following attendees in room "group room" with 200 (v4)
      | actorType  | actorId      | publishingPermissions |
      | users      | owner        | NONE                  |
      | users      | moderator    | NONE                  |
      | users      | invited user | NONE                  |
    And user "invited user" sees the following attendees in room "group room" with 200 (v4)
      | actorType  | actorId      | publishingPermissions |
      | users      | owner        | NONE                  |
      | users      | moderator    | NONE                  |
      | users      | invited user | NONE                  |

  Scenario: moderator can set publishing permissions in group room
    Given user "owner" creates room "group room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "owner" adds user "moderator" to room "group room" with 200 (v4)
    And user "owner" promotes "moderator" in room "group room" with 200 (v4)
    And user "owner" adds user "invited user" to room "group room" with 200 (v4)
    And user "owner" loads attendees attendee ids in room "group room" (v4)
    When user "moderator" sets publishing permissions for "owner" in room "group room" to "NONE" with 200 (v4)
    And user "moderator" sets publishing permissions for "moderator" in room "group room" to "NONE" with 200 (v4)
    And user "moderator" sets publishing permissions for "invited user" in room "group room" to "NONE" with 200 (v4)
    Then user "owner" sees the following attendees in room "group room" with 200 (v4)
      | actorType  | actorId      | publishingPermissions |
      | users      | owner        | NONE                  |
      | users      | moderator    | NONE                  |
      | users      | invited user | NONE                  |
    And user "moderator" sees the following attendees in room "group room" with 200 (v4)
      | actorType  | actorId      | publishingPermissions |
      | users      | owner        | NONE                  |
      | users      | moderator    | NONE                  |
      | users      | invited user | NONE                  |
    And user "invited user" sees the following attendees in room "group room" with 200 (v4)
      | actorType  | actorId      | publishingPermissions |
      | users      | owner        | NONE                  |
      | users      | moderator    | NONE                  |
      | users      | invited user | NONE                  |

  Scenario: others can not set publishing permissions in group room
    Given user "owner" creates room "group room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "owner" adds user "moderator" to room "group room" with 200 (v4)
    And user "owner" promotes "moderator" in room "group room" with 200 (v4)
    And user "owner" adds user "invited user" to room "group room" with 200 (v4)
    And user "owner" loads attendees attendee ids in room "group room" (v4)
    When user "invited user" sets publishing permissions for "owner" in room "group room" to "NONE" with 403 (v4)
    And user "invited user" sets publishing permissions for "moderator" in room "group room" to "NONE" with 403 (v4)
    And user "invited user" sets publishing permissions for "invited user" in room "group room" to "NONE" with 403 (v4)
    And user "not invited user" sets publishing permissions for "owner" in room "group room" to "NONE" with 404 (v4)
    And user "not invited user" sets publishing permissions for "moderator" in room "group room" to "NONE" with 404 (v4)
    And user "not invited user" sets publishing permissions for "invited user" in room "group room" to "NONE" with 404 (v4)
    # Guest user names in tests must begin with "guest"
    And user "guest not joined" sets publishing permissions for "owner" in room "group room" to "NONE" with 404 (v4)
    And user "guest not joined" sets publishing permissions for "moderator" in room "group room" to "NONE" with 404 (v4)
    And user "guest not joined" sets publishing permissions for "invited user" in room "group room" to "NONE" with 404 (v4)
    Then user "owner" sees the following attendees in room "group room" with 200 (v4)
      | actorType  | actorId      | publishingPermissions |
      | users      | owner        | ALL                   |
      | users      | moderator    | ALL                   |
      | users      | invited user | ALL                   |
    And user "moderator" sees the following attendees in room "group room" with 200 (v4)
      | actorType  | actorId      | publishingPermissions |
      | users      | owner        | ALL                   |
      | users      | moderator    | ALL                   |
      | users      | invited user | ALL                   |
    And user "invited user" sees the following attendees in room "group room" with 200 (v4)
      | actorType  | actorId      | publishingPermissions |
      | users      | owner        | ALL                   |
      | users      | moderator    | ALL                   |
      | users      | invited user | ALL                   |



  Scenario: owner can set publishing permissions in public room
    Given user "owner" creates room "public room" (v4)
      | roomType | 3 |
      | roomName | room |
    And user "owner" adds user "moderator" to room "public room" with 200 (v4)
    And user "owner" promotes "moderator" in room "public room" with 200 (v4)
    And user "owner" adds user "invited user" to room "public room" with 200 (v4)
    And user "not invited but joined user" joins room "public room" with 200 (v4)
    And user "guest moderator" joins room "public room" with 200 (v4)
    And user "owner" promotes "guest moderator" in room "public room" with 200 (v4)
    And user "guest" joins room "public room" with 200 (v4)
    And user "owner" loads attendees attendee ids in room "public room" (v4)
    When user "owner" sets publishing permissions for "owner" in room "public room" to "NONE" with 200 (v4)
    And user "owner" sets publishing permissions for "moderator" in room "public room" to "NONE" with 200 (v4)
    And user "owner" sets publishing permissions for "invited user" in room "public room" to "NONE" with 200 (v4)
    And user "owner" sets publishing permissions for "not invited but joined user" in room "public room" to "NONE" with 200 (v4)
    And user "owner" sets publishing permissions for "guest moderator" in room "public room" to "NONE" with 200 (v4)
    And user "owner" sets publishing permissions for "guest" in room "public room" to "NONE" with 200 (v4)
    Then user "owner" sees the following attendees in room "public room" with 200 (v4)
      | actorType  | actorId                     | publishingPermissions |
      | users      | owner                       | NONE                  |
      | users      | moderator                   | NONE                  |
      | users      | invited user                | NONE                  |
      | users      | not invited but joined user | NONE                  |
      | guests     | "guest moderator"           | NONE                  |
      | guests     | "guest"                     | NONE                  |
    And user "moderator" sees the following attendees in room "public room" with 200 (v4)
      | actorType  | actorId                     | publishingPermissions |
      | users      | owner                       | NONE                  |
      | users      | moderator                   | NONE                  |
      | users      | invited user                | NONE                  |
      | users      | not invited but joined user | NONE                  |
      | guests     | "guest moderator"           | NONE                  |
      | guests     | "guest"                     | NONE                  |
    And user "invited user" sees the following attendees in room "public room" with 200 (v4)
      | actorType  | actorId                     | publishingPermissions |
      | users      | owner                       | NONE                  |
      | users      | moderator                   | NONE                  |
      | users      | invited user                | NONE                  |
      | users      | not invited but joined user | NONE                  |
      | guests     | "guest moderator"           | NONE                  |
      | guests     | "guest"                     | NONE                  |
    And user "not invited but joined user" sees the following attendees in room "public room" with 200 (v4)
      | actorType  | actorId                     | publishingPermissions |
      | users      | owner                       | NONE                  |
      | users      | moderator                   | NONE                  |
      | users      | invited user                | NONE                  |
      | users      | not invited but joined user | NONE                  |
      | guests     | "guest moderator"           | NONE                  |
      | guests     | "guest"                     | NONE                  |
    And user "guest moderator" sees the following attendees in room "public room" with 200 (v4)
      | actorType  | actorId                     | publishingPermissions |
      | users      | owner                       | NONE                  |
      | users      | moderator                   | NONE                  |
      | users      | invited user                | NONE                  |
      | users      | not invited but joined user | NONE                  |
      | guests     | "guest moderator"           | NONE                  |
      | guests     | "guest"                     | NONE                  |

  Scenario: moderator can set publishing permissions in public room
    Given user "owner" creates room "public room" (v4)
      | roomType | 3 |
      | roomName | room |
    And user "owner" adds user "moderator" to room "public room" with 200 (v4)
    And user "owner" promotes "moderator" in room "public room" with 200 (v4)
    And user "owner" adds user "invited user" to room "public room" with 200 (v4)
    And user "not invited but joined user" joins room "public room" with 200 (v4)
    And user "guest moderator" joins room "public room" with 200 (v4)
    And user "owner" promotes "guest moderator" in room "public room" with 200 (v4)
    And user "guest" joins room "public room" with 200 (v4)
    And user "owner" loads attendees attendee ids in room "public room" (v4)
    When user "moderator" sets publishing permissions for "owner" in room "public room" to "NONE" with 200 (v4)
    And user "moderator" sets publishing permissions for "moderator" in room "public room" to "NONE" with 200 (v4)
    And user "moderator" sets publishing permissions for "invited user" in room "public room" to "NONE" with 200 (v4)
    And user "moderator" sets publishing permissions for "not invited but joined user" in room "public room" to "NONE" with 200 (v4)
    And user "moderator" sets publishing permissions for "guest moderator" in room "public room" to "NONE" with 200 (v4)
    And user "moderator" sets publishing permissions for "guest" in room "public room" to "NONE" with 200 (v4)
    Then user "owner" sees the following attendees in room "public room" with 200 (v4)
      | actorType  | actorId                     | publishingPermissions |
      | users      | owner                       | NONE                  |
      | users      | moderator                   | NONE                  |
      | users      | invited user                | NONE                  |
      | users      | not invited but joined user | NONE                  |
      | guests     | "guest moderator"           | NONE                  |
      | guests     | "guest"                     | NONE                  |
    And user "moderator" sees the following attendees in room "public room" with 200 (v4)
      | actorType  | actorId                     | publishingPermissions |
      | users      | owner                       | NONE                  |
      | users      | moderator                   | NONE                  |
      | users      | invited user                | NONE                  |
      | users      | not invited but joined user | NONE                  |
      | guests     | "guest moderator"           | NONE                  |
      | guests     | "guest"                     | NONE                  |
    And user "invited user" sees the following attendees in room "public room" with 200 (v4)
      | actorType  | actorId                     | publishingPermissions |
      | users      | owner                       | NONE                  |
      | users      | moderator                   | NONE                  |
      | users      | invited user                | NONE                  |
      | users      | not invited but joined user | NONE                  |
      | guests     | "guest moderator"           | NONE                  |
      | guests     | "guest"                     | NONE                  |
    And user "not invited but joined user" sees the following attendees in room "public room" with 200 (v4)
      | actorType  | actorId                     | publishingPermissions |
      | users      | owner                       | NONE                  |
      | users      | moderator                   | NONE                  |
      | users      | invited user                | NONE                  |
      | users      | not invited but joined user | NONE                  |
      | guests     | "guest moderator"           | NONE                  |
      | guests     | "guest"                     | NONE                  |
    And user "guest moderator" sees the following attendees in room "public room" with 200 (v4)
      | actorType  | actorId                     | publishingPermissions |
      | users      | owner                       | NONE                  |
      | users      | moderator                   | NONE                  |
      | users      | invited user                | NONE                  |
      | users      | not invited but joined user | NONE                  |
      | guests     | "guest moderator"           | NONE                  |
      | guests     | "guest"                     | NONE                  |
    # Guests can not fetch the participant list

  Scenario: guest moderator can set publishing permissions in public room
    Given user "owner" creates room "public room" (v4)
      | roomType | 3 |
      | roomName | room |
    And user "owner" adds user "moderator" to room "public room" with 200 (v4)
    And user "owner" promotes "moderator" in room "public room" with 200 (v4)
    And user "owner" adds user "invited user" to room "public room" with 200 (v4)
    And user "not invited but joined user" joins room "public room" with 200 (v4)
    And user "guest moderator" joins room "public room" with 200 (v4)
    And user "owner" promotes "guest moderator" in room "public room" with 200 (v4)
    And user "guest" joins room "public room" with 200 (v4)
    And user "owner" loads attendees attendee ids in room "public room" (v4)
    # Guest user names in tests must begin with "guest"
    When user "guest moderator" sets publishing permissions for "owner" in room "public room" to "NONE" with 200 (v4)
    And user "guest moderator" sets publishing permissions for "moderator" in room "public room" to "NONE" with 200 (v4)
    And user "guest moderator" sets publishing permissions for "invited user" in room "public room" to "NONE" with 200 (v4)
    And user "guest moderator" sets publishing permissions for "not invited but joined user" in room "public room" to "NONE" with 200 (v4)
    And user "guest moderator" sets publishing permissions for "guest moderator" in room "public room" to "NONE" with 200 (v4)
    And user "guest moderator" sets publishing permissions for "guest" in room "public room" to "NONE" with 200 (v4)
    Then user "owner" sees the following attendees in room "public room" with 200 (v4)
      | actorType  | actorId                     | publishingPermissions |
      | users      | owner                       | NONE                  |
      | users      | moderator                   | NONE                  |
      | users      | invited user                | NONE                  |
      | users      | not invited but joined user | NONE                  |
      | guests     | "guest moderator"           | NONE                  |
      | guests     | "guest"                     | NONE                  |
    And user "moderator" sees the following attendees in room "public room" with 200 (v4)
      | actorType  | actorId                     | publishingPermissions |
      | users      | owner                       | NONE                  |
      | users      | moderator                   | NONE                  |
      | users      | invited user                | NONE                  |
      | users      | not invited but joined user | NONE                  |
      | guests     | "guest moderator"           | NONE                  |
      | guests     | "guest"                     | NONE                  |
    And user "invited user" sees the following attendees in room "public room" with 200 (v4)
      | actorType  | actorId                     | publishingPermissions |
      | users      | owner                       | NONE                  |
      | users      | moderator                   | NONE                  |
      | users      | invited user                | NONE                  |
      | users      | not invited but joined user | NONE                  |
      | guests     | "guest moderator"           | NONE                  |
      | guests     | "guest"                     | NONE                  |
    And user "not invited but joined user" sees the following attendees in room "public room" with 200 (v4)
      | actorType  | actorId                     | publishingPermissions |
      | users      | owner                       | NONE                  |
      | users      | moderator                   | NONE                  |
      | users      | invited user                | NONE                  |
      | users      | not invited but joined user | NONE                  |
      | guests     | "guest moderator"           | NONE                  |
      | guests     | "guest"                     | NONE                  |
    And user "guest moderator" sees the following attendees in room "public room" with 200 (v4)
      | actorType  | actorId                     | publishingPermissions |
      | users      | owner                       | NONE                  |
      | users      | moderator                   | NONE                  |
      | users      | invited user                | NONE                  |
      | users      | not invited but joined user | NONE                  |
      | guests     | "guest moderator"           | NONE                  |
      | guests     | "guest"                     | NONE                  |
    # Guests can not fetch the participant list

  Scenario: others can not set publishing permissions in public room
    Given user "owner" creates room "public room" (v4)
      | roomType | 3 |
      | roomName | room |
    And user "owner" adds user "moderator" to room "public room" with 200 (v4)
    And user "owner" promotes "moderator" in room "public room" with 200 (v4)
    And user "owner" adds user "invited user" to room "public room" with 200 (v4)
    And user "not invited but joined user" joins room "public room" with 200 (v4)
    And user "guest moderator" joins room "public room" with 200 (v4)
    And user "owner" promotes "guest moderator" in room "public room" with 200 (v4)
    And user "guest" joins room "public room" with 200 (v4)
    And user "owner" loads attendees attendee ids in room "public room" (v4)
    When user "invited user" sets publishing permissions for "owner" in room "public room" to "NONE" with 403 (v4)
    And user "invited user" sets publishing permissions for "moderator" in room "public room" to "NONE" with 403 (v4)
    And user "invited user" sets publishing permissions for "invited user" in room "public room" to "NONE" with 403 (v4)
    And user "invited user" sets publishing permissions for "not invited but joined user" in room "public room" to "NONE" with 403 (v4)
    And user "invited user" sets publishing permissions for "guest moderator" in room "public room" to "NONE" with 403 (v4)
    And user "invited user" sets publishing permissions for "guest" in room "public room" to "NONE" with 403 (v4)
    And user "not invited but joined user" sets publishing permissions for "owner" in room "public room" to "NONE" with 403 (v4)
    And user "not invited but joined user" sets publishing permissions for "moderator" in room "public room" to "NONE" with 403 (v4)
    And user "not invited but joined user" sets publishing permissions for "invited user" in room "public room" to "NONE" with 403 (v4)
    And user "not invited but joined user" sets publishing permissions for "not invited but joined user" in room "public room" to "NONE" with 403 (v4)
    And user "not invited but joined user" sets publishing permissions for "guest moderator" in room "public room" to "NONE" with 403 (v4)
    And user "not invited but joined user" sets publishing permissions for "guest" in room "public room" to "NONE" with 403 (v4)
    And user "not joined user" sets publishing permissions for "owner" in room "public room" to "NONE" with 404 (v4)
    And user "not joined user" sets publishing permissions for "moderator" in room "public room" to "NONE" with 404 (v4)
    And user "not joined user" sets publishing permissions for "invited user" in room "public room" to "NONE" with 404 (v4)
    And user "not joined user" sets publishing permissions for "not invited but joined user" in room "public room" to "NONE" with 404 (v4)
    And user "not joined user" sets publishing permissions for "guest moderator" in room "public room" to "NONE" with 404 (v4)
    And user "not joined user" sets publishing permissions for "guest" in room "public room" to "NONE" with 404 (v4)
    # Guest user names in tests must begin with "guest"
    And user "guest" sets publishing permissions for "owner" in room "public room" to "NONE" with 403 (v4)
    And user "guest" sets publishing permissions for "moderator" in room "public room" to "NONE" with 403 (v4)
    And user "guest" sets publishing permissions for "invited user" in room "public room" to "NONE" with 403 (v4)
    And user "guest" sets publishing permissions for "not invited but joined user" in room "public room" to "NONE" with 403 (v4)
    And user "guest" sets publishing permissions for "guest moderator" in room "public room" to "NONE" with 403 (v4)
    And user "guest" sets publishing permissions for "guest" in room "public room" to "NONE" with 403 (v4)
    And user "guest not joined" sets publishing permissions for "owner" in room "public room" to "NONE" with 404 (v4)
    And user "guest not joined" sets publishing permissions for "moderator" in room "public room" to "NONE" with 404 (v4)
    And user "guest not joined" sets publishing permissions for "invited user" in room "public room" to "NONE" with 404 (v4)
    And user "guest not joined" sets publishing permissions for "not invited but joined user" in room "public room" to "NONE" with 404 (v4)
    And user "guest not joined" sets publishing permissions for "guest moderator" in room "public room" to "NONE" with 404 (v4)
    And user "guest not joined" sets publishing permissions for "guest" in room "public room" to "NONE" with 404 (v4)
    Then user "owner" sees the following attendees in room "public room" with 200 (v4)
      | actorType  | actorId                     | publishingPermissions |
      | users      | owner                       | ALL                   |
      | users      | moderator                   | ALL                   |
      | users      | invited user                | ALL                   |
      | users      | not invited but joined user | ALL                   |
      | guests     | "guest moderator"           | ALL                   |
      | guests     | "guest"                     | ALL                   |
    And user "moderator" sees the following attendees in room "public room" with 200 (v4)
      | actorType  | actorId                     | publishingPermissions |
      | users      | owner                       | ALL                   |
      | users      | moderator                   | ALL                   |
      | users      | invited user                | ALL                   |
      | users      | not invited but joined user | ALL                   |
      | guests     | "guest moderator"           | ALL                   |
      | guests     | "guest"                     | ALL                   |
    And user "invited user" sees the following attendees in room "public room" with 200 (v4)
      | actorType  | actorId                     | publishingPermissions |
      | users      | owner                       | ALL                   |
      | users      | moderator                   | ALL                   |
      | users      | invited user                | ALL                   |
      | users      | not invited but joined user | ALL                   |
      | guests     | "guest moderator"           | ALL                   |
      | guests     | "guest"                     | ALL                   |
    And user "not invited but joined user" sees the following attendees in room "public room" with 200 (v4)
      | actorType  | actorId                     | publishingPermissions |
      | users      | owner                       | ALL                   |
      | users      | moderator                   | ALL                   |
      | users      | invited user                | ALL                   |
      | users      | not invited but joined user | ALL                   |
      | guests     | "guest moderator"           | ALL                   |
      | guests     | "guest"                     | ALL                   |
    And user "guest moderator" sees the following attendees in room "public room" with 200 (v4)
      | actorType  | actorId                     | publishingPermissions |
      | users      | owner                       | ALL                   |
      | users      | moderator                   | ALL                   |
      | users      | invited user                | ALL                   |
      | users      | not invited but joined user | ALL                   |
      | guests     | "guest moderator"           | ALL                   |
      | guests     | "guest"                     | ALL                   |
    # Guests can not fetch the participant list



  Scenario: participants can not set publishing permissions in room for a share
    # These users are only needed in very specific tests, so they are not
    # created in the background step.
    Given user "owner of file" exists
    And user "user with access to file" exists
    And user "owner of file" shares "welcome.txt" with user "user with access to file" with OCS 100
    And user "user with access to file" accepts last share
    And user "owner of file" shares "welcome.txt" by link with OCS 100
    And user "guest" gets the room for last share with 200 (v1)
    And user "owner of file" joins room "file last share room" with 200 (v4)
    And user "user with access to file" joins room "file last share room" with 200 (v4)
    And user "guest" joins room "file last share room" with 200 (v4)
    And user "owner of file" loads attendees attendee ids in room "file last share room" (v4)
    When user "owner of file" sets publishing permissions for "owner of file" in room "file last share room" to "NONE" with 403 (v4)
    And user "owner of file" sets publishing permissions for "user with access to file" in room "file last share room" to "NONE" with 403 (v4)
    And user "owner of file" sets publishing permissions for "guest" in room "file last share room" to "NONE" with 403 (v4)
    And user "user with access to file" sets publishing permissions for "owner of file" in room "file last share room" to "NONE" with 403 (v4)
    And user "user with access to file" sets publishing permissions for "user with access to file" in room "file last share room" to "NONE" with 403 (v4)
    And user "user with access to file" sets publishing permissions for "guest" in room "file last share room" to "NONE" with 403 (v4)
    And user "guest" sets publishing permissions for "owner of file" in room "file last share room" to "NONE" with 403 (v4)
    And user "guest" sets publishing permissions for "user with access to file" in room "file last share room" to "NONE" with 403 (v4)
    And user "guest" sets publishing permissions for "guest" in room "file last share room" to "NONE" with 403 (v4)
    Then user "owner of file" sees the following attendees in room "file last share room" with 200 (v4)
      | actorType  | actorId                  | publishingPermissions |
      | users      | owner of file            | ALL                   |
      | users      | user with access to file | ALL                   |
      | guests     | "guest"                  | ALL                   |
    And user "user with access to file" sees the following attendees in room "file last share room" with 200 (v4)
      | actorType  | actorId                  | publishingPermissions |
      | users      | owner of file            | ALL                   |
      | users      | user with access to file | ALL                   |
      | guests     | "guest"                  | ALL                   |



  # This does not make much sense, but there is no real need to block it either.
  Scenario: owner can set publishing permissions in a password request room
    # The user is only needed in very specific tests, so it is not created in
    # the background step.
    Given user "owner of file" exists
    And user "owner of file" shares "welcome.txt" by link with OCS 100
      | password | 123456 |
      | sendPasswordByTalk | true |
    And user "guest" creates the password request room for last share with 201 (v1)
    And user "guest" joins room "password request for last share room" with 200 (v4)
    And user "owner of file" joins room "password request for last share room" with 200 (v4)
    And user "owner of file" loads attendees attendee ids in room "password request for last share room" (v4)
    When user "owner of file" sets publishing permissions for "owner of file" in room "password request for last share room" to "NONE" with 200 (v4)
    And user "owner of file" sets publishing permissions for "guest" in room "password request for last share room" to "NONE" with 200 (v4)
    Then user "owner of file" sees the following attendees in room "password request for last share room" with 200 (v4)
      | actorType  | actorId       | publishingPermissions |
      | users      | owner of file | NONE                  |
      | guests     | "guest"       | NONE                  |
