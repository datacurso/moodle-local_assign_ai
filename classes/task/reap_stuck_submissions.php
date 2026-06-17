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
 * Scheduled task that fails AI reviews stuck in queued/processing for too long.
 *
 * A submission can stay in 'processing' forever when the AI request hangs and the
 * cron worker dies without throwing a catchable exception. This task marks such
 * records as failed so they show up in the history log and can be retried.
 *
 * @package    local_assign_ai
 * @copyright  2025 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reap_stuck_submissions extends \core\task\scheduled_task {
    /** @var int Minutes after which a queued/processing record is considered stuck. */
    private const STUCK_MINUTES = 15;

    /**
     * Return the task name shown in admin screens.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_reap_stuck', 'local_assign_ai');
    }

    /**
     * Mark stuck queued/processing records as failed.
     */
    public function execute() {
        global $DB;

        $threshold = time() - (self::STUCK_MINUTES * 60);

        [$insql, $params] = $DB->get_in_or_equal([
            assign_submission::STATUS_QUEUED,
            assign_submission::STATUS_PROCESSING,
        ], SQL_PARAMS_NAMED, 'st');
        $params['threshold'] = $threshold;

        $stuck = $DB->get_records_select(
            'local_assign_ai_pending',
            "status {$insql} AND timemodified < :threshold",
            $params
        );

        if (!$stuck) {
            return;
        }

        $message = get_string('error_processing_timeout', 'local_assign_ai');
        $count = 0;
        foreach ($stuck as $record) {
            // update_pending_submission refreshes timemodified, so it won't be reaped again.
            assign_submission::update_pending_submission((int) $record->id, [
                'status' => assign_submission::STATUS_FAILED,
                'errormessage' => $message,
            ]);
            $count++;
        }

        mtrace("local_assign_ai: marked {$count} stuck AI review(s) as failed.");
    }
}
