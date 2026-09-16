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

namespace core_role\reportbuilder\local\systemreports;

use context_system;
use core\{context, context_helper, lang_string};
use core\reportbuilder\local\entities\context as context_entity;
use core_reportbuilder\local\entities\course;
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\{action, column, filter};
use core_reportbuilder\system_report;
use core_role\reportbuilder\local\entities\{role, role_capability};
use help_icon;
use html_writer;
use moodle_url;
use pix_icon;
use stdClass;

/**
 * Role assignments system report.
 *
 * @package    core_role
 * @copyright  2026 Catalyst IT Australia Pty Ltd
 * @author     Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class role_assignments extends system_report {
    /**
     * Initialise report.
     */
    protected function initialise(): void {
        global $DB;

        $raalias = 'roleassignments';

        // Count distinct users in the main table for performance.
        $this->set_main_table_sql(
            "(SELECT roleid, contextid, COUNT(DISTINCT userid) AS assignments, MAX(timemodified) AS lastmodified
                FROM {role_assignments}
            GROUP BY roleid, contextid)",
            $raalias
        );

        $this->annotate_entity('role_assignments', new lang_string('roleassignments', 'role'));

        // Add role entity.
        $entityrole = new role();
        $rolealias = $entityrole->get_table_alias('role');
        $this->add_entity($entityrole
            ->add_join("JOIN {role} {$rolealias} ON {$rolealias}.id = {$raalias}.roleid"));

        // Add context entity.
        $entitycontext = new context_entity();
        $contextalias = $entitycontext->get_table_alias('context');
        $this->add_entity($entitycontext
            ->add_join("JOIN {context} {$contextalias} ON {$contextalias}.id = {$raalias}.contextid"));

        // Add course entity for filters.
        $entitycourse = new course();
        $coursealias = $entitycourse->get_table_alias('course');
        $coursecontextalias = $entitycourse->get_table_alias('context');
        $coursepathmatchsql = "({$contextalias}.path = {$coursecontextalias}.path OR " .
            "{$contextalias}.path LIKE " . $DB->sql_concat("{$coursecontextalias}.path", "'/%'") . ')';
        $this->add_entity($entitycourse
            ->add_join("LEFT JOIN {context} {$coursecontextalias}
                               ON {$coursecontextalias}.contextlevel = " . CONTEXT_COURSE . "
                              AND {$coursepathmatchsql}")
            ->add_join("LEFT JOIN {course} {$coursealias} ON {$coursealias}.id = {$coursecontextalias}.instanceid"));

        $this->add_base_fields("{$raalias}.contextid, {$raalias}.roleid");

        $this->add_columns();
        $this->add_filters();
        $this->add_actions();

        $this->set_initial_sort_column('role_assignments:assignments', SORT_DESC);
        $this->set_downloadable(false);
    }

    /**
     * Add report columns.
     */
    protected function add_columns(): void {
        $raalias = $this->get_main_table_alias();
        $contextalias = $this->get_entity('context')->get_table_alias('context');

        $this->add_columns_from_entities([
            'role:originalname',
            'context:link',
        ]);

        $this->add_column((new column(
            'assignments',
            new lang_string('roleassignments', 'role'),
            'role_assignments',
        ))
            ->set_type(column::TYPE_INTEGER)
            ->add_fields([
                "{$raalias}.assignments",
                "{$raalias}.roleid",
            ])
            ->add_fields(context_helper::get_preload_record_columns_sql($contextalias))
            ->set_help_icon(new help_icon('roleassignments', 'core_role'))
            ->add_callback(static function (int $assignmentcount, stdClass $row): string {
                if (empty($row->roleid) || !isset($row->ctxid)) {
                    return (string) $assignmentcount;
                }

                $contextid = (int) $row->ctxid;
                $roleid = (int) $row->roleid;
                $courseid = ($row->ctxlevel === CONTEXT_COURSE) ? ((int) $row->ctxinstance) : 0;

                if ($courseid && $roleid) {
                    $url = new moodle_url('/user/index.php', ['id' => $courseid, 'roleid' => $roleid]);
                } else if ($contextid && $roleid) {
                    $url = new moodle_url('/admin/roles/assign.php', ['contextid' => $contextid, 'roleid' => $roleid]);
                }

                return html_writer::link($url, (string) $assignmentcount);
            }));

        $this->add_column((new column(
            'risks',
            new lang_string('rolerisks', 'role'),
            'role_assignments',
        ))
            ->add_field("{$raalias}.roleid")
            ->add_fields(context_helper::get_preload_record_columns_sql($contextalias))
            ->add_attributes(['class' => 'text-nowrap'])
            ->set_is_sortable(false)
            ->set_help_icon(new help_icon('rolerisks', 'role'))
            ->add_callback(static function (?string $roleid, stdClass $row): string {
                if (empty($roleid) || !isset($row->ctxid)) {
                    return '';
                }

                $roleid = (int) $roleid;
                $contextid = (int) $row->ctxid;

                context_helper::preload_from_record(clone $row);
                $context = context::instance_by_id($row->ctxid);

                $riskbitmap = self::calculate_risk_bitmask($roleid, $context);
                return role_capability::format_risk_icons($riskbitmap, $contextid, $roleid);
            }));

        $this->add_column((new column(
            'timemodified',
            new lang_string('timemodified', 'core_reportbuilder'),
            'role_assignments',
        ))
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$raalias}.lastmodified")
            ->set_callback([format::class, 'userdate']));

        if ($rolecolumn = $this->get_column('role:originalname')) {
            $rolecolumn
                ->set_title(new lang_string('role'));
        }

        if ($contextcolumn = $this->get_column('context:link')) {
            $contextcolumn
                ->set_title(new lang_string('context'));
        }
    }

    /**
     * Add report actions.
     */
    protected function add_actions(): void {
        $this->add_action((new action(
            new moodle_url('/admin/roles/override.php', [
                'contextid' => ':contextid',
                'roleid' => ':roleid',
            ]),
            new pix_icon('t/edit', ''),
            [],
            false,
            new lang_string('editpermissions', 'role'),
        ))->add_callback(static function (stdClass $row): bool {
            return (int) $row->contextid !== SYSCONTEXTID;
        }));

        $this->add_action((new action(
            new moodle_url('/admin/roles/define.php', [
                'action' => 'view',
                'roleid' => ':roleid',
            ]),
            new pix_icon('t/edit', ''),
            [],
            false,
            new lang_string('editpermissions', 'role'),
        ))->add_callback(static function (stdClass $row): bool {
            return (int) $row->contextid === SYSCONTEXTID;
        }));
    }

    /**
     * Add report filters.
     */
    protected function add_filters(): void {
        $this->add_filters_from_entities([
            'role:name',
            'role:archetype',
            'context:level',
            'course:courseselector',
        ]);

        // Filter by last modified.
        $this->add_filter((new filter(
            date::class,
            'timemodified',
            new lang_string('timemodified', 'core_reportbuilder'),
            'role_assignments',
            $this->get_main_table_alias() . '.lastmodified',
        )));
    }

    /**
     * Validate view access.
     *
     * @return bool
     */
    protected function can_view(): bool {
        return has_capability('moodle/role:manage', context_system::instance());
    }

    /**
     * Calculate the effective risk bitmask for a role assignment in the given context.
     *
     * @param int $roleid
     * @param context $context
     * @return int risk bit mask
     */
    public static function calculate_risk_bitmask(int $roleid, context $context): int {
        $contextcapabilities = [];
        foreach ($context->get_capabilities() as $capabilityrecord) {
            $contextcapabilities[$capabilityrecord->name] = true;
        }

        static $capabilityinfo = [];
        $riskbitmask = 0;
        foreach (role_context_capabilities($roleid, $context) as $capability => $permission) {
            if (!isset($contextcapabilities[$capability])) {
                continue;
            }

            if ((int) $permission !== CAP_ALLOW) {
                continue;
            }

            if (!isset($capabilityinfo[$capability])) {
                $capabilityinfo[$capability] = get_capability_info($capability);
            }
            $capability = $capabilityinfo[$capability];
            if (!$capability || !$capability->riskbitmask) {
                continue;
            }

            $riskbitmask |= (int) $capability->riskbitmask;
        }

        return $riskbitmask;
    }
}
