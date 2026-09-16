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

declare(strict_types=1);

namespace core_role\reportbuilder\local\systemreports;

use context_course;
use context_system;

/**
 * Unit tests for role assignments system report calculate_risk_bitmask
 *
 * @package    core_role
 * @covers     \core_role\reportbuilder\local\systemreports\role_assignments
 * @copyright  2026 Catalyst IT Australia Pty Ltd
 * @author     Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class role_assignments_test extends \advanced_testcase {
    /**
     * Test calculate_risk_bitmask with various permission states
     * @covers ::calculate_risk_bitmask
     */
    public function test_calculate_risk_bitmask(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $context = context_course::instance($course->id);
        $roleid = $this->getDataGenerator()->create_role();

        // No capabilities assigned - should be zero risk.
        $riskbitmask = role_assignments::calculate_risk_bitmask($roleid, $context);
        $this->assertEquals(0, $riskbitmask);

        // Find two capabilities with different risks.
        $cap1 = get_capability_info('moodle/course:update');
        $cap2 = get_capability_info('moodle/course:delete');

        // Allow first capability - should only have that risk.
        role_change_permission($roleid, $context, 'moodle/course:update', CAP_ALLOW);
        $riskbitmask = role_assignments::calculate_risk_bitmask($roleid, $context);
        $this->assertSame((int) $cap1->riskbitmask, $riskbitmask);

        // Allow second capability with different risk - should combine risks.
        role_change_permission($roleid, $context, 'moodle/course:delete', CAP_ALLOW);
        $riskbitmask = role_assignments::calculate_risk_bitmask($roleid, $context);
        $this->assertEquals((int) ($cap1->riskbitmask | $cap2->riskbitmask), $riskbitmask);

        // Prevent first capability - should remove only that risk.
        role_change_permission($roleid, $context, 'moodle/course:update', CAP_PREVENT);
        $riskbitmask = role_assignments::calculate_risk_bitmask($roleid, $context);
        $this->assertEquals((int) $cap2->riskbitmask, $riskbitmask);

        // Prohibit second capability - should result in zero risk.
        role_change_permission($roleid, $context, 'moodle/course:delete', CAP_PROHIBIT);
        $riskbitmask = role_assignments::calculate_risk_bitmask($roleid, $context);
        $this->assertEquals(0, $riskbitmask);
    }

    /**
     * Test calculate_risk_bitmask with inheritance
     * @covers ::calculate_risk_bitmask
     */
    public function test_calculate_risk_bitmask_inheritance(): void {
        $this->resetAfterTest();

        $systemctx = context_system::instance();
        $course = $this->getDataGenerator()->create_course();
        $coursectx = context_course::instance($course->id);

        $roleid = $this->getDataGenerator()->create_role();

        // Use a course-level capability that has risk.
        $cap = get_capability_info('moodle/course:update');

        // Parent has allow, child inherits it.
        role_change_permission($roleid, $systemctx, 'moodle/course:update', CAP_ALLOW);
        $riskbitmask = role_assignments::calculate_risk_bitmask($roleid, $coursectx);
        $this->assertEquals((int) $cap->riskbitmask, $riskbitmask);

        // Child has allow, parent has prevent.
        role_change_permission($roleid, $systemctx, 'moodle/course:update', CAP_PREVENT);
        role_change_permission($roleid, $coursectx, 'moodle/course:update', CAP_ALLOW);
        $riskbitmask = role_assignments::calculate_risk_bitmask($roleid, $coursectx);
        $this->assertEquals((int) $cap->riskbitmask, $riskbitmask);

        // Child has allow, parent has prohibit.
        role_change_permission($roleid, $systemctx, 'moodle/course:update', CAP_PROHIBIT);
        $riskbitmask = role_assignments::calculate_risk_bitmask($roleid, $coursectx);
        $this->assertEquals(0, $riskbitmask);

        // Bitmask should only inherit risks that are applicable to the current context.
        role_change_permission($roleid, $systemctx, 'moodle/site:config', CAP_ALLOW);
        $riskbitmask = role_assignments::calculate_risk_bitmask($roleid, $coursectx);
        $this->assertEquals(0, $riskbitmask);
    }
}
