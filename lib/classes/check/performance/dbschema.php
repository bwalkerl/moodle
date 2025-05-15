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

/**
 * DB schema performance check
 *
 * @package    core
 * @category   check
 * @copyright  2021 Brendan Heywood <brendan@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace core\check\performance;

defined('MOODLE_INTERNAL') || die();

use core\check\check;
use core\check\result;

/**
 * DB schema performance check
 *
 * @copyright  2021 Brendan Heywood <brendan@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dbschema extends check {

    /**
     * Get the short check name
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('check_dbschema_name', 'report_performance');
    }

    /**
     * A link to a place to action this
     *
     * @return \action_link|null
     */
    public function get_action_link(): ?\action_link {
        return new \action_link(
            new \moodle_url(\get_docs_url('Verify_Database_Schema')),
            get_string('moodledocs'));
    }

    /**
     * Return result
     * @return result
     */
    public function get_result(): result {
        global $DB, $PAGE;

        $dbmanager = $DB->get_manager();
        $schema = $dbmanager->get_install_xml_schema();

        if (!$errors = $dbmanager->check_database_schema($schema, null, true)) {
            return new result(result::OK, get_string('check_dbschema_ok', 'report_performance'), '');
        }

        $checkrisky = optional_param('checkrisky', null, PARAM_BOOL);
        if (isset($checkrisky)) {
            $dbmanager->evaluate_risky_errors($errors);
        }

        ksort($errors);
        $status = $this->get_status($errors);
        $details = $this->get_table($errors);

        $risky = $dbmanager->filter_errors($errors, '', [\database_manager::RISKY]);
        if (!empty($risky)) {
            $details .= \html_writer::link(
                new \moodle_url($PAGE->url, ['detail' => 'core_dbschema', 'checkrisky' => true]),
                get_string('check_dbschema_risky', 'report_performance'),
                ['class' => 'btn btn-primary mt-2']
            );
        }

        return new result($status, get_string('check_dbschema_errors', 'report_performance'), $details);
    }

    /**
     * Gets the highest status found in the database schema errors
     *
     * @param array $errors Database schema errors
     * @return string Higest status
     */
    public function get_status(array $errors): string {
        $statuses = [];
        foreach ($errors as $details) {
            foreach ($details as $error) {
                $statuses[] = $error->status;
            }
        }
        return result::get_highest_status($statuses) ?? result::ERROR;
    }

    /**
     * Renders a html table of database schema errors
     *
     * @param array $errors Database schema errors
     * @return string html table
     */
    public function get_table(array $errors): string {
        global $OUTPUT;

        $table = new \html_table();
        $table->data = [];
        $table->head = [
            get_string('table', 'tool_xmldb'),
            get_string('status'),
            get_string('issue', 'report_performance'),
            get_string('update'),
            get_string('summary'),
        ];
        $table->id = 'dbschema_table';
        $table->attributes = ['class' => 'admintable generaltable table-sm'];

        foreach ($errors as $tablename => $details) {
            $name = new \html_table_cell($tablename);
            $name->rowspan = count($details);
            $name->attributes['class'] = 'font-weight-bold';

            foreach ($details as $index => $error) {
                $row = [];
                if ($index === 0) {
                    $row[] = $name;
                }

                $result = new \html_table_cell($OUTPUT->check_result(new result($error->status, '', '')));
                $result->attributes['class'] = 'status';
                $row[] = $result;

                $row[] = $error->type;
                $row[] = $error->safety;
                $row[] = $error->desc;

                $table->data[] = $row;
            }
        }

        return \html_writer::table($table);
    }
}

