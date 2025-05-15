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
 * Class responsible for checking and fixing changed columns.
 *
 * @package    core
 * @copyright  2025 Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class changed_column extends schema_issue {
    /** @var string The reference key for this type of schema issue */
    public const KEY = 'changedcolumns';

    /** @var string Issue with column type */
    public const ISSUE_TYPE = 'type';

    /** @var string Issue with column null setting */
    public const ISSUE_NULL = 'null';

    /** @var string Issue with column length */
    public const ISSUE_LENGTH = 'length';

    /** @var string Issue with column dbtype */
    public const ISSUE_DBTYPE = 'dbtype';

    /** @var string Issue with column default */
    public const ISSUE_DEFAULT = 'default';

    /** @var array Types mapping */
    public const TYPES_MAP = [
        'I' => XMLDB_TYPE_INTEGER,
        'R' => XMLDB_TYPE_INTEGER,
        'N' => XMLDB_TYPE_NUMBER,
        'F' => XMLDB_TYPE_NUMBER, // Nobody should be using floats!
        'C' => XMLDB_TYPE_CHAR,
        'X' => XMLDB_TYPE_TEXT,
        'B' => XMLDB_TYPE_BINARY,
        'T' => XMLDB_TYPE_TIMESTAMP,
        'D' => XMLDB_TYPE_DATETIME,
    ];

    /** @var array All issues for this column */
    public array $issues;

    /** @var array All error messages for this column */
    public array $messages;

    /** @var array Data fixes that are required before the column can be aligned */
    public array $fixes;

    /**
     * Constructor.
     *
     * @param string $message Description of the error.
     * @param \xmldb_table $table Table with schema issues.
     * @param \xmldb_field $field Column with schema issues.
     * @param \database_column_info $dbfield Database field containing extra information.
     * @param string $issue The type of changed column issue.
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
        string $issue,
    ) {
        $this->status = \core\check\result::WARNING;
        $this->safety = $this->get_fix_safety($issue);
        $this->issues[] = $issue;
        $this->messages[] = $message;
    }

    /**
     * Gets the safety level of fixing the schema issue.
     *
     * @param string $issue The type of changed column issue.
     * @return string The safety level of fixing the issue.
     */
    protected function get_fix_safety(string $issue): string {
        return match ($issue) {
            self::ISSUE_TYPE => $this->field->getType() === XMLDB_TYPE_TEXT ? self::SAFE : self::RISKY,
            self::ISSUE_NULL => $this->field->getNotNull() ? self::RISKY : self::SAFE,
            self::ISSUE_LENGTH => $this->get_length_safety(),
            self::ISSUE_DBTYPE => self::RISKY,
            self::ISSUE_DEFAULT => self::SAFE,
        };
    }

    /**
     * Gets the safety level of fixing the length of the column.
     *
     * @return string The safety level of fixing the issue.
     */
    protected function get_length_safety(): string {
        // Check the meta type in case there are differences.
        switch (self::TYPES_MAP[$this->dbfield->meta_type]) {
            case XMLDB_TYPE_CHAR:
                return $this->field->getLength() > $this->dbfield->max_length ? self::SAFE : self::RISKY;
            case XMLDB_TYPE_NUMBER:
                if ($this->field->getDecimals() < $this->dbfield->scale) {
                    return self::UNSAFE;
                }
                if ($this->field->getLength() < $this->dbfield->max_length || $this->field->getDecimals() > $this->dbfield->scale) {
                    return self::RISKY;
                }
                return self::SAFE;
            default:
                return self::SAFE;
        }
    }

    /**
     * Combines two changed columns issues together.
     *
     * The core functions used to fix schema alignment issues for changed columns
     * fix multiple issues at once, so these need to be handled together.
     *
     * @param changed_column $issue
     * @return bool Whether the issues were combined
     */
    public function combine_issue(changed_column $issue): bool {
        if ($this->table != $issue->table || $this->field != $issue->field) {
            $this->print_cli("Cannot combine different columns");
            return false;
        }

        $this->issues = array_merge($this->issues, $issue->issues);
        $this->messages[] = $issue->message;
        $this->count += 1;

        // Keep the highest safety level.
        if (schema_issue::SAFETY_LEVEL[$issue->safety] > schema_issue::SAFETY_LEVEL[$this->safety]) {
            $this->safety = $issue->safety;
        }
        return true;
    }

    /**
     * Gets the error messages for the issue
     *
     * @return array
     */
    public function get_messages(): array {
        return $this->messages;
    }

    /**
     * Evaluates errors marked as 'risky' to determine if they are safe or unsafe.
     * This performs additional queries to check whether the column data fits the schema,
     * and updates the error safety flag.
     */
    public function evaluate_risky(): void {
        if ($this->safety !== self::RISKY) {
            return;
        }

        $this->check_column_data_issues();
        if ($this->safety === self::SAFE) {
            $this->print_cli("Column is safe to fix");
        }
    }

    /**
     * Evalutes the safety level of of aligning a column definition to resolve schema errors.
     * This checks the column data for errors marked as "risky" and updates the safety level
     * to "safe", "unsafe" or "unfixable".
     */
    protected function check_column_data_issues(): void {
        // We only need to check issues marked as risky.
        if ($this->safety !== self::RISKY) {
            return;
        }

        // Start by assuming safe and raise level as we check.
        $this->safety = self::SAFE;

        if (in_array(self::ISSUE_NULL, $this->issues)) {
            if ($this->check_null_safety() === self::UNFIXABLE) {
                return;
            }
        }

        if (in_array(self::ISSUE_TYPE, $this->issues)) {
            if ($this->check_type_safety() === self::UNFIXABLE) {
                return;
            }
        }

        // Length check is also required for type changes, so check it for all errors.
        $this->check_length_safety();
    }

    /**
     * Evalutes the safety level of making a column not null
     */
    protected function check_null_safety(): void {
        global $DB;

        if ($this->field->getNotNull() && !$this->dbfield->not_null) {
            if ($DB->record_exists_select($this->table->getName(), "{$this->field->getName()} IS NULL")) {
                // Unsafe as we have null values.
                $this->print_cli("Column is unsafe as it requires nulls to be updated to the default");
                $this->safety = self::UNSAFE;
                $this->fixes[] = self::ISSUE_NULL;
            }
        }
    }

    /**
     * Evalutes the safety level of changing the type of a column
     */
    protected function check_type_safety(): void {
        global $DB;

        $columnname = $this->field->getName();
        $type = $this->field->getType();
        if ($type == XMLDB_TYPE_FLOAT) {
            $type = XMLDB_TYPE_NUMBER;
        }

        // Check for type changes. We can ignore checking TEXT and CHAR as they only depend on length.
        if ($type != XMLDB_TYPE_TEXT && $type != XMLDB_TYPE_CHAR) {
            if ($type == XMLDB_TYPE_INTEGER) {
                // Have to manually check whether all values are integers.
                $rs = $DB->get_recordset($this->table->getName(), null, '', $columnname);
                foreach ($rs as $record) {
                    if (filter_var($record->$columnname, FILTER_VALIDATE_INT) === false) {
                        $this->print_cli("Column is unfixable as it contains values that are not integers");
                        $this->safety = self::UNFIXABLE;
                        break;
                    }
                }
                $rs->close();
            } else if ($type == XMLDB_TYPE_NUMBER) {
                // Have to manually check whether all values are floats.
                $rs = $DB->get_recordset($this->table->getName(), null, '', $columnname);
                foreach ($rs as $record) {
                    if (!is_numeric($record->$columnname)) {
                        $this->print_cli("Column is unfixable as it contains values that are not numeric");
                        $this->safety = self::UNFIXABLE;
                        break;
                    }
                }
                $rs->close();
            } else {
                $this->print_cli("Checks for changing to this type is currently unsupported");
                $this->safety = self::UNFIXABLE;
            }
        }
    }

    /**
     * Evalutes the safety level of changing the length of a column
     */
    protected function check_length_safety(): void {
        global $DB;

        $columnname = $this->field->getName();
        $type = $this->field->getType();
        if ($type == XMLDB_TYPE_FLOAT) {
            $type = XMLDB_TYPE_NUMBER;
        }

        // Schema alignment does not check for decrease in integers, so we ignore them here as well.
        if ($type == XMLDB_TYPE_TEXT || $type == XMLDB_TYPE_INTEGER) {
            return;
        }

        // We only need to check length decreases and increasing decimals.
        $length = $this->field->getLength();
        if ($length >= $this->dbfield->max_length && $type != XMLDB_TYPE_NUMBER) {
            return;
        }

        if ($type == XMLDB_TYPE_CHAR) {
            // Check all values in the column are under the new length limit.
            if ($DB->record_exists_select($this->table->getName(), $DB->sql_length($columnname) . ' > ?', [$length])) {
                $this->print_cli("Column is unsafe as it contains data larger than the new length");
                $this->safety = self::UNSAFE;
                $this->fixes[] = self::ISSUE_LENGTH;
            }
        } else if ($type == XMLDB_TYPE_NUMBER) {
            // Numbers require some extra checks with decimals as well as length.
            $decimals = $this->field->getDecimals();
            if ($decimals < $this->dbfield->scale) {
                // Decreasing precision changes data. Update safety and continue checking.
                $this->print_cli("Column is unsafe as it causes a decrease in precision");
                $this->safety = self::UNSAFE;
            }

            // We only need to check length decreases and increasing decimals.
            if ($length >= $this->dbfield->max_length && $decimals <= $this->dbfield->scale) {
                return;
            }

            // Float SQL is more complicated across DBs, fall back to checking each value individually.
            $rs = $DB->get_recordset($this->table->getName(), null, '', "id,$columnname");
            $intlength = $length - $decimals;
            foreach ($rs as $record) {
                // Split the value into int part and decimals, and then compare.
                $parts = explode('.', ltrim($record->$columnname, '-'));
                $intdigits = strlen($parts[0]);
                $decimaldigits = isset($parts[1]) ? strlen($parts[1]) : 0;
                if ($intdigits > $intlength) {
                    $this->print_cli("Column is unfixable as it contains numeric values that do not fit");
                    $this->safety = self::UNFIXABLE;
                    break;
                } else if ($decimaldigits > $decimals && $this->safety === self::SAFE) {
                    // Decrease in precision.
                    $this->print_cli("Column is unsafe as it causes a decrease in precision for some data");
                    $this->safety = self::UNSAFE;
                }
            }
            $rs->close();
        } else {
            $this->print_cli("Checks for decreases of this type are not supported");
            $this->safety = self::UNFIXABLE;
        }
    }

    /**
     * Attempts to align the existing database column definition to match the expected schema.
     *
     * @return bool True if the issue was resolved, false otherwise.
     */
    public function resolve_issue(): bool {
        if ($this->get_dbman()->field_exists($this->table, $this->field)) {
            // Run any data fixes that are required.
            if (!empty($this->fixes)) {
                $this->fix_column_data_issues();
            }

            try {
                // Temporarily drop indexes to modify this column.
                $indexes = $this->drop_column_indexes();

                // Change field types fixes multiple issues at once.
                $this->print_cli("Updating column to match XMLDB definition");
                $this->get_dbman()->change_field_type($this->table, $this->field);

                // Not all databases change default with field type.
                if (in_array(self::ISSUE_DEFAULT, $this->issues)) {
                    $this->get_dbman()->change_field_default($this->table, $this->field);
                }
                $result = true;
            } catch (\ddl_change_structure_exception $e) {
                $this->print_cli($e->getMessage());
                $result = false;
            } finally {
                // Restore dropped indexes.
                if (!empty($indexes)) {
                    $this->restore_column_indexes($indexes);
                }
            }
        }
        return $result;
    }

    /**
     * Fixes data issues for a column that are required to resolve schema issues.
     */
    protected function fix_column_data_issues(): void {
        if (empty($this->fixes)) {
            return;
        }

        if (in_array(self::ISSUE_NULL, $this->fixes)) {
            $this->convert_null_to_default();
        }

        if (in_array(self::ISSUE_LENGTH, $this->fixes)) {
            $this->shorten_data();
        }
    }

    /**
     * Converts all null values to the default of the column.
     */
    protected function convert_null_to_default(): void {
        global $DB;

        $this->print_cli("Converting null values to default");
        // Text columns may not have a default, so fall back to a blank string.
        $default = $this->get_dbman()->generator->getDefault($this->field) ?? '';
        $DB->set_field_select($this->table->getName(), $this->field->getName(), $default, "{$this->field->getName()} IS NULL");
    }

    /**
     * Shortens data to the maximum length of the column.
     */
    protected function shorten_data(): void {
        global $DB;

        $length = $this->field->getLength();
        $columnname = $this->field->getName();
        $select = $DB->sql_length($columnname) . ' > ?';
        $rs = $DB->get_recordset_select($this->table->getName(), $select, [$length], "id,$columnname");

        $count = 0;
        foreach ($rs as $record) {
            $shortened = mb_substr($record->$columnname, 0, $length);
            $record->$columnname = $shortened;
            $DB->update_record($this->table->getName(), $record);
            $count++;
        }
        $rs->close();
        $this->print_cli("Shortened $count records to $length characters");
    }
}
