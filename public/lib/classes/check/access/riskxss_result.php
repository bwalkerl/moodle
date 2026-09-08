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
 * Lists all users with XSS risk
 *
 * It would be great to combine this with risk trusts in user table,
 * unfortunately nobody implemented user trust UI yet :-(
 *
 * @package    core
 * @category   check
 * @copyright  2020 Brendan Heywood <brendan@catalyst-au.net>
 * @copyright  2008 petr Skoda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace core\check\access;

defined('MOODLE_INTERNAL') || die();

use core\check\result;

/**
 * Lists all users with XSS risk
 *
 * It would be great to combine this with risk trusts in user table,
 * unfortunately nobody implemented user trust UI yet :-(
 *
 * @copyright  2020 Brendan Heywood <brendan@catalyst-au.net>
 * @copyright  2008 petr Skoda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class riskxss_result extends \core\check\result {

    /** @var array SQL parameters. */
    protected $params = [];

    /** @var string SQL statement. */
    protected $sqlfrom;

    /** @var int Count of users with xss risk. */
    protected $count;

    /**
     * Constructor
     */
    public function __construct() {

        global $DB;
        $this->params = array('capallow' => CAP_ALLOW);
        $this->sqlfrom = "FROM (SELECT DISTINCT rcx.contextid, rcx.roleid
                           FROM {role_capabilities} rcx
                           JOIN {capabilities} cap ON (cap.name = rcx.capability AND
                                " . $DB->sql_bitand('cap.riskbitmask', RISK_XSS) . " <> 0)
                           WHERE rcx.permission = :capallow) rc,
                     {context} c,
                     {context} sc,
            {role_assignments} ra,
                        {user} u
                         WHERE c.id = rc.contextid
                           AND (sc.path = c.path OR
                                sc.path LIKE " . $DB->sql_concat('c.path', "'/%'") . " OR
                                c.path LIKE " . $DB->sql_concat('sc.path', "'/%'") . ")
                           AND u.id = ra.userid AND u.deleted = 0
                           AND ra.contextid = sc.id
                           AND ra.roleid = rc.roleid";

        $this->count = $DB->count_records_sql("SELECT COUNT(DISTINCT u.id) $this->sqlfrom", $this->params);

        if ($this->count == 0) {
            $this->status = result::OK;
        } else {
            $this->status = result::WARNING;
        }

        $this->summary = get_string('check_riskxss_warning', 'report_security', $this->count);

    }

    /**
     * Showing the full list of user may be slow so defer it
     *
     * @return string
     */
    public function get_details(): string {

        global $CFG, $DB;

        require_once($CFG->libdir . '/tablelib.php');

        // Table setup.
        $table = new \flexible_table('riskxss-details');

        $detail = optional_param('detail', '', PARAM_TEXT);
        $baseurl = new \moodle_url('/report/security/index.php', ['detail' => $detail]);
        $table->define_baseurl($baseurl);

        $table->define_columns(['fullname', 'email', 'roleassignments']);
        $table->define_headers([
            get_string('fullname'),
            get_string('email'),
            get_string('roleassignmentsrisk', 'role'),
        ]);
        $table->is_persistent(true);
        $table->sortable(true, 'firstname', SORT_ASC);
        $table->initialbars(true);
        $table->set_attribute('class', 'generaltable w-auto');

        ob_start();
        $table->setup();

        $total = $this->count;
        [$initialwhere, $initialparams] = $table->get_sql_where();
        if (!empty($initialwhere)) {
            $total = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT u.id) $this->sqlfrom AND $initialwhere",
                array_merge($this->params, $initialparams),
            );
        }
        $table->pagesize(100, $total);

        $orderbysql = $table->get_sql_sort();

        // Get paginated users.
        $userfieldsapi = \core_user\fields::for_userpic();
        $userfields = $userfieldsapi->get_sql('u', false, '', '', false)->selects;

        $risksql = "SELECT DISTINCT ra.userid, ra.contextid, ra.roleid $this->sqlfrom";
        if (!empty($initialwhere)) {
            $risksql .= " AND $initialwhere";
        }
        $pagedusers = $DB->get_records_sql(
            "SELECT $userfields, COUNT(1) AS roleassignments
               FROM ($risksql) risk
               JOIN {user} u ON u.id = risk.userid
           GROUP BY u.id
           ORDER BY $orderbysql",
            array_merge($this->params, $initialparams),
            $table->get_page_start(),
            $table->get_page_size(),
        );

        // Run a second query to load full contextid and roleid for each user on this page.
        $useridstoload = array_keys($pagedusers);
        $userroledetails = [];
        if (!empty($useridstoload)) {
            [$insql, $inparams] = $DB->get_in_or_equal($useridstoload, SQL_PARAMS_NAMED, 'riskxssuid');
            $detailrows = $DB->get_recordset_sql(
                "SELECT risk.userid, risk.contextid, risk.roleid
                   FROM ($risksql) risk
                  WHERE risk.userid $insql
               ORDER BY risk.userid, risk.contextid",
                array_merge($this->params, $initialparams, $inparams),
            );
            foreach ($detailrows as $detail) {
                $userroledetails[$detail->userid][$detail->roleid][] = $detail->contextid;
            }
            $detailrows->close();
        }

        $roles = role_fix_names(get_all_roles());
        $roleorder = array_flip(array_keys($roles));

        // Add data to table.
        $maxcontext = 10;
        $contextnames = [];
        foreach ($pagedusers as $user) {
            // Sort roles by role order.
            $rolesources = $userroledetails[$user->id] ?? [];
            uksort($rolesources, fn($a, $b) => $roleorder[$a] <=> $roleorder[$b]);

            // Build role html.
            $roleshtml = '';
            foreach ($rolesources as $roleid => $contextids) {
                if (empty($contextids)) {
                    continue;
                }

                $contexthtml = [];
                $contextcount = count($contextids);
                $remainingcontext = 0;
                foreach ($contextids as $contextid) {
                    if (!isset($contextnames[$contextid])) {
                        $context = \context::instance_by_id($contextid);
                        $contextnames[$contextid] = $context->get_context_name();
                    }

                    $contextname = $contextnames[$contextid];
                    $permissionsurl = new \moodle_url('/admin/roles/check.php', [
                        'contextid' => $contextid,
                        'reportuser' => $user->id,
                        'risk' => 'riskxss',
                    ]);
                    $contexthtml[$contextname] = \html_writer::link($permissionsurl, $contextname);
                    if (count($contexthtml) >= $maxcontext) {
                        $remainingcontext = $contextcount - count($contexthtml);
                        break;
                    }
                }

                // Sort context by context name.
                uksort($contexthtml, 'strnatcasecmp');
                if ($remainingcontext > 0) {
                    $more = get_string('moreitems', 'role', $remainingcontext);
                    $contexthtml[$more] = \html_writer::div($more, 'text-muted');
                }

                $rolename = isset($roles[$roleid]) ? $roles[$roleid]->localname : '';
                $roleshtml .= \html_writer::tag(
                    'details',
                    \html_writer::tag('summary', "$rolename ($contextcount)") . \html_writer::alist($contexthtml),
                );
            }

            $user->roleassignments = $roleshtml;
            $table->add_data_keyed($table->format_row($user));
        }

        $table->finish_output();
        $details = ob_get_clean();

        return get_string('check_riskxss_details', 'report_security', $details);
    }
}
