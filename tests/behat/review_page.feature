@local @local_assign_ai
Feature: Review with AI page
  In order to manage AI-generated feedback for assignment submissions
  As a teacher
  I need to see every pending AI record with its status and the mass action buttons

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
      | student3 | Student   | Three    | student3@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |

  @MDL-INT-014
  Scenario: Teacher sees the unconfigured service warning and one row per record with its status badge
    Given the following "local_assign_ai > pending records" exist:
      | assign  | user     | status  | grade | message   | errormessage       |
      | assign1 | student1 | initial |       |           |                    |
      | assign1 | student2 | pending | 80    | Good work |                    |
      | assign1 | student3 | failed  |       |           | AI service timeout |
    When I am on the "assign1" "local_assign_ai > review" page logged in as "teacher1"
    Then I should see "AI review actions are unavailable because the Datacurso web service is not configured"
    And I should see "Review with AI"
    And I should see "Pending AI review" in the "Student One" "table_row"
    And I should see "Pending approval" in the "Student Two" "table_row"
    And I should see "Error" in the "Student Three" "table_row"
    And I should see "AI service timeout" in the "Student Three" "table_row"
    And I should see "80" in the "Student Two" "table_row"

  @MDL-INT-014
  Scenario: Review all is enabled when there are records pending AI review and Approve all is disabled without proposals
    Given the following "local_assign_ai > pending records" exist:
      | assign  | user     | status  |
      | assign1 | student1 | initial |
    When I am on the "assign1" "local_assign_ai > review" page logged in as "teacher1"
    Then the "Review all" "button" should be enabled
    And the "Approve all" "button" should be disabled

  @MDL-INT-014
  Scenario: Review all is disabled without initial records and Approve all is enabled with pending proposals
    Given the following "local_assign_ai > pending records" exist:
      | assign  | user     | status  | grade | message   |
      | assign1 | student2 | pending | 80    | Good work |
    When I am on the "assign1" "local_assign_ai > review" page logged in as "teacher1"
    Then the "Review all" "button" should be disabled
    And the "Approve all" "button" should be enabled

  @javascript @MDL-INT-015
  Scenario: Queued and processing rows show their badges and the mass action buttons reflect the current states
    Given the following "local_assign_ai > pending records" exist:
      | assign  | user     | status     |
      | assign1 | student1 | queued     |
      | assign1 | student2 | processing |
    When I am on the "assign1" "local_assign_ai > review" page logged in as "teacher1"
    Then I should see "queued" in the "Student One" "table_row"
    And I should see "Processing" in the "Student Two" "table_row"
    And ".js-progress-indicator" "css_element" should exist
    And "Cancel" "button" should exist in the "Student One" "table_row"
    And "Cancel" "button" should exist in the "Student Two" "table_row"
    And the "Review all" "button" should be disabled
    And the "Approve all" "button" should be disabled

  @MDL-INT-020
  Scenario: A student does not see the AI review links in the assignment navigation
    When I am on the "Test assign" "assign activity" page logged in as "student1"
    Then "Review with AI" "link" should not exist
    And "AI review history" "link" should not exist

  @MDL-INT-020
  Scenario: A student cannot open the review page directly
    Given I log in as "student1"
    Then the "assign1" "review" page of local_assign_ai should deny access
