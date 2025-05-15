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
 * Validate that the current db structure matches the install.xml files.
 *
 * @package   core
 * @copyright 2014 Totara Learning Solutions Ltd {@link http://www.totaralms.com/}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author    Petr Skoda <petr.skoda@totaralms.com>
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir.'/clilib.php');

$help = "Validate database structure

The detected fix safety level will be one of:
  safe        - these can be fixed with no loss of data

  dbindex     - these are safe with no data loss but may reduce the performance
                eg. removing an additional index

  risky       - may be safe, unsafe, or unfixable and this depends on the data
                eg. changing a column to not null - either safe OR unsafe
                eg. reducing a column from 100 chars to 80 chars - either safe OR unsafe
                eg. reducing length of a float column - either safe OR unfixable

  unsafe      - these will result in some loss of data
                eg. deleting a table or column
                eg. a float which is 3 decimals reduced to 2 decimals
                eg. reducing length of a column from 100 chars to 80 chars AND there is data
                    with 80+ chars in the database will truncate data

  unfixable   - this is an issue that cannot be resolved automatically because of conflicting
                requirements OR it has not been implemented
                eg. a missing column which has no default and cannot be null
                eg. changing a column to integer AND there is data with non-numeric values
                eg. reducing length of a float column AND there is data that is too long

Note:
- Column schema fixes will be run together, eg. a column with an incorrect type, length and null.
  If a column has multiple schema issues, the higher risk level will be used.
- Some fixes have dependencies on other fixes, eg. creating an index requires the column to exist.
  If a table has unfixables issues then other safe fixes may not be able to be resolved.

WARNING:
- Schema alignment issues should be resolved at the source whenever possible.
- This script changes the DB schema and should be treated similar to an upgrade and run with care,
  and likely during maintenances mode.

Options:
-t, --tables=tablename   Runs the schema check on specified tables
-e, --exclude=tablename  Exclude specified tables from the schema check
-i, --issues=issuetype   Runs the schema check only for the specified issue types.
-c, --check-risky        Runs potentially slow queries to determine the safety level.
                         It will classify fixes as either safe, unsafe or unfixable.
                         It MUST be included to fix data marked as risky.
-f, --fix=safe           Correct any schema issues which are safe.
-f, --fix=unsafe         Correct any schema issues which are unsafe, including fixing data.
                         Data fixes may involve changing nulls to default and shortening data.
-f, --fix=dbindex        Removes any extra db indexes
-h, --help               Print out this help.

Example:
\$ sudo -u www-data /usr/bin/php admin/cli/check_database_schema.php
\$ sudo -u www-data /usr/bin/php admin/cli/check_database_schema.php --tables=config*,course
\$ sudo -u www-data /usr/bin/php admin/cli/check_database_schema.php --issues=extratables,extracolumns
\$ sudo -u www-data /usr/bin/php admin/cli/check_database_schema.php --tables=config --fix=safe
\$ sudo -u www-data /usr/bin/php admin/cli/check_database_schema.php --tables=config --check-risky --fix=safe,unsafe
";

[$options, $unrecognized] = cli_get_params([
    'help' => false,
    'tables' => false,
    'exclude' => false,
    'issues' => false,
    'check-risky' => false,
    'fix' => false,
], [
    'h' => 'help',
    't' => 'tables',
    'e' => 'exclude',
    'i' => 'issues',
    'c' => 'check-risky',
    'f' => 'fix',
]);

if ($options['help']) {
    echo $help;
    exit(0);
}

if (empty($CFG->version)) {
    echo "Database is not yet installed.\n";
    exit(2);
}

if (str_contains($options['fix'], 'risky')) {
    cli_error("'risky' is an invalid cli option for fix. Include --check-risky to classify risky fixes as either safe or unsafe.");
}

$dbmanager = $DB->get_manager();

$tables = null;
if ($options['tables']) {
    $tables = array_map('trim', explode(',', $options['tables']));
    $tables = $dbmanager->resolve_table_patterns($tables);
    sort($tables);
    echo "Limiting tables to: " . implode(', ', $tables) . "\n";
}

$exclude = null;
if ($options['exclude']) {
    $exclude = array_map('trim', explode(',', $options['exclude']));
    $exclude = $dbmanager->resolve_table_patterns($exclude);
    sort($exclude);
    echo "Excluding tables: " . implode(', ', $exclude) . "\n";
}

$checkoptions = [
    'tables' => $tables,
    'exclude' => $exclude,
];

if ($options['issues']) {
    $issues = array_map('trim', explode(',', $options['issues']));
    $issuetypes = core\ddl\schema_alignment\schema_manager::ISSUE_KEYS;
    $checkoptions += array_fill_keys($issuetypes, false);
    foreach ($issues as $issue) {
        if (!in_array($issue, $issuetypes)) {
            $validtypes = implode(', ', $issuetypes);
            echo "Invalid issue type: $issue.\nValid types include: $validtypes.\n";
            exit(6);
        }
        $checkoptions[$issue] = true;
    }
    echo "Limiting issues to: " . implode(', ', $issues) . "\n";
}

$schema = $dbmanager->get_install_xml_schema($tables, $exclude);
$sm = $dbmanager->check_database_schema($schema, $checkoptions, false);
if (!$sm->count_issues()) {
    echo "Database structure is ok.\n";
    exit(0);
}

$prevtable = '';
$issues = $sm->get_issues();
foreach ($issues as $issue) {
    $tablename = $issue->table->getName();
    if ($tablename !== $prevtable) {
        cli_separator();
        echo "$tablename\n";
        $prevtable = $tablename;
    }
    foreach ($issue->get_messages() as $message) {
        echo sprintf(" * fix=%-10s %s\n", $issue->safety, $message);
    }
}
cli_separator();

if ($options['check-risky']) {
    echo "Running further checks on column data to classify risky fixes as safe, unsafe or unfixable\n";
    $sm->evaluate_risky_issues();
    cli_separator();
}

if ($issues && !$options['fix']) {
    exit(1);
}

if ($issues && $options['fix']) {
    echo "Running schema alignment fixes for safety level: {$options['fix']}\n";
    $levels = array_map('trim', explode(',', $options['fix']));
    $fixed = $sm->fix_schema_issues($levels);
    $attempted = $sm->count_issues($levels);

    cli_separator();
    if ($fixed && $fixed === $attempted) {
        echo "$fixed schema issues were resolved.\n";
    } else if ($fixed) {
        echo "$fixed out of $attempted schema issues were resolved.\n";
        exit(3);
    } else if ($attempted) {
        echo "No schema issues were resolved.\n";
        exit(4);
    } else {
        echo "No schema issues match the safety levels.\n";
        exit(5);
    }
}

exit(0);
