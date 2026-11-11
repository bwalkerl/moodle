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

/**
 * Resolve the grade using the highest mark.
 *
 * @package    assignresolution_maximum
 * @copyright  2026 Catalyst IT Australia Pty Ltd
 * @author     Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class method extends \mod_assign\resolution\calculation_method {
    /**
     * {@inheritdoc}
     */
    public function calculate(): ?float {
        $marks = array_column($this->get_marks(), 'mark', 'marker');
        return $marks ? grade_floatval(max($marks)) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function get_contributing_markers(): array {
        $marks = array_column($this->get_marks(), 'mark', 'marker');
        if (!$marks) {
            return [];
        }
        $maximum = max($marks);
        return array_keys(array_filter($marks, fn($mark) => $mark == $maximum));
    }
}
