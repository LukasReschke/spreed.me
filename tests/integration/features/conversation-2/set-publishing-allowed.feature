Feature: set-publishing-allowed
  Background:
    Given user "owner" exists
    Given user "moderator" exists
    Given user "invited user" exists
    Given user "not invited user" exists
    Given user "not invited but joined user" exists
    Given user "not joined user" exists

  Scenario: publishing allowed can not be set while a call is active
    Given user "owner" creates room "group room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "owner" joins room "group room" with 200 (v4)
    And user "owner" joins call "group room" with 200 (v4)
    When user "owner" sets publishing allowed for room "group room" to "MODERATORS" with 400 (v4)
    Then user "owner" is participant of room "group room" (v4)
      | publishingAllowed |
      | EVERYONE          |



  Scenario: owner can not set publishing allowed in one-to-one room
    Given user "owner" creates room "one-to-one room" (v4)
      | roomType | 1 |
      | invite   | moderator |
    When user "owner" sets publishing allowed for room "one-to-one room" to "MODERATORS" with 400 (v4)
    And user "moderator" sets publishing allowed for room "one-to-one room" to "MODERATORS" with 400 (v4)
    Then user "owner" is participant of room "one-to-one room" (v4)
      | publishingAllowed |
      | EVERYONE          |
    And user "moderator" is participant of room "one-to-one room" (v4)
      | publishingAllowed |
      | EVERYONE          |



  Scenario: owner can set publishing allowed in group room
    Given user "owner" creates room "group room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "owner" adds user "moderator" to room "group room" with 200 (v4)
    And user "owner" promotes "moderator" in room "group room" with 200 (v4)
    And user "owner" adds user "invited user" to room "group room" with 200 (v4)
    When user "owner" sets publishing allowed for room "group room" to "MODERATORS" with 200 (v4)
    Then user "owner" is participant of room "group room" (v4)
      | publishingAllowed |
      | MODERATORS        |
    And user "moderator" is participant of room "group room" (v4)
      | publishingAllowed |
      | MODERATORS        |
    And user "invited user" is participant of room "group room" (v4)
      | publishingAllowed |
      | MODERATORS        |

  Scenario: moderator can set publishing allowed in group room
    Given user "owner" creates room "group room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "owner" adds user "moderator" to room "group room" with 200 (v4)
    And user "owner" promotes "moderator" in room "group room" with 200 (v4)
    And user "owner" adds user "invited user" to room "group room" with 200 (v4)
    When user "moderator" sets publishing allowed for room "group room" to "MODERATORS" with 200 (v4)
    Then user "owner" is participant of room "group room" (v4)
      | publishingAllowed |
      | MODERATORS        |
    And user "moderator" is participant of room "group room" (v4)
      | publishingAllowed |
      | MODERATORS        |
    And user "invited user" is participant of room "group room" (v4)
      | publishingAllowed |
      | MODERATORS        |

  Scenario: others can not set publishing allowed in group room
    Given user "owner" creates room "group room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "owner" adds user "moderator" to room "group room" with 200 (v4)
    And user "owner" promotes "moderator" in room "group room" with 200 (v4)
    And user "owner" adds user "invited user" to room "group room" with 200 (v4)
    And user "owner" sets publishing allowed for room "group room" to "MODERATORS" with 200 (v4)
    When user "invited user" sets publishing allowed for room "group room" to "EVERYONE" with 403 (v4)
    And user "not invited user" sets publishing allowed for room "group room" to "EVERYONE" with 404 (v4)
    # Guest user names in tests must begin with "guest"
    And user "guest not joined" sets publishing allowed for room "group room" to "EVERYONE" with 404 (v4)
    Then user "owner" is participant of room "group room" (v4)
      | publishingAllowed |
      | MODERATORS        |
    And user "moderator" is participant of room "group room" (v4)
      | publishingAllowed |
      | MODERATORS        |
    And user "invited user" is participant of room "group room" (v4)
      | publishingAllowed |
      | MODERATORS        |



  Scenario: owner can set publishing allowed in public room
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
    When user "owner" sets publishing allowed for room "public room" to "MODERATORS" with 200 (v4)
    Then user "owner" is participant of room "public room" (v4)
      | publishingAllowed |
      | MODERATORS        |
    And user "moderator" is participant of room "public room" (v4)
      | publishingAllowed |
      | MODERATORS        |
    And user "invited user" is participant of room "public room" (v4)
      | publishingAllowed |
      | MODERATORS        |
    And user "not invited but joined user" is participant of room "public room" (v4)
      | publishingAllowed |
      | MODERATORS        |
    And user "guest moderator" is participant of room "public room" (v4)
      | publishingAllowed |
      | MODERATORS        |
    And user "guest" is participant of room "public room" (v4)
      | publishingAllowed |
      | MODERATORS        |

  Scenario: moderator can set publishing allowed in public room
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
    When user "moderator" sets publishing allowed for room "public room" to "MODERATORS" with 200 (v4)
    Then user "owner" is participant of room "public room" (v4)
      | publishingAllowed |
      | MODERATORS        |
    And user "moderator" is participant of room "public room" (v4)
      | publishingAllowed |
      | MODERATORS        |
    And user "invited user" is participant of room "public room" (v4)
      | publishingAllowed |
      | MODERATORS        |
    And user "not invited but joined user" is participant of room "public room" (v4)
      | publishingAllowed |
      | MODERATORS        |
    And user "guest moderator" is participant of room "public room" (v4)
      | publishingAllowed |
      | MODERATORS        |
    And user "guest" is participant of room "public room" (v4)
      | publishingAllowed |
      | MODERATORS        |

  Scenario: others can not set publishing allowed in public room
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
    And user "owner" sets publishing allowed for room "public room" to "MODERATORS" with 200 (v4)
    When user "invited user" sets publishing allowed for room "public room" to "EVERYONE" with 403 (v4)
    And user "not invited but joined user" sets publishing allowed for room "public room" to "EVERYONE" with 403 (v4)
    And user "not joined user" sets publishing allowed for room "public room" to "EVERYONE" with 404 (v4)
    # Guest user names in tests must begin with "guest"
    And user "guest moderator" sets publishing allowed for room "public room" to "EVERYONE" with 404 (v4)
    And user "guest" sets publishing allowed for room "public room" to "EVERYONE" with 404 (v4)
    And user "guest not joined" sets publishing allowed for room "public room" to "EVERYONE" with 404 (v4)
    Then user "owner" is participant of room "public room" (v4)
      | publishingAllowed |
      | MODERATORS        |
    And user "moderator" is participant of room "public room" (v4)
      | publishingAllowed |
      | MODERATORS        |
    And user "invited user" is participant of room "public room" (v4)
      | publishingAllowed |
      | MODERATORS        |
    And user "not invited but joined user" is participant of room "public room" (v4)
      | publishingAllowed |
      | MODERATORS        |
    And user "guest moderator" is participant of room "public room" (v4)
      | publishingAllowed |
      | MODERATORS        |
    And user "guest" is participant of room "public room" (v4)
      | publishingAllowed |
      | MODERATORS        |



  Scenario: participants can not set publishing allowed in room for a share
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
    When user "owner of file" sets publishing allowed for room "file last share room" to "MODERATORS" with 403 (v4)
    And user "user with access to file" sets publishing allowed for room "file last share room" to "MODERATORS" with 403 (v4)
    And user "guest" sets publishing allowed for room "file last share room" to "MODERATORS" with 404 (v4)
    Then user "owner of file" is participant of room "file last share room" (v4)
      | publishingAllowed |
      | EVERYONE          |
    And user "user with access to file" is participant of room "file last share room" (v4)
      | publishingAllowed |
      | EVERYONE          |
    And user "guest" is participant of room "file last share room" (v4)
      | publishingAllowed |
      | EVERYONE          |



  # Although it is technically possible to set publishing allowed in a password
  # request room in practice it will not, as there will be always an on-going
  # call.
  Scenario: owner can set publishing allowed in a password request room
    # The user is only needed in very specific tests, so it is not created in
    # the background step.
    Given user "owner of file" exists
    And user "owner of file" shares "welcome.txt" by link with OCS 100
      | password | 123456 |
      | sendPasswordByTalk | true |
    And user "guest" creates the password request room for last share with 201 (v1)
    And user "guest" joins room "password request for last share room" with 200 (v4)
    And user "owner of file" joins room "password request for last share room" with 200 (v4)
    When user "owner of file" sets publishing allowed for room "password request for last share room" to "MODERATORS" with 200 (v4)
    Then user "owner of file" is participant of room "password request for last share room" (v4)
      | publishingAllowed |
      | MODERATORS        |
    And user "guest" is participant of room "password request for last share room" (v4)
      | publishingAllowed |
      | MODERATORS        |
