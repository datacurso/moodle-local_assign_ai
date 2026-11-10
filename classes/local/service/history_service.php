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

namespace local_assign_ai\local\service;

use local_assign_ai\assign_submission;
use local_assign_ai\local\activity_access;

/**
 * Record actions of the AI review history page (retry a review, view its log).
 *
 * Every action authorises the user on the activity first and then loads the record scoped to
 * that activity, so an absent id and an id of another activity fail the same way
 * (invalidpendingrecord) and the page cannot be used to learn which ids exist elsewhere.
 *
 * @package     local_assign_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class history_service {
    /** Capability required by the history page actions. */
    private const CAPABILITY = 'local/assign_ai:review';

    /**
     * Loads the record whose log is viewed or downloaded.
     *
     * @param int $cmid Course module id of the assign activity.
     * @param int $logid Pending record id.
     * @return \stdClass The pending record.
     * @throws \moodle_exception When the record is not a record of the activity (invalidpendingrecord).
     */
    public static function get_log_record(int $cmid, int $logid): \stdClass {
        $access = activity_access::require_access($cmid, self::CAPABILITY);
        return activity_access::require_pending_record($access, $logid);
    }

    /**
     * Re-queues the AI review of a record of the activity.
     *
     * An approved record is kept and a new queued attempt is created; any other record is
     * re-queued in place with its automatic retry counter reset.
     *
     * @param int $cmid Course module id of the assign activity.
     * @param int $pendingid Pending record id.
     * @return void
     * @throws \moodle_exception When the record is not a record of the activity (invalidpendingrecord).
     */
    public static function retry_review(int $cmid, int $pendingid): void {
        global $USER;

        $access = activity_access::require_access($cmid, self::CAPABILITY);
        $record = activity_access::require_pending_record($access, $pendingid);
        $courseid = (int) $access->course->id;
        $reviewerid = (int) $USER->id;

        if ((string) $record->status === assign_submission::STATUS_APPROVED) {
            // Do not replace a successful record: create a new attempt as a new log row.
            $newid = assign_submission::create_pending_submission((object) [
                'courseid' => $record->courseid,
                'assignmentid' => $record->assignmentid,
                'userid' => $record->userid,
                'submissionid' => $record->submissionid,
                'attemptnumber' => $record->attemptnumber,
                'submissionmodified' => $record->submissionmodified,
                'title' => $record->title,
                'message' => null,
                'grade' => null,
                'status' => assign_submission::STATUS_QUEUED,
            ]);
            assign_submission::queue_ai_review($cmid, $courseid, (int) $record->userid, $newid, false, $reviewerid);
            return;
        }

        // Failed (or any other) record: re-queue the same row (manual retry resets the counter).
        assign_submission::queue_ai_review($cmid, $courseid, (int) $record->userid, (int) $record->id, true, $reviewerid);
    }

    /**
     * Re-queues every failed AI review of the activity in one ad-hoc task.
     *
     * The task carries the current user as reviewer, so each transfer to the AI service is
     * logged with the teacher who requested the retry as actor (LAA-AUD-011).
     *
     * @param int $cmid Course module id of the assign activity.
     * @return int Number of re-queued records.
     */
    public static function retry_all_failed(int $cmid): int {
        global $DB, $USER;

        $access = activity_access::require_access($cmid, self::CAPABILITY);
        $failed = $DB->get_records('local_assign_ai_pending', [
            'courseid' => (int) $access->cm->course,
            'assignmentid' => (int) $access->cm->id,
            'status' => assign_submission::STATUS_FAILED,
        ]);
        if (empty($failed)) {
            return 0;
        }

        foreach ($failed as $record) {
            // Refresh timemodified (so the reaper won't re-fail them) and reset the auto-retry counter.
            assign_submission::update_pending_submission((int) $record->id, [
                'status' => assign_submission::STATUS_QUEUED,
                'retries' => 0,
            ]);
        }

        $task = new \local_assign_ai\task\process_all_submissions();
        $task->set_custom_data([
            'cmid' => (int) $access->cm->id,
            'courseid' => (int) $access->cm->course,
            'pendingcount' => count($failed),
            'reviewerid' => (int) $USER->id,
        ]);
        \core\task\manager::queue_adhoc_task($task);

        return count($failed);
    }
}
