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

namespace local_assign_ai\task;

use local_assign_ai\assign_submission;

/**
 * Scheduled task that automatically re-queues failed AI reviews.
 *
 * Self-heals transient failures (network, cron hiccups, provider timeouts). A per-record
 * retry counter caps the number of automatic attempts so permanent failures don't loop.
 *
 * @package    local_assign_ai
 * @copyright  2025 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class retry_failed_submissions extends \core\task\scheduled_task {
    /** @var int Maximum automatic retries before leaving a record as failed. */
    private const MAX_RETRIES = 3;

    /**
     * Return the task name shown in admin screens.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_retry_failed', 'local_assign_ai');
    }

    /**
     * Re-queue failed reviews that still have automatic retries left.
     */
    public function execute() {
        global $DB;

        $failed = $DB->get_records_select(
            'local_assign_ai_pending',
            'status = :status AND retries < :max',
            ['status' => assign_submission::STATUS_FAILED, 'max' => self::MAX_RETRIES]
        );

        if (!$failed) {
            return;
        }

        $count = 0;
        foreach ($failed as $record) {
            $cm = get_coursemodule_from_id('assign', $record->assignmentid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }

            // Increment the automatic retry counter, then re-queue through the normal review path.
            assign_submission::update_pending_submission((int) $record->id, [
                'retries' => (int) $record->retries + 1,
            ]);
            assign_submission::queue_ai_review(
                (int) $cm->id,
                (int) $record->courseid,
                (int) $record->userid,
                (int) $record->id
            );
            $count++;
        }

        mtrace("local_assign_ai: auto-retried {$count} failed AI review(s).");
    }
}
