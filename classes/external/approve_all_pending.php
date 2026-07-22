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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;
use local_assign_ai\assign_submission;
use local_assign_ai\grading\feedback_applier;

/**
 * External function to approve all pending AI feedback for an assignment.
 *
 * @package     local_assign_ai
 * @category    external
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class approve_all_pending extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID', VALUE_REQUIRED),
            'cmid' => new external_value(PARAM_INT, 'Course module ID', VALUE_REQUIRED),
        ]);
    }

    /**
     * Execute the approval for all pending records for the assignment.
     *
     * @param int $courseid Course ID.
     * @param int $cmid Course module ID.
     * @return array Result with approved count.
     */
    public static function execute($courseid, $cmid) {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'cmid' => $cmid,
        ]);

        $cm = get_coursemodule_from_id('assign', $params['cmid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

        self::validate_context($context);
        require_capability('local/assign_ai:changestatus', $context);

        $assign = new \assign($context, $cm, $course);

        $pendings = $DB->get_records('local_assign_ai_pending', [
            'courseid' => $params['courseid'],
            'assignmentid' => $params['cmid'],
            'status' => \local_assign_ai\assign_submission::STATUS_PENDING,
        ], 'id ASC');

        $approved = 0;
        foreach ($pendings as $record) {
            try {
                $record->status = 'approve';
                $record->timemodified = time();
                $record->usermodified = $USER->id ?? $record->usermodified;
                $DB->update_record('local_assign_ai_pending', $record);

                // Apply feedback on approve.
                feedback_applier::apply_ai_feedback($assign, $record, $USER->id);
                $approved++;
            } catch (\Throwable $e) {
                // Log the failure (marks the record as failed) and keep approving the rest.
                assign_submission::register_failure($e, (int) $record->id);
            }
        }

        return [
            'status' => 'ok',
            'approved' => $approved,
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Operation status'),
            'approved' => new external_value(PARAM_INT, 'Number of approved records'),
        ]);
    }
}
