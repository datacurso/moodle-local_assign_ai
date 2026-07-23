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

namespace local_assign_ai\pending;

use assign;
use grading_manager;
use local_assign_ai\grading\advanced_grading;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/grade/grading/lib.php');

/**
 * Pending record helpers for local_assign_ai.
 *
 * @package     local_assign_ai
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /**
     * Gets the latest pending AI record for a user in an assignment.
     *
     * @param int $cmid Course module ID.
     * @param int $userid User ID.
     * @return \stdClass|null Pending record or null.
     */
    public static function get_latest_record(int $cmid, int $userid): ?\stdClass {
        global $DB;

        return $DB->get_record_sql(
            "SELECT * FROM {local_assign_ai_pending}
              WHERE assignmentid = :cmid AND userid = :userid
           ORDER BY timemodified DESC, id DESC",
            ['cmid' => $cmid, 'userid' => $userid]
        ) ?: null;
    }

    /**
     * Gets the feedback comment stored for a grade, if any.
     *
     * @param assign $assign The assignment instance.
     * @param \stdClass $grade The user grade record.
     * @return string|null Feedback comment text or null.
     */
    public static function get_feedback_comment(assign $assign, \stdClass $grade): ?string {
        global $DB;

        $feedback = $DB->get_record('assignfeedback_comments', ['grade' => $grade->id]);
        if ($feedback && $feedback->commenttext !== null) {
            $comment = trim((string)$feedback->commenttext);
            if ($comment !== '') {
                return $feedback->commenttext;
            }
        }

        return null;
    }

    /**
     * Synchronizes approved AI record with current grading data.
     *
     * @param assign $assign The assignment instance.
     * @param \stdClass $record The pending AI record.
     * @param \stdClass $grade The user grade record.
     * @param grading_manager $gradingmanager The grading manager.
     * @param int $usermodified The user ID that performed the grading.
     * @return void
     */
    public static function sync_after_grading(
        assign $assign,
        \stdClass $record,
        \stdClass $grade,
        grading_manager $gradingmanager,
        int $usermodified
    ): void {
        global $DB;

        $method = $gradingmanager->get_active_method();
        $rubricresponse = null;
        $assessmentguideresponse = null;

        if ($method === 'rubric') {
            $rubricresponse = advanced_grading::build_rubric_response($grade, $gradingmanager);
        } else if ($method === 'guide') {
            $assessmentguideresponse = advanced_grading::build_guide_response($grade, $gradingmanager);
        }

        $message = self::get_feedback_comment($assign, $grade) ?? $record->message;

        $update = (object) [
            'id' => $record->id,
            'grade' => (int) $grade->grade,
            'message' => $message,
            'rubric_response' => $rubricresponse,
            'assessment_guide_response' => $assessmentguideresponse,
            'timemodified' => time(),
            'usermodified' => $usermodified,
        ];

        $DB->update_record('local_assign_ai_pending', $update);
    }
}
