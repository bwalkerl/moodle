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

namespace core_role\reportbuilder\local\entities;

use core\lang_string;
use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\{date, select, text};
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\{column, filter};
use core_role\reportbuilder\local\filters\risk_type;

/**
 * Role capability entity
 *
 * @package    core_role
 * @copyright  2026 Catalyst IT Australia Pty Ltd
 * @author     Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class role_capability extends base {
    /**
     * Database tables that this entity uses
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return [
            'role_capabilities',
            'capabilities',
        ];
    }

    /**
     * The default title for this entity
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('capabilities', 'core_role');
    }

    /**
     * Returns capabilities join used by columns
     *
     * @return string
     */
    private function get_capabilities_join(): string {
        $rcalias = $this->get_table_alias('role_capabilities');
        $capalias = $this->get_table_alias('capabilities');
        return "LEFT JOIN {capabilities} {$capalias} ON {$capalias}.name = {$rcalias}.capability";
    }

    /**
     * Returns list of all available columns
     *
     * @return column[]
     */
    protected function get_available_columns(): array {
        $rcalias = $this->get_table_alias('role_capabilities');
        $capalias = $this->get_table_alias('capabilities');
        $columns = [];

        // Capability name column.
        $columns[] = (new column(
            'name',
            new lang_string('capability', 'core_role'),
            $this->get_entity_name()
        ))
            ->add_field("{$rcalias}.capability");

        // Permission column.
        $columns[] = (new column(
            'permission',
            new lang_string('permission', 'core_role'),
            $this->get_entity_name()
        ))
            ->add_field("{$rcalias}.permission")
            ->add_callback(static function ($value): string {
                $intvalue = (int) ($value ?? 0);
                $permissions = [
                    CAP_ALLOW => get_string('allow', 'core_role'),
                    CAP_PREVENT => get_string('prevent', 'core_role'),
                    CAP_PROHIBIT => get_string('prohibit', 'core_role'),
                ];
                return $permissions[$intvalue] ?? '';
            });

        // Time modified column.
        $columns[] = (new column(
            'timemodified',
            new lang_string('timemodified', 'core_reportbuilder'),
            $this->get_entity_name()
        ))
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$rcalias}.timemodified")
            ->set_callback([format::class, 'userdate']);

        // Risk bitmask column.
        $columns[] = (new column(
            'risks',
            new lang_string('risks', 'core_role'),
            $this->get_entity_name()
        ))
            ->add_join($this->get_capabilities_join())
            ->add_field("{$capalias}.riskbitmask")
            ->add_attributes(['class' => 'text-nowrap'])
            ->set_is_sortable(false)
            ->add_callback(static function ($value): string {
                return self::format_risk_icons((int) ($value ?? 0));
            });

        return $columns;
    }

    /**
     * Return list of all available filters
     *
     * @return filter[]
     */
    protected function get_available_filters(): array {
        $rcalias = $this->get_table_alias('role_capabilities');
        $capalias = $this->get_table_alias('capabilities');
        $filters = [];

        // Filter by capability name.
        $filters[] = (new filter(
            text::class,
            'name',
            new lang_string('capability', 'core_role'),
            $this->get_entity_name(),
            "{$rcalias}.capability"
        ));

        // Filter by permission.
        $filters[] = (new filter(
            select::class,
            'permission',
            new lang_string('permission', 'core_role'),
            $this->get_entity_name(),
            "{$rcalias}.permission"
        ))
            ->set_options([
                CAP_ALLOW => get_string('allow', 'core_role'),
                CAP_PREVENT => get_string('prevent', 'core_role'),
                CAP_PROHIBIT => get_string('prohibit', 'core_role'),
            ]);

        // Filter by time modified.
        $filters[] = (new filter(
            date::class,
            'timemodified',
            new lang_string('timemodified', 'core_reportbuilder'),
            $this->get_entity_name(),
            "{$rcalias}.timemodified"
        ));

        // Filter by risks.
        $riskoptions = [];
        foreach (array_keys(get_all_risks()) as $riskname) {
            $riskoptions[$riskname] = get_string($riskname . 'short', 'admin');
        }

        $filters[] = (new filter(
            risk_type::class,
            'risks',
            new lang_string('risks', 'core_role'),
            $this->get_entity_name(),
            "{$capalias}.riskbitmask"
        ))
            ->set_options($riskoptions);

        return $filters;
    }

    /**
     * Formats risk bitmask as standard Moodle risk icons.
     *
     * @param int $riskbitmap
     * @param int $contextid
     * @param int $roleid
     * @return string
     */
    public static function format_risk_icons(int $riskbitmap, int $contextid = 0, int $roleid = 0): string {
        global $OUTPUT;

        if ($riskbitmap === 0) {
            return '';
        }

        $riskicons = '';
        foreach (get_all_risks() as $riskname => $riskvalue) {
            // Add empty spaces for risks that are not in the risk bitmap.
            if (!($riskbitmap & $riskvalue)) {
                $riskicons .= $OUTPUT->pix_icon('spacer', '', 'moodle');
                continue;
            }

            $shortname = get_string($riskname . 'short', 'admin');
            $icon = $OUTPUT->pix_icon(
                '/i/' . str_replace('risk', 'risk_', $riskname),
                $shortname,
                'moodle',
                ['title' => $shortname],
            );

            if ($contextid > 0 && $roleid > 0) {
                if ($contextid === SYSCONTEXTID) {
                    $riskurl = new \moodle_url('/admin/roles/define.php', [
                        'action' => 'view',
                        'roleid' => $roleid,
                        'risk' => $riskname,
                    ]);
                } else {
                    $riskurl = new \moodle_url('/admin/roles/override.php', [
                        'contextid' => $contextid,
                        'roleid' => $roleid,
                        'risk' => $riskname,
                    ]);
                }
                $riskicons .= \html_writer::link($riskurl, $icon, ['title' => $shortname]);
            } else {
                $riskicons .= $icon;
            }
        }

        return $riskicons;
    }
}
