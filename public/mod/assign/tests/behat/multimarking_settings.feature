@mod @mod_assign
Feature: Assignment multimarking settings
  In order to allow managing assignments
  As a teacher
  I need to know the impact of changing assignment multimarking settings.

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "activity" exists:
      | activity                            | assign                  |
      | course                              | C1                      |
      | name                                | Test assignment name    |
      | intro                               | Submit your online text |
      | assignsubmission_onlinetext_enabled | 1                       |
    And the following "users" exist:
      | username  | firstname  | lastname  | email                 |
      | teacher1  | Teacher    | 1         | teacher1@example.com  |
      | teacher2  | Teacher    | 2         | teacher2@example.com  |
      | student1  | Student    | 1         | student1@example.com  |
      | student2  | Student    | 2         | student2@example.com  |
    And the following "course enrolments" exist:
      | user      | course  | role            |
      | teacher1  | C1      | editingteacher  |
      | teacher2  | C1      | editingteacher  |
      | student1  | C1      | student         |
      | student2  | C1      | student         |
    And the following "mod_assign > submissions" exist:
      | assign                | user      | onlinetext                   |
      | Test assignment name  | student1  | I'm the student1 submission  |

  Scenario: Administrators manage one resolution method per plugin
    Given I log in as "admin"
    And I navigate to "Plugins > Activity modules > Assignment > Resolution plugins > Manage assignment resolution plugins" in site administration
    Then "Maximum mark" "table_row" should exist in the "assignresolutionplugins" "table"
    And "Average mark" "table_row" should exist in the "assignresolutionplugins" "table"
    And "Manual" "table_row" should exist in the "assignresolutionplugins" "table"

  Scenario: Administrators control resolution method visibility
    Given I log in as "admin"
    And I navigate to "Plugins > Activity modules > Assignment > Resolution plugins > Manage assignment resolution plugins" in site administration
    Then "Maximum mark" "table_row" should exist in the "assignresolutionplugins" "table"
    When I click on "Disable" "link" in the "Maximum mark" "table_row"
    Then "Enable" "link" should exist in the "Maximum mark" "table_row"
    When I click on "Enable" "link" in the "Maximum mark" "table_row"
    Then "Disable" "link" should exist in the "Maximum mark" "table_row"

  Scenario: Administrators control resolution methods order
    Given I log in as "admin"
    And I navigate to "Plugins > Activity modules > Assignment > Resolution plugins > Manage assignment resolution plugins" in site administration
    And "Down" "link" should not exist in the "Maximum mark" "table_row"
    When I click on "Up" "link" in the "Maximum mark" "table_row"
    Then "Down" "link" should exist in the "Maximum mark" "table_row"
    When I click on "Down" "link" in the "Maximum mark" "table_row"
    Then "Down" "link" should not exist in the "Maximum mark" "table_row"

  @javascript
  Scenario: Changing settings that recalculate grades from marks give a warning
    # Initially add grades.
    Given I am on the "Test assignment name" Activity page logged in as teacher1
    And I go to "Student 1" "Test assignment name" activity advanced grading page
    And I set the field "Grade out of 100" to "40"
    And I set the field "Notify student" to "0"
    And I press "Save changes"
    And I follow "View all submissions"
    And "Student 1" row "Grade" column of "generaltable" table should contain "40.00"

    # Require explicit confirmation of what to do with existing grades.
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    And I set the field "Use marking workflow" to "Yes"
    And I set the field "Use marking allocation" to "Yes"
    And I set the field "Number of required markers" to "2"
    And I set the field "Number of optional markers" to "3"
    And I should see "Update agreed grades"
    And I press "Save and display"
    Then "#id_error_multimarkupdate" "css_element" should be visible
    And I set the field "Update agreed grades" to "Keep current agreed grades"
    And I press "Save and display"
    And I navigate to "Submissions" in current page administration
    And "Student 1" row "Grade" column of "generaltable" table should contain "40.00"

    # Change the resolution method.
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    And the "Resolution method" "select" should be disabled
    And I set the field "Update agreed grades" to "Recalculate agreed grades"
    And I set the field "Resolution method" to "Average mark"
    And I press "Save and display"
    And I navigate to "Submissions" in current page administration
    And "Student 1" row "Grade" column of "generaltable" table should contain ""
