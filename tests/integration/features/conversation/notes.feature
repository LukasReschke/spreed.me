Feature: notes
  Background:
    Given user "participant1" exists

  Scenario: Notes is enabled by default and can be toggled
    Given user "participant1" has the notes conversation
    When user "participant1" sets setting "notes" to "0" with 200
    Then user "participant1" has no notes conversation
    When user "participant1" sets setting "notes" to "1" with 200
    Then user "participant1" has the notes conversation

  Scenario: Notes is always new
    Given user "participant1" has the notes conversation
    Then user "participant1" sees the following messages in room "participant1-notes" with 200
      | room               | actorType | actorId | actorDisplayName         | message   | messageParameters |
      | participant1-notes | bots      | notes   | My notes                 | Welcome to your notes!\nYou can use this conversation to share notes between your different devices. When you deleted it, you can recreate it via the settings. | []                |
    When user "participant1" sends message "Another note" to room "participant1-notes" with 201
    Then user "participant1" sees the following messages in room "participant1-notes" with 200
      | room               | actorType | actorId      | actorDisplayName         | message   | messageParameters |
      | participant1-notes | users     | participant1 | participant1-displayname | Another note | []                |
      | participant1-notes | bots      | notes        | My notes                 | Welcome to your notes!\nYou can use this conversation to share notes between your different devices. When you deleted it, you can recreate it via the settings. | []                |
    When user "participant1" sets setting "notes" to "0" with 200
    Then user "participant1" has no notes conversation
    When user "participant1" sets setting "notes" to "1" with 200
    Then user "participant1" has the notes conversation
    And user "participant1" sees the following messages in room "participant1-notes" with 200
      | room               | actorType | actorId | actorDisplayName         | message   | messageParameters |
      | participant1-notes | bots      | notes   | My notes                 | Welcome to your notes!\nYou can use this conversation to share notes between your different devices. When you deleted it, you can recreate it via the settings. | []                |
