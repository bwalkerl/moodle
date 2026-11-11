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

/**
 * Rounding modes for assignment calculations.
 *
 * @package    mod_assign
 * @copyright  2026 Catalyst IT Australia Pty Ltd
 * @author     Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
enum rounding: int {
    case NONE = 0;
    case NATURAL = 1;
    case DOWN = 2;
    case UP = 3;

    /**
     * Get the rounding mode name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('multimarkrounding:' . strtolower($this->name), 'assign');
    }

    /**
     * Get the rounding mode description.
     *
     * @return string
     */
    public function get_description(): string {
        return get_string('multimarkrounding:' . strtolower($this->name) . '_help', 'assign');
    }

    /**
     * Apply this rounding mode to a calculated grade.
     *
     * @param float $value Unrounded grade.
     * @return float
     */
    public function apply(float $value): float {
        return match ($this) {
            self::NONE => $value,
            self::NATURAL => round($value),
            self::DOWN => floor($value),
            self::UP => ceil($value),
        };
    }
}
