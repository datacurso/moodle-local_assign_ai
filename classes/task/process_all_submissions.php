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
require_once($CFG->libdir . '/externallib.php');

use core\task\adhoc_task;
use local_assign_ai\api\client;

/**
 * Ad-hoc task for processing all assignment submissions with AI.
 *
 * This task is automatically queued by the external function
 * when the teacher chooses to review all submissions. It processes
 * each student's submission asynchronously using the AI grading service.
 *
 * @package     local_assign_ai
 * @category    task
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_all_submissions extends adhoc_task {
    /**
     * Executes the queued ad-hoc task.
     *
     * This function is automatically executed by Moodle's task manager.
     * It validates the required parameters (course ID and cmid), loads
     * all enrolled users who can submit assignments, and sends each one
     * to the AI processing service.
     *
     * @return void
     */
    public function execute() {
        global $DB, $CFG;

        $data = $this->get_custom_data();

        // Validate required task parameters.
        if (empty($data->cmid) || empty($data->courseid)) {
            mtrace(get_string('missingtaskparams', 'local_assign_ai'));
            return;
        }

        $cm = get_coursemodule_from_id('assign', $data->cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $data->courseid], '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $assign = new \assign($context, $cm, $course);

        mtrace(get_string('aitaskstart', 'local_assign_ai', $course->fullname));

        $processed = 0;

        // Obtain records enqueued to review (status = queued) for this assignment.
        $pendings = $DB->get_records('local_assign_ai_pending', [
            'courseid' => $course->id,
            'assignmentid' => $cm->id,
            'status' => \local_assign_ai\assign_submission::STATUS_QUEUED,
        ]);

        foreach ($pendings as $pending) {
            try {
                // Skip if it is no longer queued (e.g. cancelled in the meantime).
                $current = $DB->get_record('local_assign_ai_pending', ['id' => $pending->id], 'id, status');
                if (!$current || (string) $current->status !== \local_assign_ai\assign_submission::STATUS_QUEUED) {
                    mtrace('Assign AI: skipping pending ' . $pending->id . ' (no longer queued).');
                    continue;
                }

                // Move to processing state.
                \local_assign_ai\assign_submission::update_pending_submission($pending->id, [
                    'status' => \local_assign_ai\assign_submission::STATUS_PROCESSING,
                ]);
                $proc = new \local_assign_ai\assign_submission(
                    $pending->userid,
                    $assign,
                    !empty($pending->submissionid) ? (int) $pending->submissionid : null
                );
                $proc->process_submission_ai_review($pending->id);

                $processed++;
                $params = [
                    'id' => $pending->userid,
                    'name' => $pending->userid,
                ];
                mtrace(get_string('aitaskuserqueued', 'local_assign_ai', $params));
            } catch (\Throwable $e) {
                // Log the failure (marks the record as failed) and keep processing the rest.
                \local_assign_ai\assign_submission::register_failure($e, (int) $pending->id);
                mtrace('Assign AI: error processing pending ' . $pending->id . ': ' . $e->getMessage());
            }
        }

        mtrace(get_string('aitaskdone', 'local_assign_ai', $processed));
    }
}
