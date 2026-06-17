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
use external_single_structure;
use external_value;

/**
 * External function for processing assignment submissions with AI.
 *
 * Handles the submission of one or multiple student assignments to the
 * AI grading service. If the "all" parameter is enabled, the process is queued
 * as an ad-hoc Moodle task and handled asynchronously by the cron system.
 *
 * @package     local_assign_ai
 * @category    external
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_submission extends external_api {
    /**
     * Returns the parameters required for the external function.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID', VALUE_REQUIRED),
            'userid' => new external_value(PARAM_INT, 'User ID (0 for all)', VALUE_DEFAULT, 0),
            'all' => new external_value(
                PARAM_BOOL,
                'Whether to process all users (true) or a single one (false)',
                VALUE_DEFAULT,
                false
            ),
            'pendingid' => new external_value(PARAM_INT, 'Existing pending record id (for review update)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Executes the AI submission processing.
     *
     * When called with the "all" flag, an ad-hoc task is created to handle
     * all student submissions asynchronously. If a specific user ID is
     * provided, the function processes that submission immediately.
     *
     * @param int $cmid The course module ID.
     * @param int $userid The user ID (0 for all users).
     * @param bool $all Whether to process all submissions (queued task) or a single one.
     * @param int $pendingid Existing pending record id to update when reviewing with AI (0 to skip).
     * @return array An associative array containing the processing result.
     */
    public static function execute($cmid, $userid = 0, $all = false, $pendingid = 0) {
        global $DB;

        $cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('local/assign_ai:review', $context);

        $processed = 0;

        // If processing all submissions → queue background task.
        if ($all) {
            // Pre-check there is something to process to avoid queuing empty tasks.
            $pendingcount = $DB->count_records('local_assign_ai_pending', [
                'courseid' => $course->id,
                'assignmentid' => $cm->id,
                'status' => \local_assign_ai\assign_submission::STATUS_INITIAL,
            ]);
            if ($pendingcount === 0) {
                return [
                    'status' => 'none',
                    'processed' => 0,
                ];
            }

            // Move INITIAL records to QUEUED state so UI shows 'en cola'.
            $DB->set_field('local_assign_ai_pending', 'status', \local_assign_ai\assign_submission::STATUS_QUEUED, [
                'courseid' => $course->id,
                'assignmentid' => $cm->id,
                'status' => \local_assign_ai\assign_submission::STATUS_INITIAL,
            ]);

            $task = new \local_assign_ai\task\process_all_submissions();
            $task->set_custom_data([
                'cmid' => $cmid,
                'courseid' => $course->id,
                'pendingcount' => $pendingcount,
            ]);
            \core\task\manager::queue_adhoc_task($task);

            return [
                'status' => 'queued',
                'processed' => 0,
            ];
        }

        // Process a single user submission asynchronously via an ad-hoc task (like "Review all").
        if ($userid && $pendingid) {
            $student = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

            // Mark as queued and enqueue the ad-hoc review task. The UI then shows 'en cola'.
            \local_assign_ai\assign_submission::queue_ai_review($cmid, (int) $course->id, (int) $student->id, $pendingid);

            return [
                'status' => 'queued',
                'processed' => 0,
            ];
        }

        return [
            'status' => 'ok',
            'processed' => $processed,
        ];
    }

    /**
     * Returns the structure of the function’s response.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Processing status (ok, queued, or error)'),
            'processed' => new external_value(PARAM_INT, 'Number of processed submissions'),
        ]);
    }
}
