<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * This file renders the page for the Admin URL metadata preview tool
 *
 * @package     tool_urlpreview
 * @copyright   2024 Team "the Z" <https://github.com/Catalyst-QUT-2023>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$url = optional_param('url', '', PARAM_URL);

admin_externalpage_setup('toolurlpreview');

require_capability('tool/urlpreview:usetool', context_system::instance());

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'tool_urlpreview'));

$templatedata = [
    'action' => 'index.php',
    'submittedUrl' => $url,
];

echo $OUTPUT->render_from_template('tool_urlpreview/form', $templatedata);

if (!empty($url)) {
    $PAGE->requires->js_call_amd('core/get_url_data', 'getPreviewTemplate', [$url]);
}

echo $OUTPUT->footer();
