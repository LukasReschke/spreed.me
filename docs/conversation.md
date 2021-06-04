# Conversation API

* API v1: 🏁 Removed with API v4
* API v2: 🏁 Removed with API v4
* API v3: 🏁 Removed with API v4
* API v4: Base endpoint `/ocs/v2.php/apps/spreed/api/v4`

## Get user´s conversations

* Method: `GET`
* Endpoint: `/room`

* Response:
    - Status code:
        + `200 OK`
        + `401 Unauthorized` when the user is not logged in

    - Header:

        field | type | Description
        ---|---|---
        `X-Nextcloud-Talk-Hash` | string | Sha1 value over some config. When you receive a different value on subsequent requests, the capabilities and the signaling settings should be refreshed.

    - Data:
        Array of conversations, each conversation has at least:

        field | type | Added | Removed | Description
        ---|---|---|---|---
        `id` | int | v1 | | Numeric identifier of the conversation
        `token` | string | v1 | | Token identifier of the conversation which is used for further interaction
        `type` | int | v1 | | See list of conversation types in the [constants list](constants.md#conversation-types)
        `name` | string | v1 | | Name of the conversation (can also be empty)
        `displayName` | string | v1 | | `name` if non empty, otherwise it falls back to a list of participants
        `description` | string | v3 | | Description of the conversation (can also be empty) (only available with `room-description` capability)
        `participantType` | int | v1 | | Permissions level of the current user
        `attendeeId` | int | v3 | | Unique attendee id
        `attendeePin` | string | v3 | | Unique dial-in authentication code for this user, when the conversation has SIP enabled (see `sipEnabled` attribute)
        `actorType` | string | v3 | | Currently known `users|guests|emails|groups`
        `actorId` | string | v3 | | The unique identifier for the given actor type
        `publishingPermissions` | int | v4 | Publishing permissions for the current participant (see [constants list](constants.md#participant-publishing-permissions))
        `participantInCall` | bool | v1 | v2 | **Removed:** use `participantFlags` instead
        `participantFlags` | int | v1 | | "In call" flags of the user's session making the request (only available with `in-call-flags` capability)
        `readOnly` | int | v1 | | Read-only state for the current user (only available with `read-only-rooms` capability)
        `listable` | int | v3 | | Listable scope for the room (only available with `listable-rooms` capability)
        `count` | int | v1 | v2 | **Removed:** Count the users on the [Get list of participants in a conversation](participant.md#get-list-of-participants-in-a-conversation) endpoint
        `numGuests` | int | v1 | v2 | **Removed:** Count the guests on the [Get list of participants in a conversation](participant.md#get-list-of-participants-in-a-conversation) endpoin
        `lastPing` | int | v1 | | Timestamp of the user's session making the request
        `sessionId` | string | v1 | | `'0'` if not connected, otherwise an up to 512 character long string that is the identifier of the user's session making the request. Should only be used to pre-check if the user joined already with this session, but this might be outdated by the time of usage, so better check via [Get list of participants in a conversation](participant.md#get-list-of-participants-in-a-conversation)
        `hasPassword` | bool | v1 | | Flag if the conversation has a password
        `hasCall` | bool | v1 | | Flag if the conversation has an active call
        `callFlag` | int | v3 | | Combined flag of all participants in the current call (see [constants list](constants.md#participant-in-call-flag), only available with `conversation-call-flags` capability)
        `canStartCall` | bool | v1 | | Flag if the user can start a new call in this conversation (joining is always possible) (only available with `start-call-flag` capability)
        `canDeleteConversation` | bool | v2 | | Flag if the user can delete the conversation for everyone (not possible without moderator permissions or in one-to-one conversations)
        `canLeaveConversation` | bool | v2 | | Flag if the user can leave the conversation (not possible for the last user with moderator permissions)
        `lastActivity` | int | v1 | | Timestamp of the last activity in the conversation, in seconds and UTC time zone
        `isFavorite` | bool | v1 | | Flag if the conversation is favorited by the user
        `notificationLevel` | int | v1 | | The notification level for the user (See [Participant notification levels](constants.md#Participant-notification-levels))
        `lobbyState` | int | v1 | | Webinar lobby restriction (0-1), if the participant is a moderator they can always join the conversation (only available with `webinary-lobby` capability) (See [Webinar lobby states](constants.md#webinar-lobby-states))
        `lobbyTimer` | int | v1 | | Timestamp when the lobby will be automatically disabled (only available with `webinary-lobby` capability)
        `sipEnabled` | int | v3 | | SIP enable status (0-1)
        `canEnableSIP` | int | v3 | | Whether the given user can enable SIP for this conversation. Note that when the token is not-numeric only, SIP can not be enabled even if the user is permitted and a moderator of the conversation
        `unreadMessages` | int | v1 | | Number of unread chat messages in the conversation (only available with `chat-v2` capability)
        `unreadMention` | bool | v1 | | Flag if the user was mentioned since their last visit
        `lastReadMessage` | int | v1 | | ID of the last read message in a room (only available with `chat-read-marker` capability)
        `lastCommonReadMessage` | int | v3 | | ID of the last message read by every user that has read privacy set to public in a room. When the user themself has it set to private the value is `0` (only available with `chat-read-status` capability)
        `lastMessage` | message | v1 | | Last message in a conversation if available, otherwise empty
        `objectType` | string | v1 | | The type of object that the conversation is associated with; "share:password" if the conversation is used to request a password for a share, otherwise empty
        `objectId` | string | v1 | | Share token if "objectType" is "share:password", otherwise empty
        `participants` | array | v1 | v2 | **Removed**
        `guestList` | string | v1 | v2 | **Removed**

## Creating a new conversation

* Method: `POST`
* Endpoint: `/room`
* Data:

    field | type | Description
    ---|---|---
    `roomType` | int | See [constants list](constants.md#conversation-types)
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
        ---|---|---
        `X-Nextcloud-Talk-Hash` | string | Sha1 value over some config. When you receive a different value on subsequent requests, the capabilities and the signaling settings should be refreshed.

    - Data: See array definition in `Get user´s conversations`

## Get open conversations

* Required capability: `listable-rooms`
* Method: `GET`
* Endpoint: `/listed-room`

* Response:
    - Status code:
        + `200 OK`
        + `401 Unauthorized` when the user is not logged in

    - Header:

        field | type | Description
        ---|---|---
        `searchTerm` | string | search term

    - Data: See array definition in `Get user´s conversations`

## Rename a conversation

* Method: `PUT`
* Endpoint: `/room/{token}`
* Data:

    field | type | Description
    ---|---|---
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

* Required capability: `room-description`
* Method: `PUT`
* Endpoint: `/room/{token}/description`
* Data:

    field | type | Description
    ---|---|---
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

* Required capability: `read-only-rooms`
* Method: `PUT`
* Endpoint: `/room/{token}/read-only`
* Data:

    field | type | Description
    ---|---|---
    `state` | int | New state for the conversation, see [constants list](constants.md#read-only-states)

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
    ---|---|---
    `password` | string | New password for the conversation

* Response:
    - Status code:
        + `200 OK`
        + `403 Forbidden` When the current user is not a moderator or owner
        + `403 Forbidden` When the conversation is not a public conversation
        + `404 Not Found` When the conversation could not be found for the participant

## Add conversation to favorites

* Required capability: `favorites`
* Method: `POST`
* Endpoint: `/room/{token}/favorite`

* Response:
    - Status code:
        + `200 OK`
        + `401 Unauthorized` When the participant is a guest
        + `404 Not Found` When the conversation could not be found for the participant

## Remove conversation from favorites

* Required capability: `favorites`
* Method: `DELETE`
* Endpoint: `/room/{token}/favorite`

* Response:
    - Status code:
        + `200 OK`
        + `401 Unauthorized` When the participant is a guest
        + `404 Not Found` When the conversation could not be found for the participant

## Set notification level

* Required capability: `notification-levels`
* Method: `POST`
* Endpoint: `/room/{token}/notify`
* Data:

    field | type | Description
    ---|---|---
    `level` | int | The notification level (See [Participant notification levels](constants.md#Participant-notification-levels))

* Response:
    - Status code:
        + `200 OK`
        + `400 Bad Request` When the given level is invalid
        + `401 Unauthorized` When the participant is a guest
        + `404 Not Found` When the conversation could not be found for the participant

## Open a conversation

* Required capability: `listable-rooms`
* Method: `PUT`
* Endpoint: `/room/{token}/listable`
* Data:

    field | type | Description
    ---|---|---
    `scope` | int | New flags for the conversation

* Response:
    - Status code:
        + `200 OK`
        + `400 Bad Request` When the conversation type does not support making it listable (only group and public conversation)
        + `403 Forbidden` When the current user is not a moderator/owner or the conversation is not a public conversation
        + `404 Not Found` When the conversation could not be found for the participant
