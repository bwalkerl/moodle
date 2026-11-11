<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_assign\resolution;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/lib/accesslib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');

/**
 * Shared helpers for assignment resolution tests.
 *
 * @package    mod_assign
 * @category   test
 * @copyright  2026 Catalyst IT Australia Pty Ltd
 * @author     Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait helper {
    use \mod_assign_test_generator;

    /** @var array Generated users. */
    protected array $users = [];

    /** @var \assign Assignment for the current resolution fixture. */
    protected \assign $assignment;

    /** @var \stdClass Stored grade for the current resolution fixture. */
    protected \stdClass $grade;

    /**
     * Set up resolution requirements for a calculation method.
     *
     * @param string $classname Calculation method class.
     * @param array $markvalues Completed mark values.
     * @param array $params Assignment settings.
     * @return calculation_method Calculation method.
     */
    protected function setup_calculation_requirements(
        string $classname,
        array $markvalues = [],
        array $params = [],
    ): calculation_method {
        $params['multimarkmethod'] = $classname::get_basename();
        $method = $this->setup_resolution_requirements($markvalues, $params);
        if (!$method instanceof calculation_method) {
            throw new \coding_exception('Expected a calculation method.');
        }
        return $method;
    }

    /**
     * Set up an assignment with the requested marks and resolution method.
     *
     * @param array $markvalues Completed mark values.
     * @param array $params Assignment settings.
     * @return method Resolution method for the configured requirements.
     */
    protected function setup_resolution_requirements(array $markvalues = [], array $params = []): method {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->users[2] = $student;
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->assignment = $this->create_instance($course, $params + [
            'grade' => 100,
            'markingworkflow' => 1,
            'markingallocation' => 1,
            'markercount' => max(2, count($markvalues)),
            'optionalmarkercount' => 0,
        ]);
        $allocations = [];
        foreach ($markvalues as $value) {
            $marker = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($marker->id, $course->id, 'editingteacher');
            $allocations[count($allocations) + 1] = [$marker->id];
        }
        if ($allocations) {
            $this->assignment->update_marker_allocations($student->id, $allocations);
        }
        $grade = $this->assignment->get_user_grade($student->id, true);
        foreach (array_values($markvalues) as $position => $value) {
            $grade->grader = $allocations[$position + 1][0];
            $this->assignment->update_mark($grade, $value, ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW);
        }
        $this->grade = $grade;
        return $this->assignment->get_resolution_method($this->grade);
    }

    /**
     * Get marker IDs with persisted marks for the current fixture grade.
     *
     * @return int[] Marker user IDs.
     */
    protected function get_marker_ids(): array {
        return array_keys($this->assignment->get_mark_records($this->grade->id, $this->grade->userid));
    }

    /**
     * Get the current saved grade for the fixture user.
     *
     * @return \stdClass Current grade.
     */
    protected function get_current_grade(): \stdClass {
        return $this->assignment->get_user_grade($this->grade->userid, false);
    }

    /**
     * Set a saved manual grade for the fixture user.
     *
     * @param float|null $value Grade value.
     */
    protected function set_manual_grade(?float $value): void {
        $this->grade->grade = $value;
        $this->assertTrue($this->assignment->update_grade(clone $this->grade));
    }

    /**
     * Unallocate the current fixture's markers through the assignment API.
     */
    protected function unallocate_markers(): void {
        $flags = $this->assignment->get_user_flags($this->grade->userid, true);
        $flags->workflowstate = ASSIGN_MARKING_WORKFLOW_STATE_INMARKING;
        $this->assertTrue($this->assignment->update_user_flags($flags));
        $allocations = $this->assignment->get_marker_allocations($this->grade->userid);
        $this->assignment->update_marker_allocations(
            $this->grade->userid,
            array_fill_keys(array_keys($allocations), [0]),
        );
    }
}
