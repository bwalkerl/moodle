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

namespace core\ddl\schema_alignment;

/**
 * Class responsible for checking and fixing extra columns.
 *
 * @package    core
 * @copyright  2025 Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class extra_column extends schema_issue {
    /** @var string The reference key for this type of schema issue */
    public const KEY = 'extracolumns';

    /**
     * Constructor.
     *
     * @param string $message Description of the error.
     * @param \xmldb_table $table Table with schema issues.
     * @param \xmldb_field $field Column with schema issues.
     * @param \database_column_info $dbfield Database field containing extra information.
     */
    public function __construct(
        /** @var string Description of the error. */
        public string $message,
        /** @var \xmldb_table Table with schema issues. */
        public \xmldb_table $table,
        /** @var \xmldb_field Column with schema issues. */
        public \xmldb_field $field,
        /** @var \database_column_info Database field containing extra information. */
        public \database_column_info $dbfield,
    ) {
        $this->status = \core\check\result::INFO;
        $this->safety = self::UNSAFE;
    }

    /**
     * Attempts to remove the extra column from the schema.
     *
     * @return bool True if the issue was resolved, false otherwise.
     */
    public function resolve_issue(): bool {
        if ($this->get_dbman()->field_exists($this->table, $this->field)) {
            // There may be edge cases with manual foreign keys.
            $this->drop_column_indexes();
            $this->print_cli('Dropping extra column');
            $this->get_dbman()->drop_field($this->table, $this->field);
            return true;
        }
        return false;
    }
}
