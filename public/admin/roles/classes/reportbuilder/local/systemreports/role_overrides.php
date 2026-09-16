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
use core\lang_string;
use core\reportbuilder\local\entities\context;
use core_reportbuilder\local\entities\{course, user};
use core_reportbuilder\local\report\{action};
use core_reportbuilder\system_report;
use core_role\reportbuilder\local\entities\{role, role_capability};
use moodle_url;
use pix_icon;

/**
 * Role overrides system report.
 *
 * @package    core_role
 * @copyright  2026 Catalyst IT Australia Pty Ltd
 * @author     Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class role_overrides extends system_report {
    /**
     * Initialise report.
     */
    protected function initialise(): void {
        global $DB;

        // Set main table.
        $entityrc = new role_capability();
        $rcalias = $entityrc->get_table_alias('role_capabilities');
        $this->set_main_table('role_capabilities', $rcalias);
        $this->add_entity($entityrc);

        // Exclude system context capabilities as they aren't overrides.
        $this->add_base_condition_sql("{$rcalias}.contextid <> " . SYSCONTEXTID);

        // Add role entity.
        $entityrole = new role();
        $rolealias = $entityrole->get_table_alias('role');
        $this->add_entity($entityrole
            ->add_join("JOIN {role} {$rolealias} ON {$rolealias}.id = {$rcalias}.roleid"));

        // Add context entity.
        $entitycontext = new context();
        $contextalias = $entitycontext->get_table_alias('context');
        $this->add_entity($entitycontext
            ->add_join("JOIN {context} {$contextalias} ON {$contextalias}.id = {$rcalias}.contextid"));

        // Add user entity.
        $entityuser = new user();
        $useralias = $entityuser->get_table_alias('user');
        $this->add_entity($entityuser
            ->add_join("LEFT JOIN {user} {$useralias} ON {$useralias}.id = {$rcalias}.modifierid"));

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

        $this->add_base_fields("{$rcalias}.contextid, {$rcalias}.roleid");

        $this->add_columns();
        $this->add_filters();
        $this->add_actions();

        $this->set_initial_sort_column('role_capability:name', SORT_ASC);
        $this->set_downloadable(false);
    }

    /**
     * Add report columns.
     */
    protected function add_columns(): void {
        $this->add_columns_from_entities([
            'role:originalname',
            'role_capability:name',
            'role_capability:permission',
            'context:link',
            'role_capability:risks',
            'role_capability:timemodified',
            'user:fullnamewithlink',
        ]);

        if ($rolecolumn = $this->get_column('role:originalname')) {
            $rolecolumn
                ->set_title(new lang_string('role'));
        }

        if ($contextcolumn = $this->get_column('context:link')) {
            $contextcolumn
                ->set_title(new lang_string('context'));
        }

        if ($usercolumn = $this->get_column('user:fullnamewithlink')) {
            $usercolumn
                ->set_title(new lang_string('usermodified', 'core_reportbuilder'));
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
        )));
    }

    /**
     * Add report filters.
     */
    protected function add_filters(): void {
        $this->add_filters_from_entities([
            'role_capability:name',
            'role_capability:permission',
            'role_capability:risks',
            'context:level',
            'role:name',
            'role:archetype',
            'course:courseselector',
            'role_capability:timemodified',
        ]);
    }

    /**
     * Validate view access.
     *
     * @return bool
     */
    protected function can_view(): bool {
        return has_capability('moodle/role:manage', context_system::instance());
    }
}
