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

namespace local_assign_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/assign/locallib.php');

use local_assign_ai\local\provider_dependency;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;
use local_assign_ai\assign_submission;
use local_assign_ai\grading\feedback_applier;
use local_assign_ai\local\activity_access;

/**
 * External function to change the status of AI assignment approvals.
 *
 * @package     local_assign_ai
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class change_status extends external_api {
    /** @var string[] Actions accepted from the review page. */
    private const ALLOWED_ACTIONS = [
        assign_submission::STATUS_APPROVED,
        assign_submission::STATUS_REJECTED,
    ];

    /**
     * Returns the description of the parameters for this external function.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID', VALUE_REQUIRED),
            'cmid' => new external_value(PARAM_INT, 'Course module ID', VALUE_REQUIRED),
            'userid' => new external_value(PARAM_INT, 'User ID', VALUE_REQUIRED),
            'action' => new external_value(PARAM_ALPHA, 'Action: approve or rejected', VALUE_REQUIRED),
        ]);
    }

    /**
     * Executes the external function.
     *
     * @param int $courseid Course ID.
     * @param int $cmid Course module ID.
     * @param int $userid User ID.
     * @param string $action The action to apply (approve or rejected).
     * @return array The result of the operation.
     */
    public static function execute($courseid, $cmid, $userid, $action) {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'cmid' => $cmid,
            'userid' => $userid,
            'action' => $action,
        ]);

        if (!in_array($params['action'], self::ALLOWED_ACTIONS, true)) {
            throw new \invalid_parameter_exception('Invalid action: ' . $params['action']);
        }

        provider_dependency::require_enabled();

        // Authorise before looking up the record, so its existence is not revealed to unauthorised callers.
        $access = activity_access::require_access($params['cmid'], 'local/assign_ai:changestatus', $params['courseid']);

        // Target the active record under review (status = pending). A user may also have
        // older approved rows kept as history log entries, so we must filter by status.
        $record = activity_access::require_pending_record(
            $access,
            userid: $params['userid'],
            status: assign_submission::STATUS_PENDING
        );

        try {
            $record->status = $params['action'];
            $record->timemodified = time();
            $record->usermodified = $USER->id ?? $record->usermodified;
            $DB->update_record('local_assign_ai_pending', $record);

            if ($params['action'] === assign_submission::STATUS_APPROVED) {
                $assign = new \assign($access->context, $access->cm, $access->course);
                feedback_applier::apply_ai_feedback($assign, $record, $USER->id);
            }
        } catch (\Throwable $e) {
            // Log the failure (marks the record as failed) and surface it to the caller.
            assign_submission::register_failure($e, (int) $record->id);
            throw $e;
        }

        return [
            'status'    => 'ok',
            'newstatus' => $record->status,
        ];
    }

    /**
     * Returns the description of the return values.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status'    => new external_value(PARAM_TEXT, 'Operation status'),
            'newstatus' => new external_value(PARAM_TEXT, 'New status applied'),
        ]);
    }
}
