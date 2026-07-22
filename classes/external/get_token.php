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

namespace local_assign_ai\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;

/**
 * Class get_token
 *
 * @package    local_assign_ai
 * @copyright  2025 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_token extends external_api {
    /**
     * Parameters.
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'User ID'),
            'assignmentid' => new external_value(PARAM_INT, 'Assignment ID (cmid)'),
        ]);
    }

    /**
     * Returns the latest approval token for the given user and assignment.
     *
     * @param int $userid User ID
     * @param int $assignmentid Assignment ID (cmid)
     * @return array Approval token array
     */
    public static function execute($userid, $assignmentid) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'assignmentid' => $assignmentid,
        ]);
        $userid = $params['userid'];
        $assignmentid = $params['assignmentid'];

        // The plugin stores the cmid in assignmentid. Resolve its module context and
        // require review permission so a user cannot read another user's token by
        // enumerating ids.
        $cm = get_coursemodule_from_id('assign', $assignmentid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/assign_ai:review', $context);

        $sql = "
            SELECT *
            FROM {local_assign_ai_pending}
            WHERE userid = :userid
              AND assignmentid = :assignmentid
            ORDER BY
                CASE status
                    WHEN 'pending' THEN 0
                    WHEN 'approve' THEN 1
                    WHEN 'processing' THEN 2
                    WHEN 'queued' THEN 3
                    WHEN 'initial' THEN 4
                    ELSE 5
                END,
                timemodified DESC,
                id DESC
        ";

        $record = $DB->get_record_sql($sql, [
            'userid' => $userid,
            'assignmentid' => $assignmentid,
        ]);

        if (!$record) {
            return ['approval_token' => ''];
        }

        return [
            'approval_token' => $record->approval_token ?? '',
        ];
    }

    /**
     * Returns structure.
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'approval_token' => new external_value(PARAM_RAW, 'Approval Token'),
        ]);
    }
}
