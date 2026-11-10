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


use aiprovider_datacurso\local\plugin_settings;
use local_assign_ai\config\assignment_config;
use local_assign_ai\local\tenant_context;

/**
 * Scheduled task to process delayed AI queue for assignments.
 *
 * @package    local_assign_ai
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_ai_queue extends \core\task\scheduled_task {
    /** @var int Maximum number of queued submissions handled per run. */
    private const BATCH_SIZE = 20;

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
     *
     * Handles up to BATCH_SIZE due submissions per run. Assign AI is enabled per tenant in the Datacurso AI
     * provider and the switch always follows the tenant of the student the row belongs to (never the course's
     * tenant nor the cron user): the row of a student whose tenant disabled Assign AI is deleted, is not sent to
     * the AI and does not count towards the batch.
     *
     * Due rows are read in bounded pages of BATCH_SIZE, walking forwards by (timetoprocess, id), so every row is
     * looked at once per run at most: deleted rows of disabled tenants cannot starve the batch of the other
     * tenants, and the task never reads the whole queue again and again.
     */
    public function execute() {
        global $DB;

        $now = time();
        $handled = 0;
        $lasttime = 0;
        $lastid = 0;

        while ($handled < self::BATCH_SIZE) {
            // Get the next page of pending items whose time has arrived.
            $items = $DB->get_records_select(
                'local_assign_ai_queue',
                'processed = 0 AND timetoprocess <= :now
                 AND (timetoprocess > :lasttime OR (timetoprocess = :sametime AND id > :lastid))',
                ['now' => $now, 'lasttime' => $lasttime, 'sametime' => $lasttime, 'lastid' => $lastid],
                'timetoprocess ASC, id ASC',
                '*',
                0,
                self::BATCH_SIZE
            );
            if (!$items) {
                break;
            }

            foreach ($items as $item) {
                if ($handled >= self::BATCH_SIZE) {
                    break 2;
                }
                $lasttime = (int)$item->timetoprocess;
                $lastid = (int)$item->id;

                if ($this->process_item($item, $now)) {
                    $handled++;
                }
            }
        }
    }

    /**
     * Process one queued row.
     *
     * @param \stdClass $item Row of local_assign_ai_queue.
     * @param int $now Time the run started.
     * @return bool True when the row counts towards the batch.
     */
    private function process_item(\stdClass $item, int $now): bool {
        global $DB;

        $data = json_decode($item->payload);
        $counted = false;

        try {
            if ($item->type === 'submission') {
                $userid = (int)($data->userid ?? 0);
                $cmid = (int)($data->cmid ?? 0);

                if ($userid <= 0 || $cmid <= 0) {
                    $item->processed = 1;
                    $DB->update_record('local_assign_ai_queue', $item);
                    return false;
                }

                $cm = get_coursemodule_from_id('assign', $cmid, 0, false, IGNORE_MISSING);
                if (!$cm) {
                    $item->processed = 1;
                    $DB->update_record('local_assign_ai_queue', $item);
                    return false;
                }

                // The provider switch follows the tenant of the student, never the course's tenant nor the cron
                // user. When that tenant disabled Assign AI, drop the row: it is never sent to the AI.
                if (!plugin_settings::is_enabled('local_assign_ai', tenant_context::get_tenant_id_for_user($userid))) {
                    $DB->delete_records('local_assign_ai_queue', ['id' => $item->id]);
                    return false;
                }
                $counted = true;

                $course = get_course($cm->course);
                $context = \context_module::instance($cmid);
                $assign = new \assign($context, $cm, $course);

                // The switch may have been turned off (site, tenant or assignment) after the row was queued:
                // drop the row instead of turning it into a record.
                if (!assignment_config::is_ai_enabled_for_assignment((int)$assign->get_instance()->id, $userid)) {
                    $DB->delete_records('local_assign_ai_queue', ['id' => $item->id]);
                    return true;
                }
                $config = assignment_config::get_effective((int)$assign->get_instance()->id);

                $submission = $assign->get_user_submission($userid, false);
                if (!$submission || $submission->status !== ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
                    $item->processed = 1;
                    $DB->update_record('local_assign_ai_queue', $item);
                    return true;
                }

                if (!empty($config->usedelay)) {
                    $submissiontime = max((int)$submission->timecreated, (int)$submission->timemodified);
                    $requiredtime = $submissiontime + (max(1, (int)$config->delayminutes) * 60);

                    if ($now < $requiredtime) {
                        if ((int)$item->timetoprocess !== (int)$requiredtime) {
                            $item->timetoprocess = (int)$requiredtime;
                            $DB->update_record('local_assign_ai_queue', $item);
                        }
                        return true;
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

        return $counted;
    }
}
