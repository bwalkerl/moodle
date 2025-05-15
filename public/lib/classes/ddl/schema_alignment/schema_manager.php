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
 * Class responsible for managing schema issues.
 *
 * @package    core
 * @copyright  2025 Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schema_manager {
    /** @var array Keys of schema issue classes */
    public const ISSUE_KEYS = [
        missing_table::KEY,
        missing_column::KEY,
        missing_index::KEY,
        changed_column::KEY,
        extra_table::KEY,
        extra_column::KEY,
        extra_index::KEY,
    ];

    /** @var array Detected schema issues */
    public array $issues = [];

    /**
     * Stores details about a schema issue.
     *
     * @param schema_issue $newissue The new schema issue to add.
     */
    public function add(schema_issue $newissue): void {
        // Combine changed_columns.
        if ($newissue instanceof changed_column && $existing = $this->column_exists($newissue)) {
            $existing->combine_issue($newissue);
        } else {
            $this->issues[] = $newissue;
        }
    }

    /**
     * Checks whether there is an existing issue for the same table and field.
     *
     * @param changed_column $newissue The new schema issue.
     * @return changed_column|false The matching issue if found, otherwise false.
     */
    protected function column_exists(changed_column $newissue): changed_column|false {
        foreach ($this->issues as $issue) {
            if (!($issue instanceof changed_column)) {
                continue;
            }

            // Must match table and field.
            if ($issue->table === $newissue->table && $issue->field === $newissue->field) {
                return $issue;
            }
        }

        return false;
    }

    /**
     * Gets all issues matching the provided safety levels.
     *
     * @param array $safety The safety levels to return.
     * @return array All issues matching the safety levels.
     */
    public function get_issues(array $safety = []): array {
        return $safety
            ? array_filter($this->issues, fn($issue): bool => in_array($issue->safety, $safety))
            : $this->issues;
    }

    /**
     * Gets the total count of issues.
     *
     * @param array $safety The safety levels to count.
     * @return int The total number of issues.
     */
    public function count_issues(array $safety = []): int {
        return array_sum(array_map(fn($issue): int => $issue->count, $this->get_issues($safety)));
    }

    /**
     * Gets the total count of issues for the specified table.
     *
     * @param string $tablename The tablename to count.
     * @param array $issues The issues to compare against.
     * @return int The total number of issues for the table.
     */
    public static function count_table_issues(string $tablename, array $issues): int {
        $issues = array_filter($issues, fn($issue): bool => $issue->table->getName() === $tablename);
        return array_sum(array_map(fn($issue): int => $issue->count, $issues));
    }

    /**
     * Gets a summary of all schema issues.
     *
     * @return array Table name as keys and an array of messages as values
     */
    public function get_summary(): array {
        $summary = [];

        foreach ($this->issues as $issue) {
            $tablename = $issue->table->getName();
            $summary[$tablename] = array_merge($summary[$tablename] ?? [], $issue->get_messages());
        }

        return $summary;
    }

    /**
     * Evaluates and updates the safety level of risky issues.
     * This is done separately as the checks may be slow on large tables.
     */
    public function evaluate_risky_issues(): void {
        foreach ($this->issues as $issue) {
            $issue->evaluate_risky();
        }
    }

    /**
     * Attempts to fix database schema issues based on provided errors and safety levels.
     *
     * @param array $levels Safety levels specifying which types of fixes to attempt.
     * @return int The number of issues that were resolved.
     */
    public function fix_schema_issues(array $levels): int {
        global $DB;

        // The error check doesn't use cache, while some transformations do.
        // Reset cache to make sure we have the latest data.
        $DB->reset_caches();

        // To fix risky errors we need to evaluate the actual safety level.
        if (in_array(schema_issue::RISKY, $levels)) {
            $this->evaluate_risky_issues();
        }

        $resolved = 0;
        $issues = $this->get_issues($levels);
        $issues = $this->sort_fix_order($issues);
        foreach ($issues as $issue) {
            if ($issue->resolve_issue()) {
                $resolved += $issue->count;
            };
        }

        return $resolved;
    }

    /**
     * Default sort to sort issues by tablename, fieldname then indexname.
     */
    public function sort_issues(): void {
        usort($this->issues, function ($a, $b) {
            // Sort by table name. Should be unique for extra/missing tables.
            $cmp = strcmp($a->table->getName(), $b->table->getName());
            if ($cmp !== 0) {
                return $cmp;
            }

            // Sort by column name. Should be unique for extra/missing/changed.
            $afield = property_exists($a, 'field') ? $a->field->getName() : '';
            $bfield = property_exists($b, 'field') ? $b->field->getName() : '';
            if ($afield && $bfield) {
                $cmp = strcmp($afield, $bfield);
                if ($cmp !== 0) {
                    return $cmp;
                }
            } else if ($afield) {
                return -1;
            } else if ($bfield) {
                return 1;
            }

            // There should only have indexes left, sort by index name.
            $aindex = property_exists($a, 'index') ? $a->index->getName() : '';
            $bindex = property_exists($b, 'index') ? $b->index->getName() : '';
            $cmp = strcmp($aindex, $bindex);
            if ($cmp !== 0) {
                return $cmp;
            }
        });
    }

    /**
     * Sort issues in the order they should be fixed.
     *
     * @param array $issues Issues to be sorted.
     * @return array Sorted array.
     */
    protected function sort_fix_order(array $issues): array {
        // Fixes need to be processed in this order.
        $order = [
            missing_table::class,
            missing_column::class,
            changed_column::class,
            missing_index::class,
            extra_index::class,
            extra_column::class,
            extra_table::class,
        ];

        $ordermap = array_flip($order);

        usort($issues, function ($a, $b) use ($ordermap) {
            $typea = null;
            $typeb = null;

            foreach ($ordermap as $class => $index) {
                if ($a instanceof $class) {
                    $typea = $index;
                };

                if ($b instanceof $class) {
                    $typeb = $index;
                }
            }

            $cmp = $typea <=> $typeb;
            if ($cmp !== 0) {
                return $cmp;
            }

            // If same type, compare table names alphabetically.
            return strcmp($a->table->getName(), $b->table->getName());
        });

        return $issues;
    }
}
