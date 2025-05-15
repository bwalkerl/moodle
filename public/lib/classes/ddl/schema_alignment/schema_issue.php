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
 * Class responsible for schema alignment checks and fixes.
 *
 * @package    core
 * @copyright  2025 Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class schema_issue {
    /** @var string Resolving schema issue is safe */
    public const SAFE = 'safe';

    /** @var string Resolving schema issue depends on column data */
    public const RISKY = 'risky';

    /** @var string Resolving schema issue will cause data loss */
    public const UNSAFE = 'unsafe';

    /** @var string Resolving schema issue will drop an index */
    public const DBINDEX = 'dbindex';

    /** @var string Cannot resolve schema issue */
    public const UNFIXABLE = 'unfixable';

    /** @var array Safety levels of fixing schema issues */
    public const SAFETY_LEVEL = [
        self::SAFE      => 0,
        self::DBINDEX   => 1,
        self::RISKY     => 2,
        self::UNSAFE    => 3,
        self::UNFIXABLE => 4,
    ];

    /** @var string The severity of the error */
    public string $status;

    /** @var string Safety level of resolving the issue */
    public string $safety;

    /** @var int The number of issues */
    public int $count = 1;

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
        // Parent only handles shared properties.
    }

    /**
     * Gets the database manager.
     *
     * @return \database_manager
     */
    protected function get_dbman(): \database_manager {
        global $DB;
        return $DB->get_manager();
    }

    /**
     * Gets the error status of the issue.
     *
     * @return string
     */
    public function get_status(): string {
        return $this->status;
    }

    /**
     * Gets the safety level of fixing the issue.
     *
     * @return string
     */
    public function get_safety(): string {
        return $this->safety;
    }

    /**
     * Gets the error messages for the issue.
     *
     * @return array
     */
    public function get_messages(): array {
        return [$this->message];
    }

    /**
     * Attempts to resolve the schema alignment issue.
     *
     * @return bool True if the issue was resolved, false otherwise.
     */
    abstract public function resolve_issue(): bool;

    /**
     * Evaluates errors marked as 'risky' to determine if they are safe or unsafe.
     * This performs additional queries to check whether the column data fits the schema,
     * and updates the error safety flag.
     */
    public function evaluate_risky(): void {
        return;
    }

    /**
     * Drops indexes for the specified field in the provided table.
     *
     * @return array List of \xmldb_index that were dropped
     */
    protected function drop_column_indexes(): array {
        global $DB;

        if (!property_exists($this, 'field')) {
            return [];
        }

        $dbindexes = $DB->get_indexes($this->table->getName());
        $indexes = [];

        // Get all indexes for this column.
        foreach ($dbindexes as $indexname => $index) {
            $columns = $index['columns'];
            if (in_array($this->field->getName(), $columns)) {
                $type = !empty($index['unique']) ? XMLDB_INDEX_UNIQUE : XMLDB_INDEX_NOTUNIQUE;
                $indexes[] = new \xmldb_index($indexname, $type, $columns);
            }
        }

        // Drop indexes.
        foreach ($indexes as $index) {
            if ($this->get_dbman()->index_exists($this->table, $index)) {
                $this->print_cli("Dropping index {$index->getname()}");
                $this->get_dbman()->drop_index($this->table, $index);
            }
        }
        return $indexes;
    }

    /**
     * Restores indexes for the specified field in the provided table.
     *
     * @param array $indexes List of \xmldb_index to restore
     */
    protected function restore_column_indexes(array $indexes): void {
        foreach ($indexes as $index) {
            if (!$this->get_dbman()->index_exists($this->table, $index)) {
                $this->print_cli("Restoring index {$index->getname()}");
                $this->get_dbman()->add_index($this->table, $index);
            }
        }
    }

    /**
     * Prints output for CLI scripts
     *
     * @param string $message
     */
    protected function print_cli(string $message): void {
        if (CLI_SCRIPT && !PHPUNIT_TEST) {
            $tablename = $this->table->getName();
            $prefix = " * $tablename";
            if (property_exists($this, 'field')) {
                $prefix .= "->{$this->field->getName()}";
            }
            echo "$prefix: $message\n";
        }
    }
}
