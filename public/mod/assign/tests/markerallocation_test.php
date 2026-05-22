<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_assign;

use assign;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/lib/accesslib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Unit tests for (some of) mod/assign/markerallocaion_test.php.
 *
 * @package    mod_assign
 * @category   test
 * @copyright  2017 Andrés Melo <andres.torres@blackboard.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \assign
 */
final class markerallocation_test extends \advanced_testcase {

    /** @var \stdClass course record. */
    private $course;

    /**
     * @var array Generated users
     */
    private array $users = [];

    /**
     * @var array Generated groups
     */
    private array $groups = [];

    /**
     * Create the assignment object for testing.
     *
     * @param array $args Array of options that can be overwritten.
     * @return assign
     */
    private function create_assignment(array $args = []): assign {
        $modulesettings = [
            'course'                            => $this->course->id,
            'alwaysshowdescription'             => 1,
            'submissiondrafts'                  => 1,
            'requiresubmissionstatement'        => 0,
            'sendnotifications'                 => 0,
            'sendstudentnotifications'          => 1,
            'sendlatenotifications'             => 0,
            'duedate'                           => 0,
            'allowsubmissionsfromdate'          => 0,
            'grade'                             => (!isset($args['scale'])) ? 100 : null,
            'cutoffdate'                        => 0,
            'teamsubmission'                    => ($args['teamsubmission']) ?? 0,
            'requireallteammemberssubmit'       => 0,
            'blindmarking'                      => 0,
            'attemptreopenmethod'               => 'untilpass',
            'maxattempts'                       => 1,
            'markingworkflow'                   => 1,
            'markingallocation'                 => 1,
            'markercount'                       => ($args['markercount']) ?? ASSIGN_MULTIMARKING_DEFAULT_MARKERS,
            'optionalmarkercount'               => ($args['optionalmarkercount']) ?? ASSIGN_MULTIMARKING_DEFAULT_OPTIONAL_MARKERS,
            'multimarkmethod'                   => ($args['multimarkmethod']) ?? ASSIGN_MULTIMARKING_METHOD_MANUAL,
            'multimarkrounding'                 => ($args['multimarkrounding']) ?? null,
        ];

        if (isset($args['scale'])) {
            $scale = $this->getDataGenerator()->create_scale();
            $modulesettings['gradetype'] = GRADE_TYPE_SCALE;
            $modulesettings['gradescale'] = $scale->id;
        }

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $instance = $generator->create_instance($modulesettings);
        [$course, $cm] = get_course_and_cm_from_instance($instance->id, 'assign');
        $context = \core\context\module::instance($cm->id);
        $assignment = new assign($context, $cm, $course);
        return $assignment;
    }

    /**
     * Updates an assignment instance.
     * @param assign $assignment
     * @param array $updatefields
     */
    private function update_assignment_instance(assign $assignment, array $updatefields): void {
        // Need to clone so the update can detect the differences.
        $instance = clone $assignment->get_instance();
        $instance->instance = $instance->id;
        $instance->advancedgradingmethod_submissions = '';
        foreach ($updatefields as $key => $value) {
            $instance->$key = $value;
        }
        $assignment->update_instance($instance);
    }

    /**
     * Setup all required test data.
     */
    private function setup_data(): void {
        global $DB;

        $this->resetAfterTest();

        // Create a course, by default it is created with 5 sections.
        $this->course = $this->getDataGenerator()->create_course();

        // Adding users to the course.
        $userdata = array();
        $userdata['firstname'] = 'teacher1';
        $userdata['lasttname'] = 'lastname_teacher1';
        $this->users[0] = $this->getDataGenerator()->create_user($userdata);
        $this->getDataGenerator()->enrol_user($this->users[0]->id, $this->course->id, 'editingteacher');

        $userdata = array();
        $userdata['firstname'] = 'teacher2';
        $userdata['lasttname'] = 'lastname_teacher2';
        $this->users[1] = $this->getDataGenerator()->create_user($userdata);
        $this->getDataGenerator()->enrol_user($this->users[1]->id, $this->course->id, 'editingteacher');

        $userdata = array();
        $userdata['firstname'] = 'student';
        $userdata['lasttname'] = 'lastname_student';
        $this->users[2] = $this->getDataGenerator()->create_user($userdata);
        $this->getDataGenerator()->enrol_user($this->users[2]->id, $this->course->id, 'student');

        // Adding manager to the system.
        $userdata = array();
        $userdata['firstname'] = 'Manager';
        $userdata['lasttname'] = 'lastname_Manager';
        $this->users[3] = $this->getDataGenerator()->create_user($userdata);
        $managerrole = $DB->get_record('role', array('shortname' => 'manager'));
        if (!empty($managerrole)) {
            // By default the context of the system is assigned.
            $this->getDataGenerator()->role_assign($managerrole->id, $this->users[3]->id);
        }
    }

    /**
     * Setup group data for teamsubmission tests.
     */
    private function setup_group_data(): void {
        $this->resetAfterTest(false);

        // Create a course, by default it is created with 5 sections.
        $this->course = $this->getDataGenerator()->create_course();

        // Split users into seaprate arrays for easier use here.
        $teachers = [];
        $students = [];

        // Adding teachers to the course.
        for ($i = 1; $i <= 2; $i++) {
            $userdata = [];
            $userdata['firstname'] = 'teacher' . $i;
            $userdata['lasttname'] = 'lastname_teacher' . $i;
            $teachers[$i] = $this->getDataGenerator()->create_user($userdata);
            $this->getDataGenerator()->enrol_user($teachers[$i]->id, $this->course->id, 'teacher');
        }

        // Adding students to the course.
        for ($i = 1; $i <= 6; $i++) {
            $userdata = [];
            $userdata['firstname'] = 'student' . $i;
            $userdata['lasttname'] = 'lastname_student' . $i;
            $students[$i] = $this->getDataGenerator()->create_user($userdata);
            $this->getDataGenerator()->enrol_user($students[$i]->id, $this->course->id, 'student');
        }

        // Adding students to groups.
        $this->groups['A'] = $this->getDataGenerator()->create_group(['courseid' => $this->course->id, 'name' => 'A']);
        $this->groups['B'] = $this->getDataGenerator()->create_group(['courseid' => $this->course->id, 'name' => 'B']);
        foreach ($students as $studentnumber => $user) {
            if ($studentnumber <= 3) {
                groups_add_member($this->groups['A'], $user);
            } else {
                groups_add_member($this->groups['B'], $user);
            }
        }

        $this->users = ['students' => $students, 'teachers' => $teachers];
    }

    /**
     * Test marker allocation and marking with group submissions.
     *
     * @covers ::update_allocated_markers, ::save_grade
     */
    public function test_allocated_markers_with_group_submissions(): void {
        $this->setup_group_data();
        $assignment = $this->create_assignment([
            'teamsubmission' => 1,
        ]);

        // To test the logic that a marker should not be able to update anyone not in their group
        // we will use the "public" method `save_grade` instead of the internal `update_mark`.
        // Firstly, allocate teacher1 to every student in group A.
        foreach ($this->users['students'] as $studentnumber => $student) {
            if ($studentnumber <= 3) {
                $assignment->update_allocated_markers($student->id, [1 => $this->users['teachers'][1]->id]);
            }
        }

        // Allocate a mark to the first student in the group.
        // This should spread out to the other students in the group as well.
        $this->setUser($this->users['teachers'][1]);

        // Before we save it, we need to create the submission record, which won't happen from just saving it.
        // We are passing -1 as userid because it's a required argument, but if the groupid is present, then
        // the `get_group_submission` function ignores it, so it just needs any value really.
        $assignment->get_group_submission(-1, $this->groups['A']->id, true);

        // Then save it.
        $assignment->save_grade($this->users['students'][1]->id, (object)[
            'mark' => 50,
            'applytoall' => 1,
            'attemptnumber' => -1,
        ]);

        // All 3 students in the group should now have the same mark from this allocated marker.
        foreach ($this->users['students'] as $studentnumber => $student) {
            if ($studentnumber <= 3) {
                $gradeobject = $assignment->get_user_grade($student->id, true);
                $mark = $assignment->get_mark($gradeobject->id, $this->users['teachers'][1]->id);
                $this->assertEquals(50, $mark->mark);
            }
        }

        // Now allocate teacher2 to 2 out of 3 students in group B.
        foreach ($this->users['students'] as $studentnumber => $student) {
            if ($studentnumber > 3 && $studentnumber < 6) {
                $assignment->update_allocated_markers($student->id, [1 => $this->users['teachers'][2]->id]);
            }
        }

        // Allocate a mark to the first student in the group.
        $this->setUser($this->users['teachers'][2]);
        $assignment->get_group_submission(-1, $this->groups['B']->id, true);
        $assignment->save_grade($this->users['students'][4]->id, (object)[
            'mark' => 99,
            'applytoall' => 1,
            'attemptnumber' => -1,
        ]);

        // Only 2 out of 3 students should have the grade applied.
        foreach ($this->users['students'] as $studentnumber => $student) {
            if ($studentnumber > 3) {
                $gradeobject = $assignment->get_user_grade($student->id, true);
                $mark = $assignment->get_mark($gradeobject->id, $this->users['teachers'][2]->id);
                if ($studentnumber < 6) {
                    $this->assertEquals(99, $mark->mark);
                } else {
                    $this->assertNull($mark);
                }
            }
        }
    }

    /**
     * Create all the needed elements to test the difference between both functions.
     *
     * @coversNothing
     */
    public function test_markerusers(): void {
        $this->setup_data();

        $oldusers = [$this->users[0], $this->users[1], $this->users[3]];
        $newusers = [$this->users[0], $this->users[1]];

        list($sort, $params) = users_order_by_sql('u');

        // Old code, it must return 3 users: teacher1, teacher2 and Manger.
        $oldmarkers = get_users_by_capability(\context_course::instance($this->course->id), 'mod/assign:grade', '', $sort);
        // New code, it must return 2 users: teacher1 and teacher2.
        $newmarkers = get_enrolled_users(\context_course::instance($this->course->id), 'mod/assign:grade', 0, 'u.*', $sort);

        // Test result quantity.
        $this->assertEquals(count($oldusers), count($oldmarkers));
        $this->assertEquals(count($newusers), count($newmarkers));
        $this->assertEquals(count($oldmarkers) > count($newmarkers), true);

        // Elements expected with new code.
        foreach ($newmarkers as $key => $nm) {
            $this->assertEquals($nm, $newusers[array_search($nm, $newusers)]);
        }

        // Elements expected with old code.
        foreach ($oldusers as $key => $os) {
            $this->assertEquals($os->id, $oldmarkers[$os->id]->id);
            unset($oldmarkers[$os->id]);
        }

        $this->assertEquals(count($oldmarkers), 0);
    }

    /**
     * Test functionality around having multiple allocated markers.
     *
     * @covers ::update_allocated_markers, ::update_mark
     */
    public function test_multiple_marker_allocation(): void {

        $this->setup_data();
        $assignment = $this->create_assignment();

        // To start with, confirm that no markers are allocated to the student submission.
        $markers = $assignment->get_allocated_markers($this->users[2]->id);
        $this->assertCount(0, $markers);

        // Wait a small amount of time so we can test whether grade timemodified is updated.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $timemodified = $gradeobject->timemodified;
        sleep(1);

        // Allocate both teachers to the student assignment.
        $assignment->update_allocated_markers($this->users[2]->id, [
            1 => $this->users[0]->id,
            2 => $this->users[1]->id,
        ]);
        $markers = $assignment->get_allocated_markers($this->users[2]->id);
        $this->assertCount(2, $markers);

        // Changing allocated markers should update grade timemodified as it's used to prevent stale form submissions.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, false);
        $this->assertNotEquals($timemodified, $gradeobject->timemodified);

        // Now test that we can add a mark to the submission.
        // Firstly, there should be no mark currently for either marker.
        $mark = $assignment->get_mark($gradeobject->id, $this->users[0]->id);
        $this->assertNull($mark);

        // Wait a small amount of time so we can test whether grade timemodified is updated.
        $timemodified = $gradeobject->timemodified;
        sleep(1);

        // Assign a mark as teacher1.
        $gradeobject->grader = $this->users[0]->id;
        $assignment->update_mark($gradeobject, 99);

        // Now check that we can find the mark.
        $mark = $assignment->get_mark($gradeobject->id, $this->users[0]->id);
        $this->assertEquals("99.00000", $mark->mark);

        // Assign a mark as teacher2.
        $gradeobject->grader = $this->users[1]->id;
        $assignment->update_mark($gradeobject, 11);

        // Now check that we can find the mark.
        $mark = $assignment->get_mark($gradeobject->id, $this->users[1]->id);
        $this->assertEquals("11.00000", $mark->mark);

        // Updating marks should also update grade timemodified.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, false);
        $this->assertNotEquals($timemodified, $gradeobject->timemodified);
    }

    /**
     * Test manual calculation of final grade.
     *
     * @covers ::update_mark
     */
    public function test_calculated_marker_grade_manual(): void {
        $this->setup_data();
        $assignment = $this->create_assignment();

        // Allocate both teachers to the student assignment.
        $assignment->update_allocated_markers($this->users[2]->id, [
            1 => $this->users[0]->id,
            2 => $this->users[1]->id,
        ]);

        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);

        // Assign a mark as teacher1.
        $gradeobject->grader = $this->users[0]->id;
        $assignment->update_mark($gradeobject, 99);

        // Assign a mark as teacher2.
        $gradeobject->grader = $this->users[1]->id;
        $assignment->update_mark($gradeobject, 11);

        // With manual calculation, there should be no grade set yet.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, false);
        $this->assertEquals(-1, $gradeobject->grade);
    }

    /**
     * Test "maximum" calculation of final grade when using scale grading.
     *
     * @covers ::update_mark
     */
    public function test_calculated_marker_grade_maximum(): void {
        $this->setup_data();
        $assignment = $this->create_assignment([
            'multimarkmethod' => ASSIGN_MULTIMARKING_METHOD_MAX,
        ]);

        // Allocate both teachers to the student assignment.
        $assignment->update_allocated_markers($this->users[2]->id, [
            1 => $this->users[0]->id,
            2 => $this->users[1]->id,
        ]);

        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);

        // Assign a mark as teacher1.
        $gradeobject->grader = $this->users[0]->id;
        $assignment->update_mark($gradeobject, 11);

        // Assign a mark as teacher2.
        $gradeobject->grader = $this->users[1]->id;
        $assignment->update_mark($gradeobject, 99);

        // With max calculation, the grade should be the highest one.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, false);
        $this->assertEquals(99, $gradeobject->grade);
    }

    /**
     * Test "average" calculation of final grade when using rounding of "none".
     *
     * @covers ::update_mark
     */
    public function test_calculated_marker_grade_average_round_none(): void {
        $this->setup_data();
        $assignment = $this->create_assignment([
            'multimarkmethod' => ASSIGN_MULTIMARKING_METHOD_AVERAGE,
            'multimarkrounding' => ASSIGN_MULTIMARKING_AVERAGE_ROUND_NONE,
        ]);

        // Allocate both teachers to the student assignment.
        $assignment->update_allocated_markers($this->users[2]->id, [
            1 => $this->users[0]->id,
            2 => $this->users[1]->id,
        ]);

        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);

        // Assign a mark as teacher1.
        $gradeobject->grader = $this->users[0]->id;
        $assignment->update_mark($gradeobject, 90);

        // Assign a mark as teacher2.
        $gradeobject->grader = $this->users[1]->id;
        $assignment->update_mark($gradeobject, 25);

        // With avg calculation and no rounding, the grade should be 57.5.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, false);
        $this->assertEquals(57.5, $gradeobject->grade);
    }

    /**
     * Test "average" calculation of final grade when using rounding of "down".
     *
     * @covers ::update_mark
     */
    public function test_calculated_marker_grade_average_rounding_down(): void {
        $this->setup_data();
        $assignment = $this->create_assignment([
            'multimarkmethod' => ASSIGN_MULTIMARKING_METHOD_AVERAGE,
            'multimarkrounding' => ASSIGN_MULTIMARKING_AVERAGE_ROUND_DOWN,
        ]);

        // Allocate both teachers to the student assignment.
        $assignment->update_allocated_markers($this->users[2]->id, [
            1 => $this->users[0]->id,
            2 => $this->users[1]->id,
        ]);

        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);

        // Assign a mark as teacher1.
        $gradeobject->grader = $this->users[0]->id;
        $assignment->update_mark($gradeobject, 90);

        // Assign a mark as teacher2.
        $gradeobject->grader = $this->users[1]->id;
        $assignment->update_mark($gradeobject, 25);

        // With avg calculation and down rounding, the grade should be 57.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, false);
        $this->assertEquals(57, $gradeobject->grade);
    }

    /**
     * Test that the grade calculation from marks using method "average" with up rounding, sets the correct grade.
     *
     * @covers ::update_mark
     */
    public function test_calculated_marker_grade_average_round_up(): void {
        $this->setup_data();
        $assignment = $this->create_assignment([
            'multimarkmethod' => ASSIGN_MULTIMARKING_METHOD_AVERAGE,
            'multimarkrounding' => ASSIGN_MULTIMARKING_AVERAGE_ROUND_UP,
        ]);

        // Allocate both teachers to the student assignment.
        $assignment->update_allocated_markers($this->users[2]->id, [
            1 => $this->users[0]->id,
            2 => $this->users[1]->id,
        ]);

        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);

        // Assign a mark as teacher1.
        $gradeobject->grader = $this->users[0]->id;
        $assignment->update_mark($gradeobject, 90);

        // Assign a mark as teacher2.
        $gradeobject->grader = $this->users[1]->id;
        $assignment->update_mark($gradeobject, 25);

        // With avg calculation and up rounding, the grade should be 58.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, false);
        $this->assertEquals(58, $gradeobject->grade);
    }

    /**
     * Test that the grade calculation from marks using method "average" with natural rounding, sets the correct grade.
     *
     * @covers ::update_mark
     */
    public function test_calculated_marker_grade_average_round_natural(): void {
        $this->setup_data();
        $assignment = $this->create_assignment([
            'multimarkmethod' => ASSIGN_MULTIMARKING_METHOD_AVERAGE,
            'multimarkrounding' => ASSIGN_MULTIMARKING_AVERAGE_ROUND_NATURAL,
        ]);

        // Allocate both teachers to the student assignment.
        $assignment->update_allocated_markers($this->users[2]->id, [
            1 => $this->users[0]->id,
            2 => $this->users[1]->id,
        ]);

        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);

        // Assign a mark as teacher1.
        $gradeobject->grader = $this->users[0]->id;
        $assignment->update_mark($gradeobject, 90);

        // Assign a mark as teacher2.
        $gradeobject->grader = $this->users[1]->id;
        $assignment->update_mark($gradeobject, 25);

        // With avg calculation and natural rounding, the grade should be 58.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, false);
        $this->assertEquals(58, $gradeobject->grade);
    }

    /**
     * Test that the workflow state changes on the overall grade based on marker states.
     *
     * @covers ::update_mark, ::calculate_and_save_overall_workflow_state
     */
    public function test_calculated_marker_workflow(): void {
        $this->setup_data();
        $assignment = $this->create_assignment();

        // Allocate both teachers to the student assignment.
        $assignment->update_allocated_markers($this->users[2]->id, [
            1 => $this->users[0]->id,
            2 => $this->users[1]->id,
        ]);

        // First confirm that the overall grade workflow state is not set.
        $flags = $assignment->get_user_flags($this->users[2]->id, true);
        $this->assertEmpty($flags->workflowstate);

        // One marker then sets their mark to be in the state "In Marking".
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $gradeobject->grader = $this->users[0]->id;
        $assignment->update_mark($gradeobject, null, ASSIGN_MARKING_WORKFLOW_STATE_INMARKING);

        // Re-check the overall workflow. This should now be "In Marking" as well.
        $flags = $assignment->get_user_flags($this->users[2]->id, true);
        $this->assertEquals(ASSIGN_MARKING_WORKFLOW_STATE_INMARKING, $flags->workflowstate);

        // Now this teacher marks theirs as "Marking Complete".
        $gradeobject->grader = $this->users[0]->id;
        $assignment->update_mark($gradeobject, 90, ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW);

        // Nothing should change on the overall state, that should still be In Marking.
        $flags = $assignment->get_user_flags($this->users[2]->id, true);
        $this->assertEquals(ASSIGN_MARKING_WORKFLOW_STATE_INMARKING, $flags->workflowstate);

        // Now the second marker sets theirs as "Marking Complete".
        $gradeobject->grader = $this->users[1]->id;
        $assignment->update_mark($gradeobject, 70, ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW);

        // Now that both are complete, the overall state should be the same.
        $flags = $assignment->get_user_flags($this->users[2]->id, true);
        $this->assertEquals(ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW, $flags->workflowstate);

        // If the workflow state has been manually updated to a future state, updates should not override it.
        $flags->workflowstate = ASSIGN_MARKING_WORKFLOW_STATE_INREVIEW;
        $assignment->update_user_flags($flags);

        // Manually update the workflow state to 'In review'.
        $flags = $assignment->get_user_flags($this->users[2]->id, true);
        $this->assertEquals(ASSIGN_MARKING_WORKFLOW_STATE_INREVIEW, $flags->workflowstate);

        // Trigger calculation.
        $assignment->update_mark($gradeobject, 80, ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW);

        // The workflow state should not be updated.
        $flags = $assignment->get_user_flags($this->users[2]->id, true);
        $this->assertEquals(ASSIGN_MARKING_WORKFLOW_STATE_INREVIEW, $flags->workflowstate);
    }

    /**
     * Test that when we remove a marker their marks are not counted towards anything.
     *
     * @covers ::update_mark
     */
    public function test_unallocated_marker_not_included_in_mark_calculations(): void {
        $this->setup_data();
        $assignment = $this->create_assignment([
            'multimarkmethod' => ASSIGN_MULTIMARKING_METHOD_AVERAGE,
            'multimarkrounding' => ASSIGN_MULTIMARKING_AVERAGE_ROUND_NATURAL,
        ]);

        // Allocate both teachers to the student assignment.
        $assignment->update_allocated_markers($this->users[2]->id, [
            1 => $this->users[0]->id,
            2 => $this->users[1]->id,
        ]);

        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);

        // Assign a mark as teacher1.
        $gradeobject->grader = $this->users[0]->id;
        $assignment->update_mark($gradeobject, 90);

        // Now we remove teacher1 and add manager instead. So we have manager and teacher2 as the markers.
        $assignment->update_allocated_markers($this->users[2]->id, [1 => $this->users[3]->id, 2 => $this->users[1]->id]);

        // Now add a marker from teacher2.
        $gradeobject->grader = $this->users[1]->id;
        $assignment->update_mark($gradeobject, 10);

        // At this point, though we've had 2 marks, only 1 of the allocated markers has marked.
        // So the grade should not be set.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, false);
        $this->assertEquals(-1, $gradeobject->grade);
    }

    /**
     * Verify that calculated agreed marks are cleared when a mark can no longer be calculated.
     * This should only be triggered when markers are allocated or marks are assigned.
     *
     * @covers ::update_mark
     */
    public function test_marks_are_cleared_when_mark_can_no_longer_be_calculated(): void {
        $this->setup_data();
        $assignment = $this->create_assignment([
            'markercount' => 2,
            'optionalmarkercount' => 0,
            'multimarkmethod' => ASSIGN_MULTIMARKING_METHOD_AVERAGE,
            'multimarkrounding' => ASSIGN_MULTIMARKING_AVERAGE_ROUND_NONE,
        ]);

        $assignment->update_allocated_markers($this->users[2]->id, [
            1 => $this->users[0]->id,
            2 => $this->users[1]->id,
        ]);

        // Assign a mark as teacher1.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $gradeobject->grader = $this->users[0]->id;
        $assignment->update_mark($gradeobject, 90);

        // Assign a mark as teacher2.
        $gradeobject->grader = $this->users[1]->id;
        $assignment->update_mark($gradeobject, 10);

        // All graders have marked.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $this->assertEquals(50, $gradeobject->grade);

        // Unset a mark.
        $gradeobject->grader = $this->users[1]->id;
        $assignment->update_mark($gradeobject, null);

        // Grade should be cleared.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $this->assertEquals(-1, $gradeobject->grade);

        // Assign a mark as teacher2 again.
        $gradeobject->grader = $this->users[1]->id;
        $assignment->update_mark($gradeobject, 10);

        // All graders have marked.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $this->assertEquals(50, $gradeobject->grade);

        // Allocate a different marker who hasn't marked.
        // This requires elevated permissions and would rarely happen in practice.
        $assignment->update_allocated_markers($this->users[2]->id, [
            1 => $this->users[0]->id,
            2 => $this->users[3]->id,
        ]);

        // Grade should be cleared.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $this->assertEquals(-1, $gradeobject->grade);
    }

    /**
     * Verify that a final grade is not calculated when an enabled optional marker
     * has not completed marking.
     *
     * @covers ::update_mark
     */
    public function test_enabled_optional_markers_included_in_mark_calculations(): void {
        global $DB;

        $this->setup_data();
        $assignment = $this->create_assignment([
            'markercount' => 2,
            'optionalmarkercount' => 1,
            'multimarkmethod' => ASSIGN_MULTIMARKING_METHOD_AVERAGE,
            'multimarkrounding' => ASSIGN_MULTIMARKING_AVERAGE_ROUND_NONE,
        ]);

        // Slot 2 is optional and enabled.
        $assignment->update_allocated_markers($this->users[2]->id, [
            1 => $this->users[0]->id,
            2 => $this->users[1]->id,
        ], [2 => true]);

        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);

        // Assign a mark as teacher1.
        $gradeobject->grader = $this->users[0]->id;
        $assignment->update_mark($gradeobject, 90);

        // Ensure it is not graded when the enabled optional marker has not marked.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, false);
        $this->assertEquals(-1, $gradeobject->grade);

        // Assign a mark as teacher2.
        $gradeobject->grader = $this->users[1]->id;
        $assignment->update_mark($gradeobject, 10);

        // All required markers have now marked, so the grade should be set.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, false);
        $this->assertEquals(50, $gradeobject->grade);
    }

    /**
     * Verify that disabled optional markers are ignored during grade calculation.
     *
     * @covers ::update_mark
     */
    public function test_disabled_optional_markers_not_included_in_mark_calculations(): void {
        global $DB;

        $this->setup_data();
        $assignment = $this->create_assignment([
            'markercount' => 2,
            'optionalmarkercount' => 1,
            'multimarkmethod' => ASSIGN_MULTIMARKING_METHOD_AVERAGE,
            'multimarkrounding' => ASSIGN_MULTIMARKING_AVERAGE_ROUND_NONE,
        ]);

        // Teachers are allocated to both marking slots, but slot 2 is optional and not enabled.
        $assignment->update_allocated_markers($this->users[2]->id, [
            1 => $this->users[0]->id,
            2 => $this->users[1]->id,
        ]);

        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);

        // Assign a mark as teacher1.
        $gradeobject->grader = $this->users[0]->id;
        $assignment->update_mark($gradeobject, 90);

        // All required markers have marked, so we should have a grade.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, false);
        $this->assertEquals(90, $gradeobject->grade);

        // Assign a mark as teacher2.
        $gradeobject->grader = $this->users[1]->id;
        $assignment->update_mark($gradeobject, 10);

        // Ensure the disabled optional markers mark is ignored.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, false);
        $this->assertEquals(90, $gradeobject->grade);
    }

    /**
     * Increasing total markers does not break configuration rules
     * and updates derived minimum marker count correctly.
     *
     * @covers ::can_change_marker_count, ::update_instance
     */
    public function test_increasing_total_marker_count(): void {
        $this->setup_data();
        $assignment = $this->create_assignment([
            'markercount' => 2,
            'optionalmarkercount' => 0,
            'multimarkmethod' => ASSIGN_MULTIMARKING_METHOD_AVERAGE,
            'multimarkrounding' => ASSIGN_MULTIMARKING_AVERAGE_ROUND_NONE,
        ]);

        $assignment->update_allocated_markers($this->users[2]->id, [
            1 => $this->users[0]->id,
            2 => $this->users[1]->id,
        ]);

        $this->assertequals(2, $assignment->minimum_marker_count());

        // Assign a mark as teacher1.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $gradeobject->grader = $this->users[0]->id;
        $assignment->update_mark($gradeobject, 90);

        // Can increase when not all markers have marked.
        $this->assertTrue($assignment->can_change_marker_count(3));

        // Assign a mark as teacher2.
        $gradeobject->grader = $this->users[1]->id;
        $assignment->update_mark($gradeobject, 10);

        // All graders have marked.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $this->assertEquals(50, $gradeobject->grade);

        // Can increase when all markers have marked.
        $this->assertTrue($assignment->can_change_marker_count(3));

        // Update the assignment settings.
        $this->update_assignment_instance($assignment, ['markercount' => 3]);

        $this->assertequals(3, $assignment->minimum_marker_count());

        // An increase in required markers should not recalculate grades from marks.
        // These should only update when markers or enabled status changes.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $this->assertEquals(50, $gradeobject->grade);
    }

    /**
     * Decreasing total markers is blocked when removed slots contain marks.
     *
     * @covers ::can_change_marker_count, ::update_instance
     */
    public function test_decreasing_total_marker_count(): void {
        $this->setup_data();
        $assignment = $this->create_assignment([
            'markercount' => 3,
            'optionalmarkercount' => 0,
            'multimarkmethod' => ASSIGN_MULTIMARKING_METHOD_AVERAGE,
            'multimarkrounding' => ASSIGN_MULTIMARKING_AVERAGE_ROUND_NONE,
        ]);

        $assignment->update_allocated_markers($this->users[2]->id, [
            1 => $this->users[0]->id,
            2 => $this->users[1]->id,
        ]);

        // Assign a mark as teacher1.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $gradeobject->grader = $this->users[0]->id;
        $assignment->update_mark($gradeobject, 90);

        // Assign a mark as teacher2.
        $gradeobject->grader = $this->users[1]->id;
        $assignment->update_mark($gradeobject, 10);

        // Two out of three markers have marked, so grade not calculated yet.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $this->assertEquals(-1, $gradeobject->grade);

        // Can decrease when not all markers have marked.
        $this->assertTrue($assignment->can_change_marker_count(2));

        // Update the assignment settings.
        $this->update_assignment_instance($assignment, ['markercount' => 2]);

        $this->assertequals(2, $assignment->get_instance()->markercount);

        // A decrease in required markers should calculate grades that now meet the min requirements.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $this->assertEquals(50, $gradeobject->grade);

        // Now try and decrease again. Cannot decrease when markers have marked.
        $this->assertFalse($assignment->can_change_marker_count(1));
    }

    /**
     * Increasing optional markers is blocked when newly optional slots contain marks.
     *
     * @covers ::can_change_optional_marker_count, ::update_instance
     */
    public function test_increasing_optional_marker_count(): void {
        global $DB;

        $this->setup_data();
        $assignment = $this->create_assignment([
            'markercount' => 3,
            'optionalmarkercount' => 0,
            'multimarkmethod' => ASSIGN_MULTIMARKING_METHOD_AVERAGE,
            'multimarkrounding' => ASSIGN_MULTIMARKING_AVERAGE_ROUND_NONE,
        ]);

        $assignment->update_allocated_markers($this->users[2]->id, [
            1 => $this->users[0]->id,
            2 => $this->users[1]->id,
        ]);
        $this->assertequals(3, $assignment->get_instance()->markercount);
        $this->assertequals(3, $assignment->minimum_marker_count());

        // Assign a mark as teacher1.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $gradeobject->grader = $this->users[0]->id;
        $assignment->update_mark($gradeobject, 90);

        // Assign a mark as teacher2.
        $gradeobject->grader = $this->users[1]->id;
        $assignment->update_mark($gradeobject, 10);

        // The grade should not be calculated as not all markers have marked.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $this->assertEquals(-1, $gradeobject->grade);

        // Can increase when markers are not allocated.
        $this->assertTrue($assignment->can_change_optional_marker_count(3, 1));

        // Update the assignment settings.
        $this->update_assignment_instance($assignment, ['optionalmarkercount' => 1]);

        $this->assertequals(3, $assignment->get_instance()->markercount);
        $this->assertequals(2, $assignment->minimum_marker_count());

        // The third marker should be unchecked by default.
        $this->assertequals(2, $assignment->expected_marker_count($this->users[2]->id));

        // An increase in optional markers should calculate grades that now meet the requirements.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $this->assertEquals(50, $gradeobject->grade);

        // Cannot increase when markers have marked.
        $this->assertFalse($assignment->can_change_optional_marker_count(3, 2));

        // Remove the second mark for testing.
        $DB->delete_records('assign_mark', ['assignment' => $assignment->get_instance()->id, 'marker' => $this->users[1]->id]);

        // Can increase when markers are allocated but haven't marked.
        $this->assertTrue($assignment->can_change_optional_marker_count(3, 2));

        // Update the assignment settings.
        $this->update_assignment_instance($assignment, ['optionalmarkercount' => 2]);

        $this->assertequals(3, $assignment->get_instance()->markercount);
        $this->assertequals(1, $assignment->minimum_marker_count());

        // The second marker was allocated, so should be checked by default.
        $this->assertequals(2, $assignment->expected_marker_count($this->users[2]->id));

        // The marker requirements are no longer met, so the grade should be unchanged.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $this->assertEquals(50, $gradeobject->grade);
    }

    /**
     * Decreasing optional markers is always allowed,
     * even if previously optional slots contain marks.
     *
     * @covers ::can_change_optional_marker_count, ::update_instance
     */
    public function test_decreasing_optional_marker_count(): void {
        $this->setup_data();
        $assignment = $this->create_assignment([
            'markercount' => 3,
            'optionalmarkercount' => 2,
            'multimarkmethod' => ASSIGN_MULTIMARKING_METHOD_AVERAGE,
            'multimarkrounding' => ASSIGN_MULTIMARKING_AVERAGE_ROUND_NONE,
        ]);

        $assignment->update_allocated_markers($this->users[2]->id, [
            1 => $this->users[0]->id,
            2 => $this->users[1]->id,
        ], [2 => true]);

        $this->assertequals(1, $assignment->minimum_marker_count());
        $this->assertequals(2, $assignment->expected_marker_count($this->users[2]->id));

        // Assign a mark as teacher1.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $gradeobject->grader = $this->users[0]->id;
        $assignment->update_mark($gradeobject, 90);

        // Assign a mark as teacher2.
        $gradeobject->grader = $this->users[1]->id;
        $assignment->update_mark($gradeobject, 10);

        // All required markers have marked, so grade is calculated.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $this->assertEquals(50, $gradeobject->grade);

        // Can decrease when markers have marked.
        $this->assertTrue($assignment->can_change_optional_marker_count(3, 1));

        // Update the assignment settings.
        $this->update_assignment_instance($assignment, ['optionalmarkercount' => 1]);

        $this->assertequals(3, $assignment->get_instance()->markercount);
        $this->assertequals(2, $assignment->minimum_marker_count());
        $this->assertequals(2, $assignment->expected_marker_count($this->users[2]->id));

        // Grade should be unchanged.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $this->assertEquals(50, $gradeobject->grade);

        // Can decrease when markers haven't marked.
        $this->assertTrue($assignment->can_change_optional_marker_count(3, 0));

        // Update the assignment settings.
        $this->update_assignment_instance($assignment, ['optionalmarkercount' => 0]);

        $this->assertequals(3, $assignment->get_instance()->markercount);
        $this->assertequals(3, $assignment->minimum_marker_count());
        $this->assertequals(3, $assignment->expected_marker_count($this->users[2]->id));

        // Decreasing optional markers should clear calculated grades that no longer meet requirements.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $this->assertEquals(-1, $gradeobject->grade);
    }

    /**
     * Tests increasing optional markers while decreasing and increasing total marker count
     *
     * @covers ::update_instance
     */
    public function test_bidirectional_marker_count_change(): void {
        global $DB;

        $this->setup_data();
        $assignment = $this->create_assignment([
            'markercount' => 3,
            'optionalmarkercount' => 0,
            'multimarkmethod' => ASSIGN_MULTIMARKING_METHOD_AVERAGE,
            'multimarkrounding' => ASSIGN_MULTIMARKING_AVERAGE_ROUND_NONE,
        ]);

        // Allocate markers across all slots.
        $assignment->update_allocated_markers($this->users[2]->id, [
            1 => $this->users[0]->id,
            2 => $this->users[1]->id,
            3 => $this->users[3]->id,
        ]);

        // Initial assertions.
        $this->assertEquals(3, $assignment->minimum_marker_count());

        // Reduce marker count and increase optional markers at same time.
        $this->assertTrue($assignment->can_change_optional_marker_count(2, 1));
        $this->update_assignment_instance($assignment, [
            'markercount' => 2,
            'optionalmarkercount' => 1,
        ]);

        // Marker 2 should now be optional and enabled.
        $this->assertEquals(1, $assignment->minimum_marker_count());
        $this->assertEquals(2, $assignment->expected_marker_count($this->users[2]->id));

        // Increase marker count and increase optional markers at same time.
        $this->assertTrue($assignment->can_change_optional_marker_count(4, 2));
        $this->update_assignment_instance($assignment, [
            'markercount' => 4,
            'optionalmarkercount' => 2,
        ]);

        // Previous marker should not automatically reappear.
        $this->assertEquals(2, $assignment->minimum_marker_count());
        $this->assertEquals(2, $assignment->expected_marker_count($this->users[2]->id));

        // Enabled should be removed from marker 2 status.
        $allocated = $assignment->get_all_allocated_markers($this->users[2]->id);
        $this->assertNull($allocated[2]->enabled);
    }

    /**
     * Verify enabling multi-marking keeps existing grades when no marker marks exist.
     *
     * @covers ::update_instance
     */
    public function test_enabling_multimarking_keeps_existing_grades(): void {
        $this->setup_data();

        $assignment = $this->create_assignment([
            'markercount' => 1,
        ]);

        // Create a standard grade.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $gradeobject->grade = 75;
        $assignment->update_grade($gradeobject);

        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $this->assertEquals(75, $gradeobject->grade);

        // Enable multi-marking.
        $this->update_assignment_instance($assignment, [
            'markercount' => 2,
            'optionalmarkercount' => 0,
            'multimarkmethod' => ASSIGN_MULTIMARKING_METHOD_AVERAGE,
            'multimarkrounding' => ASSIGN_MULTIMARKING_AVERAGE_ROUND_NONE,
        ]);

        // Existing grade should be kept until allocated or marked.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $this->assertEquals(75, $gradeobject->grade);
    }

    /**
     * Verify that grades are recalculated when the marking method changes.
     *
     * @dataProvider changing_grade_calculation_method_provider
     * @param string $method
     * @param int|null $rounding
     * @param float $expectedgrade
     * @covers ::update_instance
     */
    public function test_changing_grade_calculation_method(string $method, ?int $rounding, float $expectedgrade): void {
        $this->setup_data();

        $assignment = $this->create_assignment([
            'markercount' => 2,
            'optionalmarkercount' => 0,
            'multimarkmethod' => ASSIGN_MULTIMARKING_METHOD_AVERAGE,
            'multimarkrounding' => ASSIGN_MULTIMARKING_AVERAGE_ROUND_NONE,
        ]);

        $assignment->update_allocated_markers($this->users[2]->id, [
            1 => $this->users[0]->id,
            2 => $this->users[1]->id,
        ]);

        // Assign a mark as teacher1.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $gradeobject->grader = $this->users[0]->id;
        $assignment->update_mark($gradeobject, 90);

        // Assign a mark as teacher2.
        $gradeobject->grader = $this->users[1]->id;
        $assignment->update_mark($gradeobject, 15);

        // All required markers have marked, so grade is calculated.
        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $this->assertEquals(52.5, $gradeobject->grade);

        // Manually update the grade to confirm no changes to the existing strategy.
        $gradeobject->grade = 55;
        $assignment->update_grade($gradeobject);

        // Update the assignment settings.
        $this->update_assignment_instance($assignment, [
            'multimarkmethod' => $method,
            'multimarkrounding' => $rounding,
        ]);

        $gradeobject = $assignment->get_user_grade($this->users[2]->id, true);
        $this->assertEquals($expectedgrade, $gradeobject->grade);
    }

    /**
     * Data provider for test_changing_grade_calculation_method.
     *
     * @return array[]
     */
    public static function changing_grade_calculation_method_provider(): array {
        return [
            'manual' => [
                ASSIGN_MULTIMARKING_METHOD_MANUAL,
                null,
                -1,
            ],
            'maximum' => [
                ASSIGN_MULTIMARKING_METHOD_MAX,
                null,
                90,
            ],
            'average_round_natural' => [
                ASSIGN_MULTIMARKING_METHOD_AVERAGE,
                ASSIGN_MULTIMARKING_AVERAGE_ROUND_NATURAL,
                53,
            ],
            'average_round_up' => [
                ASSIGN_MULTIMARKING_METHOD_AVERAGE,
                ASSIGN_MULTIMARKING_AVERAGE_ROUND_UP,
                53,
            ],
            'average_round_down' => [
                ASSIGN_MULTIMARKING_METHOD_AVERAGE,
                ASSIGN_MULTIMARKING_AVERAGE_ROUND_DOWN,
                52,
            ],
            // Grades should not be recalculated when the strategy doesn't change.
            'average' => [
                ASSIGN_MULTIMARKING_METHOD_AVERAGE,
                ASSIGN_MULTIMARKING_AVERAGE_ROUND_NONE,
                55,
            ],
        ];
    }
}
