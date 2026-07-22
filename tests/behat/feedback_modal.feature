@local @local_assign_ai @javascript
Feature: AI feedback modal on the review page
  In order to check and adjust AI-generated feedback before approving it
  As a teacher
  I need to open the feedback details modal and save changes without approving

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
    And the following "local_assign_ai > pending records" exist:
      | assign  | user     | status  | grade | message                      |
      | assign1 | student1 | pending | 85    | Great analysis of the topic. |

  @MDL-INT-016
  Scenario: Teacher opens the details modal, sees the AI feedback and saving keeps the record pending
    Given I am on the "assign1" "local_assign_ai > review" page logged in as "teacher1"
    When I click on "View details" "button" in the "Student One" "table_row"
    Then I should see "AI Feedback" in the ".modal-title" "css_element"
    And the field "airesponse-edit" matches value "Great analysis of the topic."
    And "Save and Approve" "button" should exist in the ".modal-dialog" "css_element"
    When I click on ".save-ai" "css_element" in the ".modal-dialog" "css_element"
    Then I should see "Pending approval" in the "Student One" "table_row"

  @MDL-INT-016
  Scenario: A user without the changestatus capability sees the read-only notice instead of the save buttons
    Given the following "permission overrides" exist:
      | capability                   | permission | role           | contextlevel | reference |
      | local/assign_ai:changestatus | Prevent    | editingteacher | Course       | C1        |
    And I am on the "assign1" "local_assign_ai > review" page logged in as "teacher1"
    And "Approve all" "button" should not exist
    When I click on "View details" "button" in the "Student One" "table_row"
    Then I should see "You do not have permission to save or approve changes to AI reviews." in the ".modal-dialog" "css_element"
    And "Save and Approve" "button" should not exist in the ".modal-dialog" "css_element"
    And ".save-ai" "css_element" should not exist in the ".modal-dialog" "css_element"
