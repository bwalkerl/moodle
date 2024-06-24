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


namespace core\external;

use core_external\external_function_parameters;
use core_external\external_api;
use core_external\external_value;
use core\url\unfurler;

/**
 * Implementation of web service core_get_preview
 *
 * @package    core
 * @copyright  2024 Team "the Z" <https://github.com/Catalyst-QUT-2023>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_preview extends external_api {

    /**
     * Describes the parameters for core_url_get_preview
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            ['url' => new external_value(PARAM_URL, 'The URL to get data')]);
    }

    /**
     * Implementation of web service core_url_get_preview
     *
     * @param string $url
     * @param int|null $display
     * @return bool|string
     */
    public static function execute(string $url, int $display = null) {
        global $DB;
        // Parameter validation.
        ['url' => $url] = self::validate_parameters(
            self::execute_parameters(),
            ['url' => $url]
        );

        // From web services we don't call require_login(), but rather validate_context.
        $context = \context_system::instance();
        self::validate_context($context);

        $unfurler = new unfurler($url);
        return $unfurler->render_preview($display ?? URLPREVIEW_DISPLAY_TOOL);
    }

    /**
     * Describe the return structure for tool_urlpreview_get_preview
     *
     * @return external_value
     */
    public static function execute_returns(): external_value {
        return new external_value(PARAM_RAW, 'HTML Output');
    }
}
