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

namespace local_assign_ai\observer;

defined('MOODLE_INTERNAL') || die();

use mod_assign\event\submission_created;
use mod_assign\event\submission_updated;
use mod_assign\event\submission_removed;
use mod_assign\event\submission_graded;
use mod_assign\event\assessable_submitted;
use local_assign_ai\task\process_submission_ai;
use local_assign_ai\config\assignment_config;
use local_assign_ai\pending\manager as pending_manager;

require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Observer for submission events.
 *
 * @package    local_assign_ai
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submission {
    /**
     * Resolve submission user id from an event.
     *
     * Some assign events (for example assessable_submitted) may not always set
     * relateduserid when the actor is the submitting student.
     *
     * @param \core\event\base $event Event instance.
     * @return int|null
     */
    private static function resolve_userid(\core\event\base $event): ?int {
        global $DB;

        $data = $event->get_data();
        if (!empty($data['relateduserid'])) {
            return (int) $data['relateduserid'];
        }

        $submissionid = isset($data['objectid']) ? (int) $data['objectid'] : 0;
        if ($submissionid <= 0) {
            return null;
        }

        $userid = $DB->get_field('assign_submission', 'userid', ['id' => $submissionid]);
        if (empty($userid)) {
            return null;
        }

        return (int) $userid;
    }

    /**
     * Remove queued submission tasks for a specific user in an assignment.
     *
     * @param int $userid User id.
     * @param int $cmid Course module id.
     * @return void
     */
    private static function delete_submission_queue(int $userid, int $cmid): void {
        global $DB;

        $useridlike1 = '%"userid":' . $userid . '%';
        $useridlike2 = '%"userid":"' . $userid . '"%';
        $cmidlike1 = '%"cmid":' . $cmid . '%';
        $cmidlike2 = '%"cmid":"' . $cmid . '"%';

        $sql = "DELETE FROM {local_assign_ai_queue}
                WHERE type = 'submission'
                  AND (payload LIKE ? OR payload LIKE ?)
                  AND (payload LIKE ? OR payload LIKE ?)";

        $DB->execute($sql, [$useridlike1, $useridlike2, $cmidlike1, $cmidlike2]);
    }

    /**
     * Queue processing for a submission, respecting delay configuration.
     *
     * @param int $userid User id.
     * @param int $cmid Course module id.
     * @param \stdClass $config Effective assignment config.
     * @param int|null $submissiontime Unix timestamp for the submission event.
     * @return void
     */
    private static function enqueue_submission_processing(
        int $userid,
        int $cmid,
        \stdClass $config,
        ?int $submissiontime = null
    ): void {
        global $DB;

        $submissiontime = ($submissiontime !== null && $submissiontime > 0)
            ? (int) $submissiontime
            : time();

        $taskdata = (object) [
            'userid' => $userid,
            'cmid' => $cmid,
            'submissiontime' => $submissiontime,
        ];

        self::delete_submission_queue($userid, $cmid);

        if (!empty($config->usedelay)) {
            $delay = max(1, (int) $config->delayminutes);
            $timetoprocess = $submissiontime + ($delay * 60);

            $DB->insert_record('local_assign_ai_queue', (object) [
                'type' => 'submission',
                'payload' => json_encode($taskdata),
                'timecreated' => time(),
                'timetoprocess' => $timetoprocess,
                'processed' => 0,
            ]);
            return;
        }

        $task = new process_submission_ai();
        $task->set_custom_data($taskdata);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Handles the submission created event.
     *
     * If the assignment is configured to auto‑approve AI feedback, this will
     * send the submission to the AI service for grading without teacher
     * intervention. Otherwise, it does nothing.
     *
     * @param submission_created $event The submission created event.
     * @return void
     */
    public static function submission_created(submission_created $event) {
        try {
            if (!assignment_config::is_feature_enabled()) {
                return;
            }

            $data = $event->get_data();
            $other = $data['other'];

            if ($other['submissionstatus'] !== ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
                return;
            }

            $assign = $event->get_assign();
            if (!$assign) {
                return;
            }

            $config = assignment_config::get_effective((int)$assign->get_instance()->id);
            if (empty($config->enableai)) {
                return;
            }

            $userid = self::resolve_userid($event);
            if (!$userid) {
                return;
            }

            $cmid = $assign->get_course_module()->id;
            $submission = $assign->get_user_submission((int) $userid, true);
            $submissiontime = time();
            if ($submission && $submission->status === ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
                $submissiontime = max((int) $submission->timecreated, (int) $submission->timemodified);
            }

            self::enqueue_submission_processing((int) $userid, (int) $cmid, $config, $submissiontime);
        } catch (\Exception $e) {
            debugging('Exception in submission_created observer: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Queues AI processing when a submitted submission is edited (no-drafts assignments).
     *
     * Per-attempt deduplication and the superseding of previous attempts are handled later in
     * {@see \local_assign_ai\assign_submission::upsert_attempt_record()}, keyed by submissionid, so
     * this observer only needs to enqueue processing for the current attempt.
     *
     * @param submission_updated $event The submission updated event.
     * @return void
     */
    public static function submission_updated(submission_updated $event) {
        try {
            if (!assignment_config::is_feature_enabled()) {
                return;
            }

            $assign = $event->get_assign();
            if (!$assign) {
                return;
            }

            if (!empty($assign->get_instance()->submissiondrafts)) {
                return;
            }

            $data = $event->get_data();
            $other = $data['other'] ?? [];
            if (($other['submissionstatus'] ?? null) !== ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
                return;
            }

            $userid = self::resolve_userid($event);
            if (!$userid) {
                return;
            }

            $config = assignment_config::get_effective((int)$assign->get_instance()->id);
            if (empty($config->enableai)) {
                return;
            }

            $submission = $assign->get_user_submission($userid, true);
            $cmid = $assign->get_course_module()->id;
            $submissiontime = $submission
                ? max((int) $submission->timecreated, (int) $submission->timemodified)
                : time();

            self::enqueue_submission_processing((int) $userid, (int) $cmid, $config, $submissiontime);
        } catch (\Exception $e) {
            debugging('Exception in submission_updated observer: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Syncs approved AI records when a teacher updates grading.
     *
     * @param submission_graded $event The submission graded event.
     * @return void
     */
    public static function submission_graded(submission_graded $event) {
        global $DB, $USER;

        try {
            $assign = $event->get_assign();
            if (!$assign) {
                return;
            }

            $data = $event->get_data();
            $userid = $data['relateduserid'] ?? null;
            if (!$userid) {
                return;
            }

            $cmid = $assign->get_course_module()->id;
            $record = pending_manager::get_latest_record($cmid, $userid);

            if (!$record) {
                return;
            }

            if ($record->status !== \local_assign_ai\assign_submission::STATUS_APPROVED) {
                return;
            }

            $grade = $assign->get_user_grade($userid, true);
            if (!$grade) {
                return;
            }

            $gradingmanager = get_grading_manager($assign->get_context(), 'mod_assign', 'submissions');
            pending_manager::sync_after_grading(
                $assign,
                $record,
                $grade,
                $gradingmanager,
                $USER->id ?? $record->usermodified
            );
        } catch (\Exception $e) {
            debugging('Exception in submission_graded observer: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Handles the submission_removed event when a student deletes their submission.
     *
     * This is the ONLY place AI feedback is deleted, and it is scoped to the specific submission
     * attempt that was removed (by submissionid), so AI feedback from other attempts is kept as
     * history. Reopening an assignment does NOT fire this event, so prior attempts are preserved.
     *
     * @param submission_removed $event The submission removed event.
     * @return void
     */
    public static function submission_removed(submission_removed $event) {
        global $DB;

        try {
            $data = $event->get_data();
            $submissionid = (int) ($data['other']['submissionid'] ?? $data['objectid'] ?? 0);
            if ($submissionid <= 0) {
                return;
            }

            // Remove only the AI feedback tied to this specific submission attempt.
            $DB->delete_records('local_assign_ai_pending', ['submissionid' => $submissionid]);

            // Clean any queued processing for this user in this assignment.
            $assign = $event->get_assign();
            $userid = self::resolve_userid($event);
            if ($assign && $userid) {
                self::delete_submission_queue((int) $userid, (int) $assign->get_course_module()->id);
            }
        } catch (\Exception $e) {
            debugging('Exception in submission_removed observer: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Handles final student submission event when drafts are enabled.
     *
     * @param assessable_submitted $event The assessable submitted event.
     * @return void
     */
    public static function assessable_submitted(assessable_submitted $event) {
        try {
            if (!assignment_config::is_feature_enabled()) {
                return;
            }

            $assign = $event->get_assign();
            if (!$assign) {
                return;
            }

            if (empty($assign->get_instance()->submissiondrafts)) {
                return;
            }

            $config = assignment_config::get_effective((int) $assign->get_instance()->id);
            if (empty($config->enableai)) {
                return;
            }

            $userid = self::resolve_userid($event);
            if (!$userid) {
                return;
            }

            $submission = $assign->get_user_submission($userid, true);
            if (!$submission || $submission->status !== ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
                return;
            }

            $cmid = $assign->get_course_module()->id;
            $submissiontime = max((int) $submission->timecreated, (int) $submission->timemodified);
            self::enqueue_submission_processing((int) $userid, (int) $cmid, $config, $submissiontime);
        } catch (\Exception $e) {
            debugging('Exception in assessable_submitted observer: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
