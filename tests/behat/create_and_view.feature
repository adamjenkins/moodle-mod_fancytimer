@mod @mod_fancytimer
Feature: Add a Fancy Timer and view it
  In order to run a timed speaking activity on the classroom screen
  As a teacher
  I need to add a Fancy Timer and see its controls, while students see a mirror of it

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | summary |
      | Course 1 | C1        |         |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |

  Scenario: A teacher adds a timer from the default template and sees the controls
    Given I log in as "teacher1"
    When I add a "fancytimer" activity to course "Course 1" section "1" and I fill the form with:
      | Name | Speaking practice |
    And I am on the "Speaking practice" "fancytimer activity" page
    Then I should see "Person 1 is speaking"
    And I should see "Change groups!"
    And "Start" "button" should exist
    And "Skip" "button" should exist

  Scenario: A student sees the mirror notice and no controls
    Given the following "activities" exist:
      | activity   | name              | course | idnumber |
      | fancytimer | Speaking practice | C1     | timer1   |
    When I am on the "Speaking practice" "fancytimer activity" page logged in as "student1"
    Then I should see "This screen shows the teacher's timer."
    And I should see "Person 1 is speaking"
    And "Start" "button" should not exist

  @javascript
  Scenario: A teacher hides the controls and brings them back by clicking the timer
    Given the following "activities" exist:
      | activity   | name              | course | idnumber |
      | fancytimer | Speaking practice | C1     | timer1   |
    And I am on the "Speaking practice" "fancytimer activity" page logged in as "teacher1"
    When I click on "Hide controls" "button"
    Then "Skip" "button" should not be visible
    And I click on "[data-region='countdown']" "css_element"
    And "Skip" "button" should be visible
