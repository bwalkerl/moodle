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
 * Class responsible for checking and fixing missing indexes.
 *
 * @package    core
 * @copyright  2025 Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class missing_index extends schema_issue {
    /** @var string The reference key for this type of schema issue */
    public const KEY = 'missingindexes';

    /**
     * Constructor.
     *
     * @param string $message Description of the error.
     * @param \xmldb_table $table Table with schema issues.
     * @param \xmldb_index $index Index with schema issues.
     */
    public function __construct(
        /** @var string Description of the error. */
        public string $message,
        /** @var \xmldb_table Table with schema issues. */
        public \xmldb_table $table,
        /** @var \xmldb_index Index with schema issues. */
        public \xmldb_index $index,
    ) {
        $this->status = \core\check\result::WARNING;
        $this->safety = self::SAFE;
    }

    /**
     * Attempts to create the missing index from the schema.
     *
     * @return bool True if the issue was resolved, false otherwise.
     */
    public function resolve_issue(): bool {
        if (!$this->get_dbman()->index_exists($this->table, $this->index)) {
            $name = $this->index->getName();
            if ($this->all_index_columns_exist()) {
                $this->print_cli("Creating missing index $name");
                $this->get_dbman()->add_index($this->table, $this->index);
                return true;
            } else {
                $this->print_cli("Cannot create missing index $name as columns are missing");
            }
        }
        return false;
    }

    /**
     * Checks whether all columns for this index already exist
     *
     * @return bool Whether all columns for this index exist
     */
    protected function all_index_columns_exist(): bool {
        foreach ($this->index->getFields() as $field) {
            if (!$this->get_dbman()->field_exists($this->table, $field)) {
                return false;
            }
        }
        return true;
    }
}
