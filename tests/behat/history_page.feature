@local @local_assign_ai
Feature: AI review history page
  In order to audit past AI reviews and recover from failures
  As a teacher
  I need the history report with statuses, logs, grades and retry actions

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
      | student2 | Student   | Two      | student2@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |

  @MDL-INT-018
  Scenario: The history table lists approved and failed records with badges, grades and the retry action
    Given the following "local_assign_ai > pending records" exist:
      | assign  | user     | status  | grade | message          | errormessage       |
      | assign1 | student1 | approve | 90    | Well structured. |                    |
      | assign1 | student2 | failed  |       |                  | AI service timeout |
    When I am on the "assign1" "local_assign_ai > history" page logged in as "teacher1"
    Then I should see "AI review history"
    And I should see "Approved" in the "Student One" "table_row"
    And I should see "Success" in the "Student One" "table_row"
    And I should see "90" in the "Student One" "table_row"
    And I should see "Error" in the "Student Two" "table_row"
    And I should see "Failed" in the "Student Two" "table_row"
    And I should see "AI service timeout" in the "Student Two" "table_row"
    And "Retry failed" "link" should exist

  @MDL-INT-018
  Scenario: The retry action is disabled when there are no failed records
    Given the following "local_assign_ai > pending records" exist:
      | assign  | user     | status  | grade | message          |
      | assign1 | student1 | approve | 90    | Well structured. |
    When I am on the "assign1" "local_assign_ai > history" page logged in as "teacher1"
    Then the "Retry failed" "button" should be disabled

  @MDL-INT-018
  Scenario: The log view shows the record detail including the error message of a failed record
    Given the following "local_assign_ai > pending records" exist:
      | assign  | user     | status | errormessage       |
      | assign1 | student2 | failed | AI service timeout |
    When I am on the "assign1 > student2" "local_assign_ai > log" page logged in as "teacher1"
    Then I should see "AI log details"
    And I should see "Student Two"
    And I should see "AI service timeout"
    And "Download log" "link" should exist
    And "Back to AI review" "link" should exist

  @javascript @MDL-INT-018
  Scenario: Filtering the history report by full name only keeps the matching rows
    Given the following "local_assign_ai > pending records" exist:
      | assign  | user     | status  | grade | message          | errormessage       |
      | assign1 | student1 | approve | 90    | Well structured. |                    |
      | assign1 | student2 | failed  |       |                  | AI service timeout |
    And I am on the "assign1" "local_assign_ai > history" page logged in as "teacher1"
    When I click on "Filters" "button"
    And I set the following fields in the "Full name" "core_reportbuilder > Filter" to these values:
      | Full name operator | Contains    |
      | Full name value    | Student One |
    And I click on "Apply" "button" in the "[data-region='report-filters']" "css_element"
    Then I should see "Student One" in the "reportbuilder-table" "table"
    And I should not see "Student Two" in the "reportbuilder-table" "table"
