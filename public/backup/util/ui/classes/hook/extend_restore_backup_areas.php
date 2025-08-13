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

namespace core_backup\hook;

/**
 * Hook to extend restore backup file areas.
 *
 * @package    core_backup
 * @copyright  2025 Benjamin Walker <benjaminwalker@catalyst-au.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @property-read \renderer_base $renderer The page renderer object
 */
#[\core\attribute\label('Extends restore backup file areas.')]
#[\core\attribute\tags('backup')]
final class extend_restore_backup_areas {
    /**
     * Hook to extend restore backup file areas.
     *
     * @param \renderer_base $renderer
     * @param string $output Initial output
     */
    public function __construct(
        /** @var \renderer_base The page renderer object */
        public readonly \renderer_base $renderer,
        /** @var string The collected output */
        private string $output = '',
    ) {
    }

    /**
     * Plugins implementing callback can add a heading to the the body.
     *
     * This keeps the formatting consistent with headings of other backup areas.
     *
     * @param string $heading
     * @param string $description
     */
    public function add_heading(string $heading, string $description = ''): void {
        if ($heading) {
            $this->output .= \html_writer::tag('h3', $heading, ['class' => 'mt-6']);
        }
        if ($description) {
            $this->output .= \html_writer::tag('div', $description, ['class' => 'mb-3']);
        }
    }

    /**
     * Plugins implementing callback can add any HTML to the the body.
     *
     * Must be a string containing valid html content.
     *
     * @param null|string $output
     */
    public function add_html(?string $output): void {
        if ($output) {
            $this->output .= $output;
        }
    }

    /**
     * Returns all HTML added by the plugins
     *
     * @return string
     */
    public function get_output(): string {
        return $this->output;
    }
}
