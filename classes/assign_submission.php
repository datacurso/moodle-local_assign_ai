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

namespace local_assign_ai;

use local_assign_ai\api\client;
use local_assign_ai\config\assignment_config;
use local_assign_ai\grading\advanced_grading;
use local_assign_ai\grading\feedback_applier;
use stdClass;

/**
 * Class assign_submission
 *
 * @package    local_assign_ai
 * @copyright  2025 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_submission {
    /** Initial status before processing with AI. */
    public const STATUS_INITIAL = 'initial';
    /** Pending status awaiting human review. */
    public const STATUS_PENDING = 'pending';
    /** Queued status when review-all has been scheduled. */
    public const STATUS_QUEUED = 'queued';
    /** Processing status when ad-hoc task is handling the record. */
    public const STATUS_PROCESSING = 'processing';
    /** Approved status after human review or AI grading. */
    public const STATUS_APPROVED = 'approve';
    /** Rejected status after human review. */
    public const STATUS_REJECTED = 'rejected';
    /** Failed status when AI processing raised an error. */
    public const STATUS_FAILED = 'failed';
    /** Superseded status: feedback from a previous attempt, kept as read-only history. */
    public const STATUS_SUPERSEDED = 'superseded';

    /**
     * Statuses considered "active" (still part of the review pipeline, not yet frozen as history).
     *
     * When a newer attempt is processed, active records from previous attempts are moved to
     * STATUS_SUPERSEDED so that only the latest attempt remains reviewable.
     */
    private const ACTIVE_STATUSES = [
        self::STATUS_INITIAL,
        self::STATUS_PENDING,
        self::STATUS_QUEUED,
        self::STATUS_PROCESSING,
        self::STATUS_FAILED,
    ];

    /**
     * Final statuses: a teacher decision has been recorded. These records are kept as history and
     * are never overwritten by a re-evaluation (e.g. when the student edits the same submission).
     */
    private const FINAL_STATUSES = [
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    /** @var stdClass User ID of the author of the submission. */
    private stdClass $user;

    /**
     * @var int|null Teacher (grader) who consumes the AI review. Drives the consumption/rate-limit
     * userid so the limit is per-teacher, not per-student. Null for automatic flows (autograde),
     * where build_payload falls back to the configured graderid.
     */
    private ?int $reviewerid = null;

    /** @var stdClass Submission record from {assign_submission}. */
    private stdClass|false $submission;

    /** @var \assign Assign instance */
    private \assign $assign;

    /** @var stdClass Assignment instance */
    private stdClass $assigninstance;

    /** @var stdClass Course instance */
    private stdClass $course;

    /**
     * Constructor.
     *
     * @param int $userid User ID of the author of the submission.
     * @param \assign $assign Assig instance.
     * @param int|null $submissionid Specific submission attempt to target. When provided, the AI
     *                               review/grading runs against that exact attempt instead of the
     *                               student's latest submission (used by per-attempt reviews/retries).
     */
    public function __construct(int $userid, \assign $assign, ?int $submissionid = null) {
        global $DB;
        $this->assign = $assign;
        $this->user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0, 'suspended' => 0], '*', MUST_EXIST);

        $this->submission = false;
        if (!empty($submissionid)) {
            // Target the specific attempt this AI record belongs to.
            $this->submission = $DB->get_record('assign_submission', ['id' => $submissionid, 'userid' => $userid]);
        }
        if (!$this->submission) {
            // Fall back to the latest submission (new submissions, or legacy records without submissionid).
            $this->submission = $assign->get_user_submission($userid, false);
        }

        $this->assigninstance = $assign->get_instance();
        $this->course = $assign->get_course();
    }

    /**
     * Set the teacher (reviewer) who triggered this AI review, so the consumption/rate limit is
     * attributed to the teacher instead of the student. Used by the manual-review tasks.
     *
     * @param int|null $reviewerid Teacher user id (null keeps the graderid/student fallback).
     */
    public function set_reviewerid(?int $reviewerid): void {
        $this->reviewerid = $reviewerid ?: null;
    }

    /**
     * Processes a submission against AI logic depending on autograde configuration.
     *
     * When autograde is enabled, sends the payload to the AI provider, stores the
     * pending record with the AI response, and applies the feedback (grade/comments).
     * Otherwise, only creates a pending record for later manual review.
     *
     * @return void
     */
    public function process_submission_ai(): void {
        global $DB;

        if (!$this->submission || !$this->user) {
            return;
        }

        // Only process submitted attempts.
        if ($this->submission->status !== ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
            return;
        }

        $assignment = $this->assigninstance;
        $cmid = $this->assign->get_course_module()->id;
        $config = assignment_config::get_effective((int)$assignment->id);

        if (empty($config->enableai)) {
            return;
        }

        if (!assignment_config::is_autograde_enabled($this->assign)) {
            // Autograde disabled: create a basic pending record for teacher review later.
            $record = (object) [
                'courseid' => $this->course->id,
                'assignmentid' => $cmid,
                'userid' => $this->user->id,
                'submissionid' => (int) $this->submission->id,
                'attemptnumber' => (int) $this->submission->attemptnumber,
                'submissionmodified' => (int) $this->submission->timemodified,
                'title' => $assignment->name,
                'message' => null,
                'grade' => null,
                'rubric_response' => null,
                'status' => self::STATUS_INITIAL,
            ];
            self::upsert_attempt_record($record);
            return;
        }

        // Capture any error across the whole pipeline (payload/files, AI call, response
        // parsing, record creation and feedback application) into the log.
        $recordid = null;
        try {
            $payload = $this->build_payload();
            $response = client::send_to_ai($payload);

            $message = $response['reply'] ?? null;
            $grade = isset($response['grade']) ? (is_numeric($response['grade']) ? (float) $response['grade'] : null) : null;

            // Determine correct advanced grading response (rubric or assessment_guide).
            $rawadvanced = !empty($response['rubric']) ? $response['rubric'] : ($response['assessment_guide'] ?? null);
            $rubricresponse = null;
            $assessmentguideresponse = null;

            if ($rawadvanced) {
                $advanceddata = $rawadvanced;
                if (is_array($rawadvanced) && isset($rawadvanced['criteria'])) {
                    $advanceddata = $rawadvanced['criteria'];
                }
                $jsonresponse = json_encode($advanceddata, JSON_UNESCAPED_UNICODE);

                if (!empty($response['rubric'])) {
                    $rubricresponse = $jsonresponse;
                } else {
                    $assessmentguideresponse = $jsonresponse;
                }
            }

            $record = (object) [
                'courseid' => $this->course->id,
                'assignmentid' => $cmid,
                'userid' => $this->user->id,
                'submissionid' => (int) $this->submission->id,
                'attemptnumber' => (int) $this->submission->attemptnumber,
                'submissionmodified' => (int) $this->submission->timemodified,
                'title' => $assignment->name,
                'message' => $message,
                'grade' => $grade !== null ? (int) round($grade) : null,
                'rubric_response' => $rubricresponse,
                'assessment_guide_response' => $assessmentguideresponse,
                'status' => self::STATUS_APPROVED,
            ];
            $recordid = self::upsert_attempt_record($record);

            $record = $DB->get_record('local_assign_ai_pending', ['id' => $recordid]);
            $config = assignment_config::get($this->assigninstance->id);
            if ($record && !empty($config) && !empty($config->graderid)) {
                feedback_applier::apply_ai_feedback($this->assign, $record, $config->graderid);
            }
        } catch (\Throwable $e) {
            // If a record was already created, mark it failed; otherwise create a failed one.
            self::register_failure($e, $recordid, $recordid ? null : (object) [
                'courseid' => $this->course->id,
                'assignmentid' => $cmid,
                'userid' => $this->user->id,
                'submissionid' => (int) $this->submission->id,
                'attemptnumber' => (int) $this->submission->attemptnumber,
                'submissionmodified' => (int) $this->submission->timemodified,
                'title' => $assignment->name,
                'message' => null,
                'grade' => null,
            ]);
        }
    }

    /**
     * Processes a submission for the "Review with AI" action.
     *
     * Always sends the submission payload to the AI provider (regardless of
     * autograde setting) and stores the AI response in the pending table with
     * status set to STATUS_PENDING for manual review/approval.
     *
     * Requirements:
     *  - The user must have a submission with status ASSIGN_SUBMISSION_STATUS_SUBMITTED.
     *
     * @param int $pendingid Pending record ID to update in local_assign_ai_pending.
     * @return void
     */
    public function process_submission_ai_review(int $pendingid): void {
        global $DB;

        // Find existing pending record to update.
        $existing = $DB->get_record('local_assign_ai_pending', ['id' => $pendingid], '*', MUST_EXIST);

        // Guard checks: when the review cannot proceed, mark the record as failed (with a reason)
        // instead of leaving it stuck in "processing" forever (which shows ~99% in the UI).
        if (!$this->submission || !$this->user) {
            self::update_pending_submission($pendingid, [
                'status' => self::STATUS_FAILED,
                'errormessage' => get_string('reviewnotsubmitted', 'local_assign_ai'),
            ]);
            return;
        }

        $config = assignment_config::get_effective((int)$this->assigninstance->id);
        if (empty($config->enableai)) {
            self::update_pending_submission($pendingid, [
                'status' => self::STATUS_FAILED,
                'errormessage' => get_string('reviewaidisabled', 'local_assign_ai'),
            ]);
            return;
        }

        if ($this->submission->status !== ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
            self::update_pending_submission($pendingid, [
                'status' => self::STATUS_FAILED,
                'errormessage' => get_string('reviewnotsubmitted', 'local_assign_ai'),
            ]);
            return;
        }

        // Capture any error across the whole pipeline (payload/files, AI call, response
        // parsing and record update) into the log.
        try {
            $payload = $this->build_payload();
            $response = client::send_to_ai($payload);

            $message = $response['reply'] ?? null;
            $grade = isset($response['grade']) ? (is_numeric($response['grade']) ? (float) $response['grade'] : null) : null;

            // Determine correct advanced grading response (rubric or assessment_guide).
            $rawadvanced = !empty($response['rubric']) ? $response['rubric'] : ($response['assessment_guide'] ?? null);
            $rubricresponse = null;
            $assessmentguideresponse = null;

            if ($rawadvanced) {
                $advanceddata = $rawadvanced;
                if (is_array($rawadvanced) && isset($rawadvanced['criteria'])) {
                    $advanceddata = $rawadvanced['criteria'];
                }
                $jsonresponse = json_encode($advanceddata, JSON_UNESCAPED_UNICODE);

                if (!empty($response['rubric'])) {
                    $rubricresponse = $jsonresponse;
                } else {
                    $assessmentguideresponse = $jsonresponse;
                }
            }

            $data = [
                'submissionid' => (int) $this->submission->id,
                'attemptnumber' => (int) $this->submission->attemptnumber,
                'submissionmodified' => (int) $this->submission->timemodified,
                'message' => $message,
                'grade' => $grade !== null ? (int) round($grade) : null,
                'rubric_response' => $rubricresponse,
                'assessment_guide_response' => $assessmentguideresponse,
                'status' => self::STATUS_PENDING,
                'errormessage' => null,
            ];
            self::update_pending_submission($existing->id, $data);
        } catch (\Throwable $e) {
            self::register_failure($e, $existing->id);
        }
    }

    /**
     * Create a pending AI submission record.
     *
     * Expects a record object with the following fields:
     *  - courseid (int): Course ID.
     *  - assignmentid (int): Assignment identifier. For consistency in this plugin this is the course module id (cmid).
     *  - userid (int): Author user ID.
     *  - title (string): Record title.
     *  - message (string|null): Optional message or AI feedback.
     *  - grade (int|null): Optional grade suggested by AI.
     *  - rubric_response (string|null): Optional rubric response JSON/text.
     *  - status (string|null): Optional status. If omitted, defaults to STATUS_PENDING.
     *
     * Additional fields are set automatically:
     *  - usermodified (int): ID of the user performing the operation.
     *  - timecreated (int): Creation timestamp.
     *  - timemodified (int): Modification timestamp.
     *
     * @param stdClass $record Pending record payload with the fields described above.
     * @return int New record ID.
     */
    public static function create_pending_submission(stdClass $record): int {
        global $DB, $USER;

        $transaction = $DB->start_delegated_transaction();
        try {
            $now = time();
            if (empty($record->approval_token)) {
                $record->approval_token = random_string(10);
            }
            $record->status = $record->status ?? self::STATUS_PENDING;
            $record->usermodified = $USER->id;
            $record->timecreated = $now;
            $record->timemodified = $now;

            $id = $DB->insert_record('local_assign_ai_pending', $record);
            $transaction->allow_commit();
            return $id;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    /**
     * Update an existing pending AI submission record.
     *
     * @param int $id Record ID.
     * @param array $data Data to update.
     * @return bool True on success, false on failure.
     */
    public static function update_pending_submission(int $id, array $data): bool {
        global $DB, $USER;

        $record = $DB->get_record('local_assign_ai_pending', ['id' => $id]);
        if (!$record) {
            return false;
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($data as $key => $value) {
                $record->$key = $value;
            }
            $record->timemodified = time();
            if (!isset($record->usermodified)) {
                $record->usermodified = $USER->id ?? null;
            }

            $ok = $DB->update_record('local_assign_ai_pending', $record);
            $transaction->allow_commit();
            return $ok;
        } catch (\Exception $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    /**
     * Insert or update the AI feedback record for a specific submission attempt.
     *
     * Records are kept per attempt, keyed by submissionid. Before persisting, any *active* records
     * belonging to OTHER attempts of the same student are moved to STATUS_SUPERSEDED so that only
     * the latest attempt stays reviewable while previous attempts remain as read-only history.
     *
     * @param stdClass $record Pending record payload. Must include assignmentid, userid and submissionid.
     * @return int Record id (existing one when updated, new one when inserted).
     */
    public static function upsert_attempt_record(stdClass $record): int {
        global $DB;

        $cmid = (int) $record->assignmentid;
        $userid = (int) $record->userid;
        $submissionid = (int) ($record->submissionid ?? 0);

        if ($submissionid > 0) {
            self::supersede_previous_attempts($cmid, $userid, $submissionid);

            // There may be several records for this submission (finalized ones kept as history).
            // Re-evaluate by updating the single active (unreviewed) record in place if present;
            // otherwise create a NEW entry so finalized feedback (approve/rejected) is preserved.
            $existing = $DB->get_records('local_assign_ai_pending', [
                'assignmentid' => $cmid,
                'userid' => $userid,
                'submissionid' => $submissionid,
            ], 'id DESC');
            $priormax = 0;
            foreach ($existing as $rec) {
                if (!in_array((string) $rec->status, self::FINAL_STATUSES, true)) {
                    self::update_pending_submission((int) $rec->id, (array) $record);
                    return (int) $rec->id;
                }
                $priormax = max($priormax, (int) $rec->submissionmodified);
            }

            // Creating a NEW entry while finalized evaluations exist: flag it as a student edit when
            // the submission was modified after the previous evaluation (vs a teacher retry).
            if ($priormax > 0 && (int) ($record->submissionmodified ?? 0) > $priormax) {
                $record->edited = 1;
            }
        }

        return self::create_pending_submission($record);
    }

    /**
     * Freeze active AI feedback records from previous attempts as read-only history.
     *
     * Records already in a final state (approve/rejected) are left untouched; only records still in
     * the active pipeline are moved to STATUS_SUPERSEDED. Legacy records without a submissionid are
     * treated as belonging to a previous attempt.
     *
     * @param int $cmid Assignment course module id (matches local_assign_ai_pending.assignmentid).
     * @param int $userid Student user id.
     * @param int $currentsubmissionid Submission id of the attempt being processed now.
     * @return void
     */
    public static function supersede_previous_attempts(int $cmid, int $userid, int $currentsubmissionid): void {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal(self::ACTIVE_STATUSES, SQL_PARAMS_NAMED, 'st');
        $params = array_merge($params, [
            'superseded' => self::STATUS_SUPERSEDED,
            'now' => time(),
            'cmid' => $cmid,
            'userid' => $userid,
            'cursid' => $currentsubmissionid,
        ]);

        $sql = "UPDATE {local_assign_ai_pending}
                   SET status = :superseded, timemodified = :now
                 WHERE assignmentid = :cmid
                   AND userid = :userid
                   AND status $insql
                   AND (submissionid IS NULL OR submissionid <> :cursid)";

        $DB->execute($sql, $params);
    }

    /**
     * Record a processing failure in the AI log.
     *
     * Always emits a developer debugging message and, when possible, persists the failure
     * to the pending record so it shows up in the AI history report (status = failed +
     * errormessage). This method never throws.
     *
     * @param \Throwable $e The error that occurred.
     * @param int|null $pendingid Existing pending record to mark as failed, if known.
     * @param stdClass|null $recorddata Data to create a failed record when none exists yet
     *                                  (courseid, assignmentid, userid, title, ...).
     * @return void
     */
    public static function register_failure(\Throwable $e, ?int $pendingid = null, ?stdClass $recorddata = null): void {
        debugging('local_assign_ai processing failure: ' . $e->getMessage(), DEBUG_DEVELOPER);

        try {
            if ($pendingid) {
                self::update_pending_submission($pendingid, [
                    'status' => self::STATUS_FAILED,
                    'errormessage' => $e->getMessage(),
                ]);
            } else if ($recorddata !== null) {
                $recorddata->status = self::STATUS_FAILED;
                $recorddata->errormessage = $e->getMessage();
                if (!empty($recorddata->submissionid)) {
                    self::upsert_attempt_record($recorddata);
                } else {
                    self::create_pending_submission($recorddata);
                }
            }
        } catch (\Throwable $inner) {
            debugging('local_assign_ai could not persist failure: ' . $inner->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Queue an AI review for a single pending record as an ad-hoc task.
     *
     * Marks the record as queued and schedules {@see \local_assign_ai\task\process_review_submission},
     * which moves it to processing and runs the review asynchronously.
     *
     * @param int $cmid Assignment course module id.
     * @param int $courseid Course id.
     * @param int $userid Student user id.
     * @param int $pendingid Pending record id to (re)process.
     * @param bool $resetretries When true, reset the auto-retry counter (manual retries).
     * @param int|null $reviewerid Teacher who triggered the review (consumption/rate-limit owner).
     * @return void
     */
    public static function queue_ai_review(
        int $cmid,
        int $courseid,
        int $userid,
        int $pendingid,
        bool $resetretries = false,
        ?int $reviewerid = null
    ): void {
        $update = ['status' => self::STATUS_QUEUED];
        if ($resetretries) {
            $update['retries'] = 0;
        }
        self::update_pending_submission($pendingid, $update);

        $task = new \local_assign_ai\task\process_review_submission();
        $task->set_custom_data([
            'cmid' => $cmid,
            'courseid' => $courseid,
            'userid' => $userid,
            'pendingid' => $pendingid,
            // Teacher who triggered the review (for per-teacher consumption/rate limit).
            'reviewerid' => $reviewerid,
        ]);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Retrieves the file attachments for the current submission encoded as base64.
     *
     * Reads files from the assignsubmission_file plugin area and returns them
     * ready to be included in the AI service payload.
     *
     * @return array Array of ['filename', 'mimetype', 'content_base64'] entries.
     */
    private function get_submission_files(): array {
        if (!$this->submission) {
            return [];
        }

        $fs = get_file_storage();
        $context = \context_module::instance($this->assign->get_course_module()->id);
        $files = $fs->get_area_files(
            $context->id,
            'assignsubmission_file',
            'submission_files',
            $this->submission->id,
            'filename',
            false
        );

        $result = [];
        foreach ($files as $file) {
            if ($file->get_filename() === '.') {
                continue;
            }
            $result[] = [
                'filename'       => $file->get_filename(),
                'mimetype'       => $file->get_mimetype(),
                'content_base64' => base64_encode($file->get_content()),
            ];
        }
        return $result;
    }

    /**
     * Retrieves the onlinetext content for a given submission.
     *
     * @param stdClass $submission Submission record from {assign_submission}.
     * @return string The submission text or empty string if none.
     */
    public static function get_submission_text(stdClass $submission): string {
        global $DB;
        $submissioncontent = '';
        $onlinetext = $DB->get_record('assignsubmission_onlinetext', ['submission' => $submission->id]);
        if ($onlinetext && !empty($onlinetext->onlinetext)) {
            $submissioncontent = $onlinetext->onlinetext;
        }
        return $submissioncontent;
    }

    /**
     * Build payload for AI service.
     *
     * @return array The payload array.
     */
    private function build_payload(): array {
        $course = $this->assign->get_course();
        $assignment = $this->assigninstance;
        $cmid = $this->assign->get_course_module()->id;
        $config = assignment_config::get_effective((int)$assignment->id);

        $advancedgrading = advanced_grading::get_definition_json($this->assign);
        $rubric = null;
        $assessmentguide = null;

        if ($advancedgrading) {
            if ($advancedgrading['method'] === 'rubric') {
                $rubric = $advancedgrading['data'];
            } else if ($advancedgrading['method'] === 'guide') {
                $assessmentguide = $advancedgrading['data'];
            }
        }

        return [
            'course_id' => $course->id,
            'course' => $course->fullname,
            'assignment_id' => $assignment->id,
            'cmi_id' => $cmid,
            'assignment_title' => $assignment->name,
            'assignment_description' => $assignment->intro,
            'assignment_activity_instructions' => $assignment->activity ?? '',
            'rubric' => $rubric,
            'assessment_guide' => $assessmentguide,
            // Consumer for consumption/rate-limit: teacher who triggered the manual review →
            // configured graderid (autograde) → student (last resort, avoids null).
            'userid' => (string)($this->reviewerid ?: $config->graderid ?: $this->user->id),
            'student_name' => fullname($this->user),
            'submission_assign' => self::get_submission_text($this->submission),
            'submission_files' => $this->get_submission_files(),
            'maximum_grade' => $assignment->grade,
            'prompt' => $config->prompt,
            'lang' => $config->lang,
        ];
    }
}
