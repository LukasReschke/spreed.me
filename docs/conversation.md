# Conversation API

* Base endpoint for API v1 is: `/ocs/v2.php/apps/spreed/api/v1`
* Base endpoint for API v2 is: `/ocs/v2.php/apps/spreed/api/v2`
* Base endpoint for API v3 is: `/ocs/v2.php/apps/spreed/api/v3`

## Get listed conversations

* Method: `GET`
* Endpoint: `/listed-room`

* Response:
    - Status code:
        + `200 OK`
        + `401 Unauthorized` when the user is not logged in

    - Header:

        field | type | Description
        ------|------|------------
        `searchTerm` | string | search term

    - Data: See array definition in `Get user´s conversations`

## Get user´s conversations

* Method: `GET`
* Endpoint: `/room`

* Response:
    - Status code:
        + `200 OK`
        + `401 Unauthorized` when the user is not logged in

    - Header:

        field | type | Description
        ------|------|------------
        `X-Nextcloud-Talk-Hash` | string | Sha1 value over some config. When you receive a different value on subsequent requests, the capabilities and the signaling settings should be refreshed.

    - Data:
        Array of conversations, each conversation has at least:

        field | type | API | Description
        ------|------|-----|------------
        `token` | string | * | Token identifier of the conversation which is used for further interaction
        `type` | int | * | See list of conversation types in the [constants list](constants.md#Conversation-types)
        `name` | string | * | Name of the conversation (can also be empty)
        `displayName` | string | * | `name` if non empty, otherwise it falls back to a list of participants
        `description` | string | v3 | Description of the conversation (can also be empty) (only available with `room-description` capability)
        `avatarId` | string | v3 | The type of the avatar ("custom", "user", "icon-public", "icon-contacts", "icon-mail", "icon-password", "icon-changelog", "icon-file") (only available with `room-avatar` capability)
        `avatarVersion` | int | v3 | The version of the avatar (only available with `room-avatar` capability)
        `participantType` | int | * | Permissions level of the current user
        `attendeeId` | int | v3 | Unique attendee id
        `attendeePin` | string | v3 | Unique dial-in authentication code for this user, when the conversation has SIP enabled (see `sipEnabled` attribute)
        `actorType` | string | v3 | Currently known `users|guests|emails|groups`
        `actorId` | string | v3 | The unique identifier for the given actor type
        `participantInCall` | bool | 🏴 v1 | Flag if the current user is in the call (deprecated, use `participantFlags` instead)
        `participantFlags` | int | * | Flags of the current user (only available with `in-call-flags` capability)
        `readOnly` | int | * | Read-only state for the current user (only available with `read-only-rooms` capability)
        `listable` | int | * | Listable scope for the room (only available with `listable-rooms` capability)
        `count` | int | 🏴 v1 | **Deprecated:** ~~Number of active users~~ - always returns `0`
        `numGuests` | int | 🏴 v1 | Number of active guests
        `lastPing` | int | * | Timestamp of the last ping of the current user (should be used for sorting)
        `sessionId` | string | * | `'0'` if not connected, otherwise a 512 character long string
        `hasPassword` | bool | * | Flag if the conversation has a password
        `hasCall` | bool | * | Flag if the conversation has an active call
        `canStartCall` | bool | * | Flag if the user can start a new call in this conversation (joining is always possible) (only available with `start-call-flag` capability)
        `canDeleteConversation` | bool | 🆕 v2 | Flag if the user can delete the conversation for everyone (not possible without moderator permissions or in one-to-one conversations)
        `canLeaveConversation` | bool | 🆕 v2 | Flag if the user can leave the conversation (not possible for the last user with moderator permissions)
        `lastActivity` | int | * | Timestamp of the last activity in the conversation, in seconds and UTC time zone
        `isFavorite` | bool | * | Flag if the conversation is favorited by the user
        `notificationLevel` | int | * | The notification level for the user (one of `Participant::NOTIFY_*` (1-3))
        `lobbyState` | int | * | Webinary lobby restriction (0-1), if the participant is a moderator they can always join the conversation (only available with `webinary-lobby` capability)
        `lobbyTimer` | int | * | Timestamp when the lobby will be automatically disabled (only available with `webinary-lobby` capability)
        `sipEnabled` | int | v3 | SIP enable status (0-1)
        `canEnableSIP` | int | v3 | Whether the given user can enable SIP for this conversation. Note that when the token is not-numeric only, SIP can not be enabled even if the user is permitted and a moderator of the conversation
        `unreadMessages` | int | * | Number of unread chat messages in the conversation (only available with `chat-v2` capability)
        `unreadMention` | bool | * | Flag if the user was mentioned since their last visit
        `lastReadMessage` | int | * | ID of the last read message in a room (only available with `chat-read-marker` capability)
        `lastCommonReadMessage` | int | v3 | ID of the last message read by every user that has read privacy set to public in a room. When the user themself has it set to private the value is `0` (only available with `chat-read-status` capability)
        `lastMessage` | message | * | Last message in a conversation if available, otherwise empty
        `objectType` | string | * | The type of object that the conversation is associated with; "share:password" if the conversation is used to request a password for a share, otherwise empty
        `objectId` | string | * | Share token if "objectType" is "share:password", otherwise empty

## Creating a new conversation

* Method: `POST`
* Endpoint: `/room`
* Data:

    field | type | Description
    ------|------|------------
    `roomType` | int |
    `invite` | string | user id (`roomType = 1`), group id (`roomType = 2` - optional), circle id (`roomType = 2`, `source = 'circles'`], only available with `circles-support` capability))
    `source` | string | The source for the invite, only supported on `roomType = 2` for `groups` and `circles` (only available with `circles-support` capability)
    `roomName` | string | conversation name (Not available for `roomType = 1`)

* Response:
    - Status code:
        + `200 OK` When the "one to one" conversation already exists
        + `201 Created` When the conversation was created
        + `400 Bad Request` When an invalid conversation type was given
        + `400 Bad Request` When the conversation name is empty for `type = 3`
        + `401 Unauthorized` When the user is not logged in
        + `404 Not Found` When the target to invite does not exist

    - Data: See array definition in `Get user´s conversations`

## Get single conversation (also for guests)

* Method: `GET`
* Endpoint: `/room/{token}`

* Response:
    - Status code:
        + `200 OK`
        + `404 Not Found` When the conversation could not be found for the participant

    - Header:

        field | type | Description
        ------|------|------------
        `X-Nextcloud-Talk-Hash` | string | Sha1 value over some config. When you receive a different value on subsequent requests, the capabilities and the signaling settings should be refreshed.

    - Data: See array definition in `Get user´s conversations`

## Rename a conversation

* Method: `PUT`
* Endpoint: `/room/{token}`
* Data:

    field | type | Description
    ------|------|------------
    `roomName` | string | New name for the conversation (1-200 characters)

* Response:
    - Status code:
        + `200 OK`
        + `400 Bad Request` When the name is too long or empty
        + `400 Bad Request` When the conversation is a one to one conversation
        + `403 Forbidden` When the current user is not a moderator/owner
        + `404 Not Found` When the conversation could not be found for the participant

## Delete a conversation

* Method: `DELETE`
* Endpoint: `/room/{token}`

* Response:
    - Status code:
        + `200 OK`
        + `400 Bad Request` When the conversation is a one-to-one conversation (Use [Remove yourself from a conversation](participant.md#Remove-yourself-from-a-conversation) instead)
        + `403 Forbidden` When the current user is not a moderator/owner
        + `404 Not Found` When the conversation could not be found for the participant

## Set description for a conversation

* Method: `PUT`
* API: v3
* Endpoint: `/room/{token}/description`
* Data:

    field | type | Description
    ------|------|------------
    `description` | string | New description for the conversation

* Response:
    - Status code:
        + `200 OK`
        + `400 Bad Request` When the description is too long
        + `400 Bad Request` When the conversation is a one to one conversation
        + `403 Forbidden` When the current user is not a moderator/owner
        + `404 Not Found` When the conversation could not be found for the participant

## Allow guests in a conversation (public conversation)

* Method: `POST`
* Endpoint: `/room/{token}/public`

* Response:
    - Status code:
        + `200 OK`
        + `400 Bad Request` When the conversation is not a group conversation
        + `403 Forbidden` When the current user is not a moderator/owner
        + `404 Not Found` When the conversation could not be found for the participant

## Disallow guests in a conversation (group conversation)

* Method: `DELETE`
* Endpoint: `/room/{token}/public`

* Response:
    - Status code:
        + `200 OK`
        + `400 Bad Request` When the conversation is not a public conversation
        + `403 Forbidden` When the current user is not a moderator/owner
        + `404 Not Found` When the conversation could not be found for the participant

## Set read-only for a conversation

* Method: `PUT`
* Endpoint: `/room/{token}/read-only`
* Data:

    field | type | Description
    ------|------|------------
    `state` | int | New state for the conversation

* Response:
    - Status code:
        + `200 OK`
        + `400 Bad Request` When the conversation type does not support read-only (only group and public conversation)
        + `403 Forbidden` When the current user is not a moderator/owner or the conversation is not a public conversation
        + `404 Not Found` When the conversation could not be found for the participant

## Set password for a conversation

* Method: `PUT`
* Endpoint: `/room/{token}/password`
* Data:

    field | type | Description
    ------|------|------------
    `password` | string | New password for the conversation

* Response:
    - Status code:
        + `200 OK`
        + `403 Forbidden` When the current user is not a moderator or owner
        + `403 Forbidden` When the conversation is not a public conversation
        + `404 Not Found` When the conversation could not be found for the participant

## Set conversation password

* Method: `PUT`
* Endpoint: `/room/{token}/password`
* Data:

    field | type | Description
    ------|------|------------
    `password` | string | Set a new password for the conversation

* Response:
    - Status code:
        + `200 OK`
        + `403 Forbidden` When the current user is not a moderator/owner
        + `403 Forbidden` When the conversation is not a public conversation
        + `404 Not Found` When the conversation could not be found for the participant

## Add conversation to favorites

* Method: `POST`
* Endpoint: `/room/{token}/favorite`

* Response:
    - Status code:
        + `200 OK`
        + `401 Unauthorized` When the participant is a guest
        + `404 Not Found` When the conversation could not be found for the participant

## Remove conversation from favorites

* Method: `DELETE`
* Endpoint: `/room/{token}/favorite`

* Response:
    - Status code:
        + `200 OK`
        + `401 Unauthorized` When the participant is a guest
        + `404 Not Found` When the conversation could not be found for the participant

## Set notification level

* Method: `POST`
* Endpoint: `/room/{token}/notify`
* Data:

    field | type | Description
    ------|------|------------
    `level` | int | The notification level (See [Participant notification levels](constants.md#Participant-notification-levels))

* Response:
    - Status code:
        + `200 OK`
        + `400 Bad Request` When the the given level is invalid
        + `401 Unauthorized` When the participant is a guest
        + `404 Not Found` When the conversation could not be found for the participant

## Set listable scope for a conversation

* Method: `PUT`
* Endpoint: `/room/{token}/listable`
* Data:

    field | type | Description
    ------|------|------------
    `scope` | int | New flags for the conversation

* Response:
    - Status code:
        + `200 OK`
        + `400 Bad Request` When the conversation type does not support making it listable (only group and public conversation)
        + `403 Forbidden` When the current user is not a moderator/owner or the conversation is not a public conversation
        + `404 Not Found` When the conversation could not be found for the participant
