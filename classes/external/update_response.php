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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;
use local_assign_ai\assign_submission;

/**
 * External function to update the response message of a pending approval.
 *
 * @package     local_assign_ai
 * @category    external
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_response extends external_api {
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
            'message' => new external_value(PARAM_RAW, 'Updated message', VALUE_REQUIRED),
        ]);
    }

    /**
     * Executes the external function to update a pending approval response.
     *
     * @param int $courseid Course ID.
     * @param int $cmid Course module ID.
     * @param int $userid User ID.
     * @param string $message The updated message.
     * @return array The result of the operation.
     */
    public static function execute($courseid, $cmid, $userid, $message) {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'cmid' => $cmid,
            'userid' => $userid,
            'message' => $message,
        ]);

        // Target the active record under review (status = pending); older approved rows may
        // also exist for this user as history log entries.
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

        // The message is edited in the browser and rendered back into the review modal,
        // so strip any scripts/handlers before persisting (defence in depth on top of the
        // template output escaping).
        $record->message = clean_text($params['message'], FORMAT_HTML);
        $record->timemodified = time();
        $record->usermodified = $USER->id ?? $record->usermodified;
        $DB->update_record('local_assign_ai_pending', $record);

        return [
            'status' => 'ok',
            'message' => $record->message,
        ];
    }

    /**
     * Returns the description of the return values.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Operation status'),
            'message' => new external_value(PARAM_RAW, 'Updated message'),
        ]);
    }
}
