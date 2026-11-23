@mod @mod_fancytimer
Feature: Edit the segments of a Fancy Timer
  In order to fit the timer to my class
  As a teacher
  I need to change a segment's label on the Segments page and see it on the timer

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

  Scenario: A teacher renames a segment and sees the new label on the view page
    Given I am on the "Speaking practice" "fancytimer activity" page logged in as "teacher1"
    And I navigate to "Segments" in current page administration
    And the field "id_label_1" matches value "Person 2 is speaking"
    When I set the field "id_label_1" to "Ana is speaking"
    And I press "Save segments"
    Then I should see "Segments saved."
    And the field "id_label_1" matches value "Ana is speaking"
    And I am on the "Speaking practice" "fancytimer activity" page
    And I should see "Ana is speaking"
    And I should see "Person 1 is speaking"
    And I should not see "Person 2 is speaking"

  Scenario: A teacher cannot save a segment without a label
    Given I am on the "Speaking practice" "fancytimer activity" page logged in as "teacher1"
    And I navigate to "Segments" in current page administration
    When I set the field "id_label_2" to ""
    And I press "Save segments"
    Then I should see "Enter a label for this segment."
    And I should not see "Segments saved."
