@local @local_assign_ai
Feature: Datacurso Assign AI section in the assignment settings form
  In order to control AI reviews per assignment
  As a teacher
  I need the AI settings section with fields that follow the enable chains

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "activities" exist:
      | activity | course | name        | idnumber |
      | assign   | C1     | Test assign | assign1  |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |

  @javascript @MDL-INT-002
  Scenario: The AI section is shown and auto-grade reveals the delay and grader fields
    Given I am on the "Test assign" "assign activity editing" page logged in as "teacher1"
    When I expand all fieldsets
    Then I should see "Datacurso Assign AI"
    And I should see "Enable AI"
    And I should see "Auto-approve AI feedback"
    And I should see "Give instructions to the AI"
    And I should see "AI response language"
    And I should not see "Use delayed review"
    And I should not see "Recorded grader for auto approvals"
    And I should not see "Delay time (minutes)"
    When I set the field "Auto-approve AI feedback" to "Yes"
    Then I should see "Use delayed review"
    And I should see "Recorded grader for auto approvals"
    And I should not see "Delay time (minutes)"
    When I set the field "Use delayed review" to "Yes"
    Then I should see "Delay time (minutes)"

  @javascript @MDL-INT-002
  Scenario: Disabling AI for the assignment hides every dependent field
    Given I am on the "Test assign" "assign activity editing" page logged in as "teacher1"
    When I expand all fieldsets
    And I set the field "Enable AI" to "No"
    Then I should not see "Auto-approve AI feedback"
    And I should not see "Give instructions to the AI"
    And I should not see "AI response language"
    And I should not see "Use delayed review"
    And I should not see "Delay time (minutes)"

  @javascript @MDL-INT-001
  Scenario: When AI is globally disabled the Enable AI field is locked to No
    Given the following config values are set as admin:
      | defaultenableai | 0 | local_assign_ai |
    And I am on the "Test assign" "assign activity editing" page logged in as "teacher1"
    When I expand all fieldsets
    Then the field "Enable AI" matches value "No"
    When I set the field "Enable AI" to "Yes"
    Then the field "Enable AI" matches value "No"
    And I should see "AI activation for this assignment is unavailable because it has been globally disabled by an administrator."

  @MDL-INT-001 @MDL-INT-020
  Scenario: A teacher reaches the review page from the activity administration when AI is enabled
    Given the following "local_assign_ai > configs" exist:
      | assign  | enableai |
      | assign1 | 1        |
    When I am on the "Test assign" "assign activity" page logged in as "teacher1"
    And I navigate to "Review with AI" in current page administration
    Then I should see "Review with AI"
    And I should see "Back to course"

  @MDL-INT-001
  Scenario: The AI review links are hidden from the activity navigation when AI is globally disabled
    Given the following config values are set as admin:
      | defaultenableai | 0 | local_assign_ai |
    When I am on the "Test assign" "assign activity" page logged in as "teacher1"
    Then "Review with AI" "link" should not exist
    And "AI review history" "link" should not exist
