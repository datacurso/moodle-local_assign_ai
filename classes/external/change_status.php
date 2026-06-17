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

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use local_assign_ai\assign_submission;
use local_assign_ai\grading\feedback_applier;

/**
 * External function to change the status of AI assignment approvals.
 *
 * @package     local_assign_ai
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class change_status extends external_api {
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

        // Target the active record under review (status = pending). A user may also have
        // older approved rows kept as history log entries, so we must filter by status.
        $record = $DB->get_record('local_assign_ai_pending', [
            'courseid' => $params['courseid'],
            'assignmentid' => $params['cmid'],
            'userid' => $params['userid'],
            'status' => assign_submission::STATUS_PENDING,
        ], '*', MUST_EXIST);

        $cm = get_coursemodule_from_id('assign', $record->assignmentid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('local/assign_ai:changestatus', $context);

        try {
            $record->status = $params['action'];
            $record->timemodified = time();
            $record->usermodified = $USER->id ?? $record->usermodified;
            $DB->update_record('local_assign_ai_pending', $record);

            if ($params['action'] === 'approve') {
                $cm = get_coursemodule_from_id('assign', $record->assignmentid, 0, false, MUST_EXIST);
                $context = \context_module::instance($cm->id);
                $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
                $assign = new \assign($context, $cm, $course);
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
