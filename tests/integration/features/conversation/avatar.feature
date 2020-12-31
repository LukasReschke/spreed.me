Feature: avatar

  Background:
    Given user "owner" exists
    Given user "moderator" exists
    Given user "invited user" exists
    Given user "not invited user" exists
    Given user "not invited but joined user" exists
    Given user "not joined user" exists

  Scenario: participants can not set avatar in one-to-one room
    Given user "owner" creates room "one-to-one room"
      | roomType | 1 |
      | invite   | moderator |
    When user "owner" sets avatar for room "one-to-one room" from file "data/green-square-256.png" with 404
    And user "moderator" sets avatar for room "one-to-one room" from file "data/green-square-256.png" with 404
    And user "not invited user" sets avatar for room "one-to-one room" from file "data/green-square-256.png" with 404
    And user "guest" sets avatar for room "one-to-one room" from file "data/green-square-256.png" with 404
    Then user "owner" gets avatar for room "one-to-one room"
    And last avatar is a default avatar of size "128"
    And user "moderator" gets avatar for room "one-to-one room"
    And last avatar is a default avatar of size "128"



  Scenario: owner can set avatar in group room
    Given user "owner" creates room "group room"
      | roomType | 2 |
      | roomName | room |
    And user "owner" adds "moderator" to room "group room" with 200
    And user "owner" promotes "moderator" in room "group room" with 200
    And user "owner" adds "invited user" to room "group room" with 200
    When user "owner" sets avatar for room "group room" from file "data/green-square-256.png"
    Then user "owner" gets avatar for room "group room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "moderator" gets avatar for room "group room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "invited user" gets avatar for room "group room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"

  Scenario: moderator can set avatar in group room
    Given user "owner" creates room "group room"
      | roomType | 2 |
      | roomName | room |
    And user "owner" adds "moderator" to room "group room" with 200
    And user "owner" promotes "moderator" in room "group room" with 200
    And user "owner" adds "invited user" to room "group room" with 200
    When user "moderator" sets avatar for room "group room" from file "data/green-square-256.png"
    Then user "owner" gets avatar for room "group room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "moderator" gets avatar for room "group room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "invited user" gets avatar for room "group room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"

  Scenario: others can not set avatar in group room
    Given user "owner" creates room "group room"
      | roomType | 2 |
      | roomName | room |
    And user "owner" adds "moderator" to room "group room" with 200
    And user "owner" promotes "moderator" in room "group room" with 200
    And user "owner" adds "invited user" to room "group room" with 200
    When user "invited user" sets avatar for room "group room" from file "data/green-square-256.png" with 404
    And user "not invited user" sets avatar for room "group room" from file "data/green-square-256.png" with 404
    # Guest user names in tests must being with "guest"
    And user "guest not joined" sets avatar for room "group room" from file "data/green-square-256.png" with 404
    Then user "owner" gets avatar for room "group room" with size "256" with 404
    And user "moderator" gets avatar for room "group room" with size "256" with 404
    And user "invited user" gets avatar for room "group room" with size "256" with 404



  Scenario: owner can set avatar in public room
    Given user "owner" creates room "public room"
      | roomType | 3 |
      | roomName | room |
    And user "owner" adds "moderator" to room "public room" with 200
    And user "owner" promotes "moderator" in room "public room" with 200
    And user "owner" adds "invited user" to room "public room" with 200
    And user "not invited but joined user" joins room "public room" with 200
    And user "guest moderator" joins room "public room" with 200
    And user "owner" promotes "guest moderator" in room "public room" with 200
    And user "guest" joins room "public room" with 200
    When user "owner" sets avatar for room "public room" from file "data/green-square-256.png"
    Then user "owner" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "moderator" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "invited user" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "not invited but joined user" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "guest moderator" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "guest" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"

  Scenario: moderator can set avatar in public room
    Given user "owner" creates room "public room"
      | roomType | 3 |
      | roomName | room |
    And user "owner" adds "moderator" to room "public room" with 200
    And user "owner" promotes "moderator" in room "public room" with 200
    And user "owner" adds "invited user" to room "public room" with 200
    And user "not invited but joined user" joins room "public room" with 200
    And user "guest moderator" joins room "public room" with 200
    And user "owner" promotes "guest moderator" in room "public room" with 200
    And user "guest" joins room "public room" with 200
    When user "moderator" sets avatar for room "public room" from file "data/green-square-256.png"
    Then user "owner" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "moderator" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "invited user" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "not invited but joined user" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "guest moderator" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "guest" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"

  Scenario: guest moderator can set avatar in public room
    Given user "owner" creates room "public room"
      | roomType | 3 |
      | roomName | room |
    And user "owner" adds "moderator" to room "public room" with 200
    And user "owner" promotes "moderator" in room "public room" with 200
    And user "owner" adds "invited user" to room "public room" with 200
    And user "not invited but joined user" joins room "public room" with 200
    And user "guest moderator" joins room "public room" with 200
    And user "owner" promotes "guest moderator" in room "public room" with 200
    And user "guest" joins room "public room" with 200
    When user "guest moderator" sets avatar for room "public room" from file "data/green-square-256.png"
    Then user "owner" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "moderator" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "invited user" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "not invited but joined user" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "guest moderator" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "guest" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"

  Scenario: others can not set avatar in public room
    Given user "owner" creates room "public room"
      | roomType | 3 |
      | roomName | room |
    And user "owner" adds "moderator" to room "public room" with 200
    And user "owner" promotes "moderator" in room "public room" with 200
    And user "owner" adds "invited user" to room "public room" with 200
    And user "not invited but joined user" joins room "public room" with 200
    And user "guest moderator" joins room "public room" with 200
    And user "owner" promotes "guest moderator" in room "public room" with 200
    And user "guest" joins room "public room" with 200
    When user "invited user" sets avatar for room "public room" from file "data/green-square-256.png" with 404
    And user "not invited but joined user" sets avatar for room "public room" from file "data/green-square-256.png" with 404
    And user "not joined user" sets avatar for room "public room" from file "data/green-square-256.png" with 404
    And user "guest" sets avatar for room "public room" from file "data/green-square-256.png" with 404
    # Guest user names in tests must being with "guest"
    And user "guest not joined" sets avatar for room "public room" from file "data/green-square-256.png" with 404
    Then user "owner" gets avatar for room "public room" with size "256" with 404
    And user "moderator" gets avatar for room "public room" with size "256" with 404
    And user "invited user" gets avatar for room "public room" with size "256" with 404
    And user "not invited but joined user" gets avatar for room "public room" with size "256" with 404
    And user "guest moderator" gets avatar for room "public room" with size "256" with 404
    And user "guest" gets avatar for room "public room" with size "256" with 404



  Scenario: owner can set avatar in listable room
    Given user "owner" creates room "listable room"
      | roomType | 2 |
      | roomName | room |
    And user "owner" adds "moderator" to room "listable room" with 200
    And user "owner" promotes "moderator" in room "listable room" with 200
    And user "owner" adds "invited user" to room "listable room" with 200
    And user "owner" allows listing room "listable room" for "users" with 200
    When user "owner" sets avatar for room "listable room" from file "data/green-square-256.png"
    Then user "owner" gets avatar for room "listable room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "moderator" gets avatar for room "listable room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "invited user" gets avatar for room "listable room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "not invited user" gets avatar for room "listable room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "guest not joined" gets avatar for room "listable room" with size "256" with 404

  Scenario: moderator can set avatar in listable room
    Given user "owner" creates room "listable room"
      | roomType | 2 |
      | roomName | room |
    And user "owner" adds "moderator" to room "listable room" with 200
    And user "owner" promotes "moderator" in room "listable room" with 200
    And user "owner" adds "invited user" to room "listable room" with 200
    And user "owner" allows listing room "listable room" for "users" with 200
    When user "moderator" sets avatar for room "listable room" from file "data/green-square-256.png"
    Then user "owner" gets avatar for room "listable room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "moderator" gets avatar for room "listable room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "invited user" gets avatar for room "listable room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "not invited user" gets avatar for room "listable room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "guest not joined" gets avatar for room "listable room" with size "256" with 404

  Scenario: others can not set avatar in listable room
    Given user "owner" creates room "listable room"
      | roomType | 2 |
      | roomName | room |
    And user "owner" adds "moderator" to room "listable room" with 200
    And user "owner" promotes "moderator" in room "listable room" with 200
    And user "owner" adds "invited user" to room "listable room" with 200
    And user "owner" allows listing room "listable room" for "users" with 200
    When user "invited user" sets avatar for room "listable room" from file "data/green-square-256.png" with 404
    And user "not invited user" sets avatar for room "listable room" from file "data/green-square-256.png" with 404
    # Guest user names in tests must being with "guest"
    And user "guest not joined" sets avatar for room "listable room" from file "data/green-square-256.png" with 404
    Then user "owner" gets avatar for room "listable room" with size "256" with 404
    And user "moderator" gets avatar for room "listable room" with size "256" with 404
    And user "invited user" gets avatar for room "listable room" with size "256" with 404
    And user "not invited user" gets avatar for room "listable room" with size "256" with 404
    And user "guest not joined" gets avatar for room "listable room" with size "256" with 404



  Scenario: participants can not set avatar in room for a share
    # These users are only needed in very specific tests, so they are not
    # created in the background step.
    Given user "owner of file" exists
    And user "user with access to file" exists
    And user "owner of file" shares "welcome.txt" with user "user with access to file" with OCS 100
    And user "user with access to file" accepts last share
    And user "owner of file" shares "welcome.txt" by link with OCS 100
    And user "guest" gets the room for last share with 200
    And user "owner of file" joins room "file last share room" with 200
    And user "user with access to file" joins room "file last share room" with 200
    And user "guest" joins room "file last share room" with 200
    When user "owner of file" sets avatar for room "file last share room" from file "data/green-square-256.png" with 404
    And user "user with access to file" sets avatar for room "file last share room" from file "data/green-square-256.png" with 404
    And user "guest" sets avatar for room "file last share room" from file "data/green-square-256.png" with 404
    Then user "owner of file" gets avatar for room "file last share room" with size "256" with 404
    And user "user with access to file" gets avatar for room "file last share room" with size "256" with 404
    And user "guest" gets avatar for room "file last share room" with size "256" with 404



  Scenario: participants can not set avatar in a password request room
    # The user is only needed in very specific tests, so it is not created in
    # the background step.
    Given user "owner of file" exists
    And user "owner of file" shares "welcome.txt" by link with OCS 100
      | password | 123456 |
      | sendPasswordByTalk | true |
    And user "guest" creates the password request room for last share with 201
    And user "guest" joins room "password request for last share room" with 200
    And user "owner of file" joins room "password request for last share room" with 200
    When user "owner of file" sets avatar for room "password request for last share room" from file "data/green-square-256.png" with 404
    And user "guest" sets avatar for room "password request for last share room" from file "data/green-square-256.png" with 404
    Then user "owner of file" gets avatar for room "password request for last share room" with size "256" with 404
    And user "guest" gets avatar for room "password request for last share room" with size "256" with 404



  Scenario: set jpg image as room avatar
    Given user "owner" creates room "group room"
      | roomType | 2 |
      | roomName | room |
    When user "owner" sets avatar for room "group room" from file "data/blue-square-256.jpg"
    Then user "owner" gets avatar for room "group room" with size "256"
    And the following headers should be set
      | Content-Type | image/jpeg |
      | X-NC-IsCustomAvatar | 1 |
    And last avatar is a square of size "256"
    And last avatar is a single "#0000FF" color

  Scenario: set non squared image as room avatar
    Given user "owner" creates room "group room"
      | roomType | 2 |
      | roomName | room |
    When user "owner" sets avatar for room "group room" from file "data/green-rectangle-256-128.png" with 400
    Then user "owner" gets avatar for room "group room" with size "256" with 404

  Scenario: set not an image as room avatar
    Given user "owner" creates room "group room"
      | roomType | 2 |
      | roomName | room |
    When user "owner" sets avatar for room "group room" from file "data/textfile.txt" with 400
    Then user "owner" gets avatar for room "group room" with size "256" with 404



  Scenario: owner can delete avatar in group room
    Given user "owner" creates room "group room"
      | roomType | 2 |
      | roomName | room |
    And user "owner" adds "moderator" to room "group room" with 200
    And user "owner" promotes "moderator" in room "group room" with 200
    And user "owner" adds "invited user" to room "group room" with 200
    And user "owner" sets avatar for room "group room" from file "data/green-square-256.png"
    And user "owner" gets avatar for room "group room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    When user "owner" deletes avatar for room "group room"
    Then user "owner" gets avatar for room "group room" with size "256" with 404
    And user "moderator" gets avatar for room "group room" with size "256" with 404
    And user "invited user" gets avatar for room "group room" with size "256" with 404

  Scenario: moderator can delete avatar in group room
    Given user "owner" creates room "group room"
      | roomType | 2 |
      | roomName | room |
    And user "owner" adds "moderator" to room "group room" with 200
    And user "owner" promotes "moderator" in room "group room" with 200
    And user "owner" adds "invited user" to room "group room" with 200
    And user "owner" sets avatar for room "group room" from file "data/green-square-256.png"
    And user "owner" gets avatar for room "group room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    When user "moderator" deletes avatar for room "group room"
    Then user "owner" gets avatar for room "group room" with size "256" with 404
    And user "moderator" gets avatar for room "group room" with size "256" with 404
    And user "invited user" gets avatar for room "group room" with size "256" with 404

  Scenario: others can not delete avatar in group room
    Given user "owner" creates room "group room"
      | roomType | 2 |
      | roomName | room |
    And user "owner" adds "moderator" to room "group room" with 200
    And user "owner" promotes "moderator" in room "group room" with 200
    And user "owner" adds "invited user" to room "group room" with 200
    And user "owner" sets avatar for room "group room" from file "data/green-square-256.png"
    When user "invited user" deletes avatar for room "group room" with 404
    And user "not invited user" deletes avatar for room "group room" with 404
    # Guest user names in tests must being with "guest"
    And user "guest not joined" deletes avatar for room "group room" with 404
    Then user "owner" gets avatar for room "group room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "moderator" gets avatar for room "group room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "invited user" gets avatar for room "group room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"



  Scenario: owner can delete avatar in public room
    Given user "owner" creates room "public room"
      | roomType | 3 |
      | roomName | room |
    And user "owner" adds "moderator" to room "public room" with 200
    And user "owner" promotes "moderator" in room "public room" with 200
    And user "owner" adds "invited user" to room "public room" with 200
    And user "not invited but joined user" joins room "public room" with 200
    And user "guest moderator" joins room "public room" with 200
    And user "owner" promotes "guest moderator" in room "public room" with 200
    And user "guest" joins room "public room" with 200
    And user "owner" sets avatar for room "public room" from file "data/green-square-256.png"
    And user "owner" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    When user "owner" deletes avatar for room "public room"
    Then user "owner" gets avatar for room "public room" with size "256" with 404
    And user "moderator" gets avatar for room "public room" with size "256" with 404
    And user "invited user" gets avatar for room "public room" with size "256" with 404
    And user "not invited but joined user" gets avatar for room "public room" with size "256" with 404
    And user "guest moderator" gets avatar for room "public room" with size "256" with 404
    And user "guest" gets avatar for room "public room" with size "256" with 404

  Scenario: moderator can delete avatar in public room
    Given user "owner" creates room "public room"
      | roomType | 3 |
      | roomName | room |
    And user "owner" adds "moderator" to room "public room" with 200
    And user "owner" promotes "moderator" in room "public room" with 200
    And user "owner" adds "invited user" to room "public room" with 200
    And user "not invited but joined user" joins room "public room" with 200
    And user "guest moderator" joins room "public room" with 200
    And user "owner" promotes "guest moderator" in room "public room" with 200
    And user "guest" joins room "public room" with 200
    And user "owner" sets avatar for room "public room" from file "data/green-square-256.png"
    And user "owner" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    When user "moderator" deletes avatar for room "public room"
    Then user "owner" gets avatar for room "public room" with size "256" with 404
    And user "moderator" gets avatar for room "public room" with size "256" with 404
    And user "invited user" gets avatar for room "public room" with size "256" with 404
    And user "not invited but joined user" gets avatar for room "public room" with size "256" with 404
    And user "guest moderator" gets avatar for room "public room" with size "256" with 404
    And user "guest" gets avatar for room "public room" with size "256" with 404

  Scenario: guest moderator can delete avatar in public room
    Given user "owner" creates room "public room"
      | roomType | 3 |
      | roomName | room |
    And user "owner" adds "moderator" to room "public room" with 200
    And user "owner" promotes "moderator" in room "public room" with 200
    And user "owner" adds "invited user" to room "public room" with 200
    And user "not invited but joined user" joins room "public room" with 200
    And user "guest moderator" joins room "public room" with 200
    And user "owner" promotes "guest moderator" in room "public room" with 200
    And user "guest" joins room "public room" with 200
    And user "owner" sets avatar for room "public room" from file "data/green-square-256.png"
    And user "owner" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    When user "guest moderator" deletes avatar for room "public room"
    Then user "owner" gets avatar for room "public room" with size "256" with 404
    And user "moderator" gets avatar for room "public room" with size "256" with 404
    And user "invited user" gets avatar for room "public room" with size "256" with 404
    And user "not invited but joined user" gets avatar for room "public room" with size "256" with 404
    And user "guest moderator" gets avatar for room "public room" with size "256" with 404
    And user "guest" gets avatar for room "public room" with size "256" with 404

  Scenario: others can not delete avatar in public room
    Given user "owner" creates room "public room"
      | roomType | 3 |
      | roomName | room |
    And user "owner" adds "moderator" to room "public room" with 200
    And user "owner" promotes "moderator" in room "public room" with 200
    And user "owner" adds "invited user" to room "public room" with 200
    And user "not invited but joined user" joins room "public room" with 200
    And user "guest moderator" joins room "public room" with 200
    And user "owner" promotes "guest moderator" in room "public room" with 200
    And user "guest" joins room "public room" with 200
    And user "owner" sets avatar for room "public room" from file "data/green-square-256.png"
    When user "invited user" deletes avatar for room "public room" with 404
    And user "not invited but joined user" deletes avatar for room "public room" with 404
    And user "not joined user" deletes avatar for room "public room" with 404
    And user "guest" deletes avatar for room "public room" with 404
    # Guest user names in tests must being with "guest"
    And user "guest not joined" deletes avatar for room "public room" with 404
    Then user "owner" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "moderator" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "invited user" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "not invited but joined user" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "guest moderator" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"
    And user "guest" gets avatar for room "public room" with size "256"
    And last avatar is a custom avatar of size "256" and color "#00FF00"



  Scenario: get room avatar with a larger size than the original one
    Given user "owner" creates room "group room"
      | roomType | 2 |
      | roomName | room |
    And user "owner" sets avatar for room "group room" from file "data/green-square-256.png"
    When user "owner" gets avatar for room "group room" with size "512"
    Then last avatar is a custom avatar of size "512" and color "#00FF00"

  Scenario: get room avatar with a smaller size than the original one
    Given user "owner" creates room "group room"
      | roomType | 2 |
      | roomName | room |
    And user "owner" sets avatar for room "group room" from file "data/green-square-256.png"
    When user "owner" gets avatar for room "group room" with size "128"
    Then last avatar is a custom avatar of size "128" and color "#00FF00"



  Scenario: room list returns the default avatar after room creation
    When user "owner" creates room "public room"
      | roomType | 3 |
      | roomName | room |
    And user "owner" adds "moderator" to room "public room" with 200
    And user "owner" promotes "moderator" in room "public room" with 200
    And user "owner" adds "invited user" to room "public room" with 200
    And user "not invited but joined user" joins room "public room" with 200
    Then user "owner" is participant of the following rooms (v3)
      | avatarId    | avatarVersion |
      | icon-public | 1             |
    And user "moderator" is participant of the following rooms (v3)
      | avatarId    | avatarVersion |
      | icon-public | 1             |
    And user "invited user" is participant of the following rooms (v3)
      | avatarId    | avatarVersion |
      | icon-public | 1             |
    And user "not invited but joined user" is participant of the following rooms (v3)
      | avatarId    | avatarVersion |
      | icon-public | 1             |

  Scenario: room list returns a custom avatar after avatar is set
    Given user "owner" creates room "public room"
      | roomType | 3 |
      | roomName | room |
    And user "owner" adds "moderator" to room "public room" with 200
    And user "owner" promotes "moderator" in room "public room" with 200
    And user "owner" adds "invited user" to room "public room" with 200
    And user "not invited but joined user" joins room "public room" with 200
    When user "owner" sets avatar for room "public room" from file "data/green-square-256.png"
    Then user "owner" is participant of the following rooms (v3)
      | avatarId | avatarVersion |
      | custom   | 2             |
    And user "moderator" is participant of the following rooms (v3)
      | avatarId | avatarVersion |
      | custom   | 2             |
    And user "invited user" is participant of the following rooms (v3)
      | avatarId | avatarVersion |
      | custom   | 2             |
    And user "not invited but joined user" is participant of the following rooms (v3)
      | avatarId | avatarVersion |
      | custom   | 2             |

  Scenario: room list returns a default avatar after avatar is deleted
    Given user "owner" creates room "public room"
      | roomType | 3 |
      | roomName | room |
    And user "owner" adds "moderator" to room "public room" with 200
    And user "owner" promotes "moderator" in room "public room" with 200
    And user "owner" adds "invited user" to room "public room" with 200
    And user "not invited but joined user" joins room "public room" with 200
    And user "owner" sets avatar for room "public room" from file "data/green-square-256.png"
    And user "owner" is participant of the following rooms (v3)
      | avatarId | avatarVersion |
      | custom   | 2             |
    When user "owner" deletes avatar for room "public room"
    Then user "owner" is participant of the following rooms (v3)
      | avatarId    | avatarVersion |
      | icon-public | 3             |
    And user "moderator" is participant of the following rooms (v3)
      | avatarId    | avatarVersion |
      | icon-public | 3             |
    And user "invited user" is participant of the following rooms (v3)
      | avatarId    | avatarVersion |
      | icon-public | 3             |
    And user "not invited but joined user" is participant of the following rooms (v3)
      | avatarId    | avatarVersion |
      | icon-public | 3             |



  Scenario: one-to-one room avatar is updated when user avatar is updated
    Given user "owner" creates room "one-to-one room"
      | roomType | 1 |
      | invite   | moderator |
    When user "owner" logs in
    And logged in user posts temporary avatar from file "data/green-square-256.png"
    And logged in user crops temporary avatar
      | x | 0 |
      | y | 0 |
      | w | 256 |
      | h | 256 |
    Then user "owner" gets avatar for room "one-to-one room" with size "256"
    And last avatar is a default avatar of size "256"
    And user "moderator" gets avatar for room "one-to-one room" with size "256"
    # Although the user avatar is a custom avatar the room avatar is still a
    # default avatar.
    And the following headers should be set
      | Content-Type | image/png |
      | X-NC-IsCustomAvatar | 0 |
    And last avatar is a square of size "256"
    And last avatar is a single "#00FF00" color
    And user "owner" is participant of the following rooms (v3)
      | avatarId | avatarVersion |
      | user     | 2             |
    And user "moderator" is participant of the following rooms (v3)
      | avatarId | avatarVersion |
      | user     | 2             |

  Scenario: one-to-one room avatar is updated when user avatar is deleted
    Given user "owner" creates room "one-to-one room"
      | roomType | 1 |
      | invite   | moderator |
    And user "owner" logs in
    And logged in user posts temporary avatar from file "data/green-square-256.png"
    And logged in user crops temporary avatar
      | x | 0 |
      | y | 0 |
      | w | 256 |
      | h | 256 |
    When logged in user deletes the user avatar
    Then user "owner" gets avatar for room "one-to-one room"
    And last avatar is a default avatar of size "128"
    And user "moderator" gets avatar for room "one-to-one room"
    And last avatar is a default avatar of size "128"
    And user "owner" is participant of the following rooms (v3)
      | avatarId | avatarVersion |
      | user     | 3             |
    And user "moderator" is participant of the following rooms (v3)
      | avatarId | avatarVersion |
      | user     | 3             |
