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
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_multiple_structure;

/**
 * External function to retrieve details of a pending AI assignment approval.
 *
 * Provides the details for a pending approval request, such as token, message,
 * status, user, and grading data.
 *
 * @package    local_assign_ai
 * @category   external
 * @copyright  2025 Datacurso
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_details extends external_api {
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
        ]);
    }

    /**
     * Executes the external function to retrieve approval details.
     *
     * @param int $courseid Course ID.
     * @param int $cmid Course module ID.
     * @param int $userid User ID.
     * @return array The details of the pending approval.
     */
    public static function execute($courseid, $cmid, $userid) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'cmid' => $cmid,
            'userid' => $userid,
        ]);

        $sql = "
            SELECT *
            FROM {local_assign_ai_pending}
            WHERE courseid = :courseid
              AND assignmentid = :cmid
              AND userid = :userid
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
            LIMIT 1
        ";

        $record = $DB->get_record_sql($sql, [
            'courseid' => $params['courseid'],
            'cmid' => $params['cmid'],
            'userid' => $params['userid'],
        ]);

        if (!$record) {
            return [
                'message' => '',
                'status' => 'none',
                'userid' => $params['userid'],
                'grade' => null,
                'rubric_response' => null,
            ];
        }

        $cm = get_coursemodule_from_id('assign', $record->assignmentid, $record->courseid, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('local/assign_ai:viewdetails', $context);

        return [
            'message' => $record->message,
            'status' => $record->status,
            'userid' => $record->userid,
            'grade' => $record->grade,
            'rubric_response' => $record->rubric_response,
            'assessment_guide_response' => $record->assessment_guide_response,
        ];
    }

    /**
     * Returns the description of the return values.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'message' => new external_value(PARAM_RAW, 'AI message'),
            'status' => new external_value(PARAM_TEXT, 'AI status'),
            'userid' => new external_value(PARAM_INT, 'User ID'),
            'grade' => new external_value(PARAM_FLOAT, 'AI suggested grade', VALUE_OPTIONAL),
            'rubric_response' => new external_value(PARAM_RAW, 'AI rubric response JSON', VALUE_OPTIONAL),
            'assessment_guide_response' => new external_value(PARAM_RAW, 'AI guide response JSON', VALUE_OPTIONAL),
        ]);
    }
}
