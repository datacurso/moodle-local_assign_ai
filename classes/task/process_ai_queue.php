<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_assign_ai\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/assign/locallib.php');


use local_assign_ai\config\assignment_config;

/**
 * Scheduled task to process delayed AI queue for assignments.
 *
 * @package    local_assign_ai
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_ai_queue extends \core\task\scheduled_task {
    /**
     * Return the task name shown in admin screens.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_process_ai_queue', 'local_assign_ai');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        $now = time();

        // Get pending items whose time has arrived.
        $items = $DB->get_records_select(
            'local_assign_ai_queue',
            'processed = 0 AND timetoprocess <= ?',
            [$now],
            'timetoprocess ASC',
            '*',
            0,
            20
        );

        foreach ($items as $item) {
            $data = json_decode($item->payload);

            try {
                if ($item->type === 'submission') {
                    $userid = (int)($data->userid ?? 0);
                    $cmid = (int)($data->cmid ?? 0);

                    if ($userid <= 0 || $cmid <= 0) {
                        $item->processed = 1;
                        $DB->update_record('local_assign_ai_queue', $item);
                        continue;
                    }

                    $cm = get_coursemodule_from_id('assign', $cmid, 0, false, IGNORE_MISSING);
                    if (!$cm) {
                        $item->processed = 1;
                        $DB->update_record('local_assign_ai_queue', $item);
                        continue;
                    }

                    $course = get_course($cm->course);
                    $context = \context_module::instance($cmid);
                    $assign = new \assign($context, $cm, $course);

                    // The switch may have been turned off (site, tenant or assignment) after the row was queued:
                    // drop the row instead of turning it into a record.
                    if (!assignment_config::is_ai_enabled_for_assignment((int)$assign->get_instance()->id)) {
                        $DB->delete_records('local_assign_ai_queue', ['id' => $item->id]);
                        continue;
                    }
                    $config = assignment_config::get_effective((int)$assign->get_instance()->id);

                    $submission = $assign->get_user_submission($userid, false);
                    if (!$submission || $submission->status !== ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
                        $item->processed = 1;
                        $DB->update_record('local_assign_ai_queue', $item);
                        continue;
                    }

                    if (!empty($config->usedelay)) {
                        $submissiontime = max((int)$submission->timecreated, (int)$submission->timemodified);
                        $requiredtime = $submissiontime + (max(1, (int)$config->delayminutes) * 60);

                        if ($now < $requiredtime) {
                            if ((int)$item->timetoprocess !== (int)$requiredtime) {
                                $item->timetoprocess = (int)$requiredtime;
                                $DB->update_record('local_assign_ai_queue', $item);
                            }
                            continue;
                        }
                    }

                    $submissionprocessor = new \local_assign_ai\assign_submission($userid, $assign);
                    $submissionprocessor->process_submission_ai();
                }

                $item->processed = 1;
                $DB->update_record('local_assign_ai_queue', $item);
            } catch (\Throwable $e) {
                debugging('Error processing Assign AI queue: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }
}
