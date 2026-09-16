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
 * Report builder page for role assignments.
 *
 * @package    core_role
 * @copyright  2026 Catalyst IT Australia Pty Ltd
 * @author     Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_reportbuilder\local\filters\select;
use core_reportbuilder\system_report_factory;
use core_role\reportbuilder\local\systemreports\role_assignments;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('defineroles');

$roleid = optional_param('roleid', 0, PARAM_INT);

$urlparams = [];
if ($roleid > 0) {
    $urlparams['roleid'] = $roleid;
}

$baseurl = new moodle_url('/admin/roles/assignments.php', $urlparams);
$title = get_string('roleassignments', 'role');

$PAGE->set_primary_active_tab('siteadminnode');
$PAGE->set_secondary_active_tab('users');
$PAGE->set_url($baseurl);
$PAGE->set_context(context_system::instance());
$PAGE->navbar->add($title, $baseurl);

echo $OUTPUT->header();

$currenttab = 'assignments';
require('managetabs.php');

$report = system_report_factory::create(role_assignments::class, context_system::instance());
if ($roleid > 0) {
    $report->set_filter_values([
        'role:name_operator' => select::EQUAL_TO,
        'role:name_value' => (string) $roleid,
    ]);
}
echo $report->output();

echo $OUTPUT->footer();
