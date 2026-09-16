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

namespace core_role\reportbuilder\local\filters;

use core_reportbuilder\local\filters\select;

/**
 * Risk type report filter.
 *
 * @package    core_role
 * @copyright  2026 Catalyst IT Australia Pty Ltd
 * @author     Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class risk_type extends select {
    /**
     * Return filter SQL.
     *
     * @param array $values
     * @return array
     */
    public function get_sql_filter(array $values): array {
        global $DB;

        $operator = (int) ($values["{$this->name}_operator"] ?? self::ANY_VALUE);
        $fieldsql = $this->filter->get_field_sql();
        $params = $this->filter->get_field_params();

        $selected = (string) ($values["{$this->name}_value"] ?? '');
        if ($operator === self::ANY_VALUE || $selected === '') {
            return ['', []];
        }

        $riskmasks = get_all_risks();
        if (!array_key_exists($selected, $riskmasks)) {
            return ['', []];
        }

        $mask = (int) $riskmasks[$selected];

        $masksql = (string) $mask;
        if ($operator === self::NOT_EQUAL_TO) {
            return ["(" . $DB->sql_bitand($fieldsql, $masksql) . ") = 0", $params];
        }

        return ["(" . $DB->sql_bitand($fieldsql, $masksql) . ") <> 0", $params];
    }
}
