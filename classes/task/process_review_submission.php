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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/assign/locallib.php');

use core\task\adhoc_task;
use local_assign_ai\assign_submission;

/**
 * Ad-hoc task to review a single submission with AI ("Review" button per row).
 *
 * Mirrors the per-record logic of {@see process_all_submissions} but for a single
 * pending record, so the individual review runs asynchronously like "Review all".
 *
 * @package     local_assign_ai
 * @category    task
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_review_submission extends adhoc_task {
    /**
     * Executes the queued ad-hoc task.
     *
     * Expected custom data:
     *  - cmid (int)
     *  - courseid (int)
     *  - userid (int)
     *  - pendingid (int)
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        if (empty($data->cmid) || empty($data->userid) || empty($data->pendingid)) {
            return;
        }

        $cm = get_coursemodule_from_id('assign', $data->cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $assign = new \assign($context, $cm, $course);

        try {
            // Skip if the record is no longer queued (e.g. it was cancelled in the meantime).
            $current = $DB->get_record(
                'local_assign_ai_pending',
                ['id' => (int) $data->pendingid],
                'id, status, submissionid, assignmentid, courseid, userid'
            );
            if (!$current || (string) $current->status !== assign_submission::STATUS_QUEUED) {
                mtrace('Assign AI: skipping pending ' . $data->pendingid . ' (no longer queued).');
                return;
            }

            // Defence in depth: never process a record that does not match the task's own
            // activity, course and user (guards against a task queued with tampered data or
            // a record whose ownership changed after it was queued).
            if (
                (int) $current->assignmentid !== (int) $cm->id
                || (int) $current->courseid !== (int) $course->id
                || (int) $current->userid !== (int) $data->userid
            ) {
                mtrace('Assign AI: skipping pending ' . $data->pendingid . ' (context mismatch).');
                return;
            }

            // Move to processing state so the UI reflects progress.
            assign_submission::update_pending_submission((int) $data->pendingid, [
                'status' => assign_submission::STATUS_PROCESSING,
            ]);

            // Review the attempt this record belongs to (not necessarily the latest submission).
            $targetsid = $current->submissionid ? (int) $current->submissionid : null;
            $proc = new assign_submission((int) $data->userid, $assign, $targetsid);
            $proc->process_submission_ai_review((int) $data->pendingid);
        } catch (\Throwable $e) {
            // Log the failure (marks the record as failed) so it shows in the AI history report.
            assign_submission::register_failure($e, (int) $data->pendingid);
            mtrace('Assign AI: error reviewing pending ' . $data->pendingid . ': ' . $e->getMessage());
        }
    }
}
