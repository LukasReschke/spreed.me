# Call API

* API v1: 🏁 Removed with API v4
* API v2: 🏁 Removed with API v4
* API v3: 🏁 Removed with API v4
* API v4: Base endpoint `/ocs/v2.php/apps/spreed/api/v4`

## Get list of connected participants

* Method: `GET`
* Endpoint: `/call/{token}`

* Response:
    - Status code:
        + `200 OK`
        + `403 Forbidden` When the conversation is read-only
        + `404 Not Found` When the conversation could not be found for the participant
        + `412 Precondition Failed` When the lobby is active and the user is not a moderator

    - Data:
        Array of participants, each participant has at least:

        field | type | Added | Removed | Description
        ---|---|---|---|---
        `actorType` | string | v3 | | Actor type of the attendee (see [Constants - Attendee types](constants.md#attendee-types))
        `actorId` | string | v3 | | The user id, guest random id or email address of the attendee
        `userId` | string | v1 | v3 | The user id replaced with actorType = users and actorId being the userId
        `displayName` | string | v3 | | The display name of the attendee
        `lastPing` | int | v1 | | Timestamp of the last ping of the user (should be used for sorting)
        `sessionId` | string | v1 | | 512 character long string
        `publishingPermissions` | int | v4 | Publishing permissions for the participant (see [constants list](constants.md#participant-publishing-permissions))

## Join a call

* Method: `POST`
* Endpoint: `/call/{token}`
* Data:

    field | type | Description
    ---|---|---
    `flags` | int | Flags what streams are provided by the participant (see [Constants - Participant in-call flag](constants.md#participant-in-call-flag))

* Response:
    - Status code:
        + `200 OK`
        + `403 Forbidden` When the conversation is read-only
        + `404 Not Found` When the conversation could not be found for the participant
        + `404 Not Found` When the user did not join the conversation before
        + `412 Precondition Failed` When the lobby is active and the user is not a moderator

## Leave a call (but staying in the conversation for future calls and chat)

* Method: `DELETE`
* Endpoint: `/call/{token}`

* Response:
    - Status code:
        + `200 OK`
        + `403 Forbidden` When the conversation is read-only
        + `404 Not Found` When the conversation could not be found for the participant
        + `404 Not Found` When the user did not join the conversation before
        + `412 Precondition Failed` When the lobby is active and the user is not a moderator
