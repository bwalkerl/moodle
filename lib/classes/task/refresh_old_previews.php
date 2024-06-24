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

namespace core\task;

use core\task\scheduled_task;
use core\url\unfurler;
use core\url\urlpreview;

/**
 * This task refreshes old urlpreviews in the DB by updating the stored metadata.
 * It rescrapes the url for records that are older than 2 weeks but which have been
 * previewed in the last 2 weeks.
 *
 * The delete_unused_previews task is responsible for clearing out old records from the DB.
 *
 * @package    core
 * @copyright  2024 Team "the Z" <https://github.com/Catalyst-QUT-2023>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class refresh_old_previews extends scheduled_task {
    /**
     * Get the name.
     */
    public function get_name(): string {
        return get_string('refresholdpreviews', 'tool_urlpreview');
    }

    /**
     * The function executed whenever the task is called.
     */
    public function execute(): void {
        global $DB;
        $twoweeksago = time() - (2 * WEEKSECS);

        // This selects records older than 2 weeks that have been previewed in the last 2 weeks.
        $select = "timecreated < ? AND lastpreviewed >= ?";
        $records = urlpreview::get_records_select($select, [$twoweeksago, $twoweeksago]);

        foreach ($records as $record) {
            // Refresh metadata for the URL using the `unfurl` class.
            $url = $record->get('url');
            $unfurler = new unfurler($url);
            $unfurler->refresh();
        }
    }
}
