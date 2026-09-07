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
 * Library code used by the roles administration interfaces.
 *
 * @package    core_role
 * @copyright  1999 onwards Martin Dougiamas (http://dougiamas.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Subclass of core_role_capability_table_base for use on the Check permissions page.
 *
 * We have one additional column, Allowed, which contains yes/no.
 */
class core_role_check_capability_table extends core_role_capability_table_with_risks {
    protected $user;
    protected $fullname;
    protected $contextname;
    protected $stryes;
    protected $strno;
    private $hascap;

    /**
     * Constructor
     * @param object $context the context this table relates to.
     * @param object $user the user we are generating the results for.
     * @param string $contextname $context->get_context_name() - to save recomputing.
     */
    public function __construct($context, $user, $contextname) {
        parent::__construct($context, 'explaincaps', 0);
        $this->user = $user;
        $this->fullname = fullname($user);
        $this->contextname = $contextname;
        $this->stryes = get_string('yes');
        $this->strno = get_string('no');
        $this->add_classes(['table-striped']);
    }

    /**
     * Loads parent permissions.
     */
    protected function load_parent_permissions() {
        $this->parentpermissions = [];
    }

    /**
     * Render the risk summary box before the capability table.
     */
    public function display() {
        global $OUTPUT;
        $risks = $this->get_role_risks_info();
        if ($risks) {
            echo $OUTPUT->box($risks, 'generalbox');
        }
        parent::display();
    }

    /**
     * Count the risky capabilities available to the selected user.
     *
     * @return array
     */
    protected function get_role_risks() {
        $allrisks = get_all_risks();
        $risks = array_fill_keys(array_keys($allrisks), 0);
        foreach ($this->capabilities as $capability) {
            if (!has_capability($capability->name, $this->context, $this->user->id)) {
                continue;
            }
            foreach ($allrisks as $type => $risk) {
                if ($risk & (int)$capability->riskbitmask) {
                    $risks[$type]++;
                }
            }
        }
        return $risks;
    }

    protected function add_header_cells() {
        echo '<th>' . get_string('allowed', 'core_role') . '</th>';
        echo '<th class="risk" colspan="' . count($this->allrisks) . '" scope="col">' . get_string('risks', 'core_role') . '</th>';
    }

    protected function num_extra_columns() {
        return 1 + count($this->allrisks);
    }

    protected function get_row_classes($capability) {
        $this->hascap = has_capability($capability->name, $this->context, $this->user->id);
        if ($this->hascap) {
            return array('yes');
        } else {
            return array('no');
        }
    }

    /**
     * Hide rows that do not match the active risk filter.
     *
     * @param object $capability The capability being checked.
     * @return bool True if the row should be skipped.
     */
    protected function skip_row($capability) {
        if (parent::skip_row($capability)) {
            return true;
        }

        // Only display allowed capabilities when filtering risks.
        $filter = optional_param('risk', '', PARAM_TEXT);
        return $filter && !has_capability($capability->name, $this->context, $this->user->id);
    }

    /**
     * Add table cells for permissions.
     *
     * @param object $capability The capability being checked.
     */
    protected function add_permission_cells($capability) {
        $content = $this->hascap ? $this->stryes : $this->strno;
        return '<td>' . $content . '</td>';
    }
}
