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

/**
 * Tests for assignment grade resolution rounding modes.
 *
 * @package    mod_assign
 * @category   test
 * @copyright  2026 Catalyst IT Australia Pty Ltd
 * @author     Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(rounding::class)]
final class rounding_test extends \advanced_testcase {
    /**
     * Rounding modes expose stable values and language strings.
     */
    public function test_rounding_metadata(): void {
        $cases = rounding::cases();
        $this->assertSame([0, 1, 2, 3], array_map(fn(rounding $mode) => $mode->value, $cases));
        foreach ([0 => 'none', 1 => 'natural', 2 => 'down', 3 => 'up'] as $mode => $name) {
            $case = rounding::from($mode);
            $this->assertSame(get_string('multimarkrounding:' . $name, 'assign'), $case->get_name());
            $this->assertSame(get_string('multimarkrounding:' . $name . '_help', 'assign'), $case->get_description());
        }
        $this->assertNull(rounding::tryFrom(-1));
        $this->assertNull(rounding::tryFrom(4));
    }

    /**
     * Apply each rounding mode to positive and negative values.
     *
     * @param rounding $mode Rounding mode.
     * @param float $value Input value.
     * @param float $expected Expected rounded value.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rounding_mode_provider')]
    public function test_apply_rounding(rounding $mode, float $value, float $expected): void {
        $this->assertSame($expected, $mode->apply($value));
    }

    /**
     * Rounding mode application cases.
     *
     * @return array
     */
    public static function rounding_mode_provider(): array {
        return [
            'none positive' => [rounding::NONE, 57.5, 57.5],
            'natural positive' => [rounding::NATURAL, 57.5, 58.0],
            'down positive' => [rounding::DOWN, 57.5, 57.0],
            'up positive' => [rounding::UP, 57.5, 58.0],
            'none negative' => [rounding::NONE, -57.5, -57.5],
            'natural negative' => [rounding::NATURAL, -57.5, -58.0],
            'down negative' => [rounding::DOWN, -57.5, -58.0],
            'up negative' => [rounding::UP, -57.5, -57.0],
        ];
    }
}
