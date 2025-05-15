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

Options:
--tables=tablename    Runs fixes only on specified tables
--exclude=tablename   Exclude fixes on specified tables
--check-risky         Runs potentially slow queries on data to check if risky fixes are safe
--update-risky        Runs SQL to resolve the data issues where possible
--fix=safe            Runs SQL to fix schema issues for all specified levels. safe, risky, unsafe, manual
-h, --help            Print out this help.

Example:
\$ sudo -u www-data /usr/bin/php admin/cli/check_database_schema.php
\$ sudo -u www-data /usr/bin/php admin/cli/check_database_schema.php --tables=config,config_plugins --fix=safe
\$ sudo -u www-data /usr/bin/php admin/cli/check_database_schema.php --check-risky --fix=safe
";

list($options, $unrecognized) = cli_get_params([
    'help' => false,
    'tables' => false,
    'exclude' => false,
    'check-risky' => false,
    'update-risky' => false,
    'fix' => false,
], [
    'h' => 'help',
]);

if ($options['help']) {
    echo $help;
    exit(0);
}

if (empty($CFG->version)) {
    echo "Database is not yet installed.\n";
    exit(2);
}

$dbmanager = $DB->get_manager();
$schema = $dbmanager->get_install_xml_schema();

if (!$errors = $dbmanager->check_database_schema($schema, null, true)) {
    echo "Database structure is ok.\n";
    exit(0);
}

foreach ($errors as $table => $items) {
    cli_separator();
    mtrace($table);
    foreach ($items as $item) {
        mtrace(" * $item->desc ($item->fix)");
    }
}
cli_separator();

if ($options['tables']) {
    mtrace("Restricting fixes to tables: " . $options['tables']);
    $tables = array_map('trim', explode(',', $options['tables']));
    $errors = array_filter($errors, fn($key) => in_array($key, $tables, true), ARRAY_FILTER_USE_KEY);
}

if ($options['exclude']) {
    mtrace("Excluding fixes from tables: " . $options['exclude']);
    $exclude = array_map('trim', explode(',', $options['exclude']));
    $errors = array_filter($errors, fn($key) => !in_array($key, $exclude, true), ARRAY_FILTER_USE_KEY);
}

if ($options['update-risky']) {
    mtrace("Running SQL to resolve the data issues where possible");
    $dbmanager->evaluate_risky_errors($errors, true);
    cli_separator();
} else if ($options['check-risky']) {
    mtrace("Running further tests to check safety of risky fixes");
    $dbmanager->evaluate_risky_errors($errors, false);
    cli_separator();
}

if ($errors && $options['fix']) {
    mtrace("Running schema alignment fixes for safety level: " . $options['fix']);
    $levels = array_map('trim', explode(',', $options['fix']));
    $dbmanager->fix_database_schema($errors, $levels);
}

exit(1);
