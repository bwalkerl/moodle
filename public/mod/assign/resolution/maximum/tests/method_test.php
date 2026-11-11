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

namespace assignresolution_maximum;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/tests/resolution/method_testcase.php');

/**
 * Tests for the maximum calculation method.
 *
 * @package    assignresolution_maximum
 * @category   test
 * @copyright  2026 Catalyst IT Australia Pty Ltd
 * @author     Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(method::class)]
final class method_test extends \mod_assign\resolution\method_testcase {
    /**
     * Maximum calculations include all tied contributors and handle empty marks.
     *
     * @param array $values Completed mark values.
     * @param float|null $expected Expected grade.
     * @param array $contributors Contributing marker IDs.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('calculation_provider')]
    public function test_calculation(array $values, ?float $expected, array $contributors): void {
        $method = $this->setup_calculation_requirements(method::class, $values);
        $markerids = $method->get_considered_markers();
        $expectedcontributors = array_map(fn($position) => $markerids[$position], $contributors);
        $this->assertSame($expected, $method->calculate_grade());
        $this->assertSame($expectedcontributors, $method->get_contributing_markers());
    }

    /**
     * Maximum calculation cases.
     *
     * @return array
     */
    public static function calculation_provider(): array {
        return [
            'maximum' => [[90, 25], 90.0, [0]],
            'tied' => [[90, 25, '90.0'], 90.0, [0, 2]],
            'zero' => [[0, 0], 0.0, [0, 1]],
            'empty' => [[], null, []],
        ];
    }
}
