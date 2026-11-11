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

namespace assignresolution_manual;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/tests/resolution/method_testcase.php');

/**
 * Tests for manual agreement method.
 *
 * @package    assignresolution_manual
 * @category   test
 * @copyright  2026 Catalyst IT Australia Pty Ltd
 * @author     Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(method::class)]
final class method_test extends \mod_assign\resolution\method_testcase {
    /**
     * Manual resolution has no calculation method.
     */
    public function test_calculation(): void {
        $resolution = $this->setup_resolution_requirements([40, 80], ['multimarkmethod' => method::get_basename()]);
        $this->assertNull($resolution->get_calculation_method());
        $this->assertFalse($resolution->resolve_grade(false));
    }
}
