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
 * Class responsible for checking and fixing missing tables.
 *
 * @package    core
 * @copyright  2025 Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class missing_table extends schema_issue {
    /** @var string The reference key for this type of schema issue */
    public const KEY = 'missingtables';

    /**
     * Constructor.
     *
     * @param string $message Description of the error.
     * @param \xmldb_table $table Table with schema issues.
     */
    public function __construct(
        /** @var string Description of the error. */
        public string $message,
        /** @var \xmldb_table Table with schema issues. */
        public \xmldb_table $table,
    ) {
        $this->status = \core\check\result::ERROR;
        $this->safety = self::SAFE;
    }

    /**
     * Attempts to create the missing table from the schema.
     *
     * @return bool True if the issue was resolved, false otherwise.
     */
    public function resolve_issue(): bool {
        if (!$this->get_dbman()->table_exists($this->table)) {
            $this->print_cli('Creating missing table');
            $this->get_dbman()->create_table($this->table);
            return true;
        }
        return false;
    }
}
