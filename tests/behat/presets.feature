@mod @mod_fancytimer
Feature: Presets and templates for a Fancy Timer
  In order to reuse timer set-ups
  As a teacher
  I need to save the timer as a preset and apply a bundled template

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | summary |
      | Course 1 | C1        |         |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity   | name              | course | idnumber |
      | fancytimer | Speaking practice | C1     | timer1   |

  Scenario: A teacher saves the timer as a preset
    Given I am on the "Speaking practice" "fancytimer activity" page logged in as "teacher1"
    And I navigate to "Presets" in current page administration
    And I should see "You have no saved presets yet."
    When I set the field "Preset name" to "Our speaking routine"
    And I press "Save preset"
    Then I should see "was saved."
    And "Apply to this timer" "button" should exist in the "Our speaking routine" "list_item"
    And I should not see "You have no saved presets yet."

  Scenario: A teacher applies a bundled template after confirming
    Given I am on the "Speaking practice" "fancytimer activity" page logged in as "teacher1"
    And I navigate to "Presets" in current page administration
    When I click on "Apply to this timer" "button" in the "Simple countdown (5 min)" "list_item"
    Then I should see "All of its current segments"
    And I press "Apply to this timer"
    And I should see "The timer now uses"
    And the field "id_label_0" matches value "Time left"
    And I am on the "Speaking practice" "fancytimer activity" page
    And I should see "Time left"
    And I should see "5:00"
    And I should not see "Person 1 is speaking"
