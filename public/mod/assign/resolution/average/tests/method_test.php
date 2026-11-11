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

namespace assignresolution_average;

use assignresolution_average\method as average;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/tests/resolution/method_testcase.php');

/**
 * Tests for the average calculation method.
 *
 * @package    assignresolution_average
 * @category   test
 * @copyright  2026 Catalyst IT Australia Pty Ltd
 * @author     Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(method::class)]
final class method_test extends \mod_assign\resolution\method_testcase {
    /**
     * Calculations return the average of completed marks.
     *
     * @param array $values Completed mark values.
     * @param float|null $expected Expected grade.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('calculation_provider')]
    public function test_calculation(array $values, ?float $expected): void {
        $method = $this->setup_calculation_requirements(method::class, $values);
        $this->assertSame($expected, $method->calculate_grade());
        $this->assertSame($method->get_considered_markers(), $method->get_contributing_markers());
    }

    /**
     * Average calculation cases.
     *
     * @return array
     */
    public static function calculation_provider(): array {
        return [
            'empty' => [[], null],
            'zero' => [[0, 0], 0.0],
            'average' => [[90, 25], 57.5],
        ];
    }
}
