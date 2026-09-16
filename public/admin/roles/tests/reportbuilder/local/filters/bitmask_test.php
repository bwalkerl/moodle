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
use core_reportbuilder\local\report\filter;
use core\lang_string;

/**
 * Unit tests for bitmask filter
 *
 * @package    core_role
 * @covers     \core_role\reportbuilder\local\filters\bitmask
 * @copyright  2026 Catalyst IT Australia Pty Ltd
 * @author     Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class bitmask_test extends \advanced_testcase {
    /**
     * Data provider for {@see test_get_sql_filter_simple}
     *
     * @return array
     */
    public static function get_sql_filter_simple_provider(): array {
        return [
            [select::ANY_VALUE, null, true],
            [select::EQUAL_TO, RISK_XSS, true],
            [select::EQUAL_TO, RISK_PERSONAL, true],
            [select::EQUAL_TO, RISK_SPAM, true],
            [select::EQUAL_TO, RISK_CONFIG, false],
            [select::EQUAL_TO, RISK_DATALOSS, false],
            [select::EQUAL_TO, 0, false],
            [select::EQUAL_TO, 'invalid', true],
            [select::NOT_EQUAL_TO, RISK_XSS, false],
            [select::NOT_EQUAL_TO, RISK_PERSONAL, false],
            [select::NOT_EQUAL_TO, RISK_SPAM, false],
            [select::NOT_EQUAL_TO, RISK_CONFIG, true],
            [select::NOT_EQUAL_TO, RISK_DATALOSS, true],
            [select::NOT_EQUAL_TO, 0, true],
            [select::NOT_EQUAL_TO, 'invalid', true],
        ];
    }

    /**
     * Test risk type filter SQL
     *
     * @param int $operator
     * @param int|string|null $value
     * @param bool $expectmatch
     * @dataProvider get_sql_filter_simple_provider
     */
    public function test_get_sql_filter_simple(int $operator, int|string|null $value, bool $expectmatch): void {
        global $DB;

        $reportfilter = (new filter(
            bitmask::class,
            'riskbitmask',
            new lang_string('risks', 'core_role'),
            'testentity',
            'riskbitmask',
        ))
            ->set_options(array_flip(get_all_risks()));

        [$sql, $params] = bitmask::create($reportfilter)->get_sql_filter([
            $reportfilter->get_unique_identifier() . '_operator' => $operator,
            $reportfilter->get_unique_identifier() . '_value' => $value,
        ]);

        // Apply filter against the role assign capability as it has XSS, personal and spam risks.
        $select = 'name = :capability' . ($sql ? " AND $sql" : '');
        $params['capability'] = 'moodle/role:assign';

        // Confirm the capability matches the filter.
        $found = $DB->record_exists_select('capabilities', $select, $params);
        $this->assertEquals($expectmatch, $found);
    }
}
