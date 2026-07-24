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

/**
 * Tests for the AI submission processor.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');

/**
 * Unit tests for the AI submission processor.
 *
 * @coversDefaultClass \local_assign_ai\assign_submission
 * @group local_assign_ai
 */
final class assign_submission_test extends \advanced_testcase {
    use \mod_assign_test_generator;

    /**
     * Configure the Datacurso AI provider so real pipeline calls can run against curl mocks.
     *
     * The provider only requires a license key; site_uuid is set for determinism.
     *
     * @return void
     */
    private function configure_ai_provider(): void {
        set_config('licensekey', 'phpunit-license-key', 'aiprovider_datacurso');
        set_config('site_uuid', 'phpunit-site-uuid', 'aiprovider_datacurso');
    }

    /**
     * Queue the mocked HTTP responses consumed by one client::send_to_ai() call.
     *
     * One AI review makes two HTTP requests: the region lookup (GET tokens/saldo) and the
     * final /assign/answer POST. Mock responses are consumed in LIFO order, so the
     * /assign/answer body is queued first.
     *
     * @param string $answerbody Body returned for the final /assign/answer POST.
     * @return void
     */
    private function mock_ai_pipeline(string $answerbody): void {
        \curl::mock_response($answerbody);
        \curl::mock_response(json_encode(['is_for_eu' => false]));
    }

    /**
     * Queue the mocked responses of a successful AI review with the given grade and reply.
     *
     * @param int $grade Grade returned by the mocked AI service.
     * @param string $reply Feedback text returned by the mocked AI service.
     * @return void
     */
    private function mock_ai_success(int $grade, string $reply): void {
        $this->mock_ai_pipeline(json_encode([
            'reply' => $reply,
            'grade' => $grade,
            'rubric' => null,
            'assessment_guide' => null,
        ]));
    }

    /**
     * Bump the assign instance id sequence past the given id.
     *
     * assignment_config keeps a per-process static cache keyed by assignment id while the PHPUnit
     * database reset reuses ids across tests, so each test claims a process-unique id range to
     * guarantee it operates on an assignment id no other test has cached.
     *
     * @param int $id Filler id imported directly into the assign table.
     * @param int $courseid Course id used by the filler row.
     * @return void
     */
    private function bump_assign_sequence(int $id, int $courseid): void {
        global $DB;

        $DB->import_record('assign', (object) [
            'id' => $id,
            'course' => $courseid,
            'name' => 'filler',
            'intro' => '',
            'introformat' => FORMAT_HTML,
        ]);
        $DB->get_manager()->reset_sequence('assign');
    }

    /**
     * Enable autograde (and optionally the grader) on the auto-created assignment config row.
     *
     * @param int $assignmentid Assignment instance id.
     * @param int|null $graderid Optional grader user id applied to the config row.
     * @return void
     */
    private function enable_autograde(int $assignmentid, ?int $graderid = null): void {
        global $DB;

        $DB->set_field('local_assign_ai_config', 'autograde', 1, ['assignmentid' => $assignmentid]);
        if ($graderid !== null) {
            $DB->set_field('local_assign_ai_config', 'graderid', $graderid, ['assignmentid' => $assignmentid]);
        }
    }

    /**
     * MDL-INT-004: With autograde enabled, a file submission is processed through the mocked
     * AI service and the record is approved with the mocked grade.
     *
     * @covers ::process_submission_ai
     */
    public function test_autograde_processes_file_submission(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $this->configure_ai_provider();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(2010, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 5,
            'assignsubmission_file_maxsizebytes' => 1024 * 1024,
            'assignfeedback_comments_enabled' => 1,
        ]);
        $this->enable_autograde((int) $assign->get_instance()->id);

        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_submission([
            'userid' => $student->id,
            'cmid' => $assign->get_course_module()->id,
            'file' => 'lib/tests/fixtures/upload_users.csv',
        ]);

        // The file must exist in the assignsubmission_file area of the attempt.
        $submission = $assign->get_user_submission($student->id, false);
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $assign->get_context()->id,
            'assignsubmission_file',
            'submission_files',
            $submission->id,
            'filename',
            false
        );
        $this->assertCount(1, $files);

        $this->mock_ai_success(8, 'File feedback');

        $processor = new assign_submission((int) $student->id, $assign);
        $processor->process_submission_ai();

        $record = $DB->get_record('local_assign_ai_pending', ['userid' => $student->id], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_APPROVED, $record->status);
        $this->assertEquals(8, $record->grade);
        $this->assertSame('File feedback', $record->message);
        $this->assertNull($record->errormessage);
    }

    /**
     * MDL-INT-004: With autograde enabled, an online text submission is processed through the
     * mocked AI service and the record is approved with the mocked grade.
     *
     * @covers ::process_submission_ai
     */
    public function test_autograde_processes_onlinetext_submission(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $this->configure_ai_provider();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(2020, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignfeedback_comments_enabled' => 1,
        ]);
        $this->enable_autograde((int) $assign->get_instance()->id);

        $this->add_submission($student, $assign, 'My essay text');

        $this->mock_ai_success(9, 'Text feedback');

        $processor = new assign_submission((int) $student->id, $assign);
        $processor->process_submission_ai();

        $record = $DB->get_record('local_assign_ai_pending', ['userid' => $student->id], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_APPROVED, $record->status);
        $this->assertEquals(9, $record->grade);
        $this->assertSame('Text feedback', $record->message);
        $this->assertNull($record->errormessage);
    }

    /**
     * MDL-INT-004: A submission carrying both online text and a file is processed as a whole
     * and the record is approved with the mocked grade.
     *
     * @covers ::process_submission_ai
     */
    public function test_autograde_processes_combined_text_and_file_submission(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $this->configure_ai_provider();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(2030, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 5,
            'assignsubmission_file_maxsizebytes' => 1024 * 1024,
            'assignfeedback_comments_enabled' => 1,
        ]);
        $this->enable_autograde((int) $assign->get_instance()->id);

        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_submission([
            'userid' => $student->id,
            'cmid' => $assign->get_course_module()->id,
            'onlinetext' => 'Combined essay text',
            'file' => 'lib/tests/fixtures/upload_users.csv',
        ]);

        // Both content sources must exist before processing.
        $submission = $assign->get_user_submission($student->id, false);
        $this->assertNotEmpty(assign_submission::get_submission_text($submission));
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $assign->get_context()->id,
            'assignsubmission_file',
            'submission_files',
            $submission->id,
            'filename',
            false
        );
        $this->assertCount(1, $files);

        $this->mock_ai_success(10, 'Combined feedback');

        $processor = new assign_submission((int) $student->id, $assign);
        $processor->process_submission_ai();

        $record = $DB->get_record('local_assign_ai_pending', ['userid' => $student->id], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_APPROVED, $record->status);
        $this->assertEquals(10, $record->grade);
        $this->assertSame('Combined feedback', $record->message);
    }

    /**
     * MDL-INT-004: When the AI service rejects the request (empty response body, as surfaced by
     * the real HTTP client), the record ends as failed with a clear error message.
     *
     * @covers ::process_submission_ai
     * @covers ::register_failure
     */
    public function test_autograde_failure_marks_record_failed_with_error(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $this->configure_ai_provider();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(2040, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);
        $this->enable_autograde((int) $assign->get_instance()->id);

        // A submitted attempt without any processable content (no text, no files).
        $submission = $assign->get_user_submission($student->id, true);
        $DB->set_field(
            'assign_submission',
            'status',
            ASSIGN_SUBMISSION_STATUS_SUBMITTED,
            ['id' => $submission->id]
        );

        // The service rejects the payload: the HTTP client surfaces it as an "empty response" error.
        $this->mock_ai_pipeline('');

        $processor = new assign_submission((int) $student->id, $assign);
        $processor->process_submission_ai();

        $record = $DB->get_record('local_assign_ai_pending', ['userid' => $student->id], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_FAILED, $record->status);
        $this->assertSame(get_string('emptyresponse', 'aiprovider_datacurso'), $record->errormessage);
        $this->assertNull($record->grade);

        $this->resetDebugging();
    }

    /**
     * MDL-INT-008: With autograde disabled, processing a submitted attempt only creates an
     * initial record for later manual review and performs no AI call at all.
     *
     * @covers ::process_submission_ai
     */
    public function test_manual_flow_creates_initial_record_without_ai_call(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(2050, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);

        $this->add_submission($student, $assign, 'My essay text');

        // No curl mock is queued: any HTTP attempt would fail and mark the record as failed.
        $processor = new assign_submission((int) $student->id, $assign);
        $processor->process_submission_ai();

        $record = $DB->get_record('local_assign_ai_pending', ['userid' => $student->id], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_INITIAL, $record->status);
        $this->assertNull($record->message);
        $this->assertNull($record->grade);
        $this->assertNull($record->errormessage);
    }

    /**
     * MDL-INT-008: queue_ai_review() marks the record as queued and schedules a
     * process_review_submission ad-hoc task.
     *
     * @covers ::queue_ai_review
     */
    public function test_queue_ai_review_marks_record_queued_and_schedules_task(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(2060, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);

        $this->add_submission($student, $assign, 'My essay text');
        $processor = new assign_submission((int) $student->id, $assign);
        $processor->process_submission_ai();

        $record = $DB->get_record('local_assign_ai_pending', ['userid' => $student->id], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_INITIAL, $record->status);

        assign_submission::queue_ai_review(
            (int) $assign->get_course_module()->id,
            (int) $course->id,
            (int) $student->id,
            (int) $record->id
        );

        $record = $DB->get_record('local_assign_ai_pending', ['id' => $record->id], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_QUEUED, $record->status);

        $tasks = \core\task\manager::get_adhoc_tasks(\local_assign_ai\task\process_review_submission::class);
        $this->assertCount(1, $tasks);
    }

    /**
     * MDL-INT-008: Running the queued review task against the mocked AI service moves the
     * record to pending with the grade and message stored.
     *
     * @covers ::process_submission_ai_review
     * @covers ::queue_ai_review
     */
    public function test_review_task_moves_record_to_pending_with_ai_result(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $this->configure_ai_provider();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(2070, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignfeedback_comments_enabled' => 1,
        ]);

        $this->add_submission($student, $assign, 'My essay text');
        $processor = new assign_submission((int) $student->id, $assign);
        $processor->process_submission_ai();

        $record = $DB->get_record('local_assign_ai_pending', ['userid' => $student->id], '*', MUST_EXIST);
        assign_submission::queue_ai_review(
            (int) $assign->get_course_module()->id,
            (int) $course->id,
            (int) $student->id,
            (int) $record->id
        );

        // Drop the observer-queued autograde task so only the review task runs against the mock.
        $DB->delete_records('task_adhoc', ['classname' => '\local_assign_ai\task\process_submission_ai']);

        $this->mock_ai_success(8, 'Manual review feedback');
        \phpunit_util::run_all_adhoc_tasks();

        $record = $DB->get_record('local_assign_ai_pending', ['id' => $record->id], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_PENDING, $record->status);
        $this->assertEquals(8, $record->grade);
        $this->assertSame('Manual review feedback', $record->message);
        $this->assertNull($record->errormessage);
    }

    /**
     * MDL-INT-009: With autograde enabled and a grader configured, the AI feedback is applied
     * to the assignment grade with the configured grader attribution.
     *
     * @covers ::process_submission_ai
     */
    public function test_autograde_applies_feedback_when_grader_configured(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $this->configure_ai_provider();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->bump_assign_sequence(2080, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignfeedback_comments_enabled' => 1,
        ]);
        $this->enable_autograde((int) $assign->get_instance()->id, (int) $teacher->id);

        $this->add_submission($student, $assign, 'My essay text');

        $this->mock_ai_success(8, 'Great work');

        $this->setAdminUser();
        $processor = new assign_submission((int) $student->id, $assign);
        $processor->process_submission_ai();

        $record = $DB->get_record('local_assign_ai_pending', ['userid' => $student->id], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_APPROVED, $record->status);
        $this->assertEquals(8, $record->grade);

        // The grade is applied to the mod_assign tables with the configured grader attribution.
        $grade = $assign->get_user_grade($student->id, false);
        $this->assertNotEmpty($grade);
        $this->assertEquals(8.0, (float) $grade->grade);
        $this->assertEquals($teacher->id, $grade->grader);

        // The AI message is stored as feedback comments.
        $feedback = $DB->get_record('assignfeedback_comments', ['grade' => $grade->id], '*', MUST_EXIST);
        $this->assertSame('Great work', $feedback->commenttext);

        $this->resetDebugging();
    }

    /**
     * MDL-INT-011: Processing a new attempt marks previous active (unreviewed) records as
     * superseded while each attempt keeps its own record.
     *
     * @covers ::process_submission_ai
     * @covers ::supersede_previous_attempts
     */
    public function test_new_attempt_supersedes_previous_active_record(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $this->configure_ai_provider();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->bump_assign_sequence(2090, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'attemptreopenmethod' => ASSIGN_ATTEMPT_REOPEN_METHOD_MANUAL,
            'maxattempts' => ASSIGN_UNLIMITED_ATTEMPTS,
        ]);
        $this->enable_autograde((int) $assign->get_instance()->id);

        // First attempt, processed and left as an active (unreviewed) record.
        $this->add_submission($student, $assign, 'First attempt');
        $firstsubmission = $assign->get_user_submission($student->id, false);

        $this->mock_ai_success(6, 'First attempt feedback');
        $processor = new assign_submission((int) $student->id, $assign);
        $processor->process_submission_ai();

        $firstrecord = $DB->get_record(
            'local_assign_ai_pending',
            ['submissionid' => $firstsubmission->id],
            '*',
            MUST_EXIST
        );
        $DB->set_field(
            'local_assign_ai_pending',
            'status',
            assign_submission::STATUS_PENDING,
            ['id' => $firstrecord->id]
        );

        // The teacher opens a new attempt and the student submits again.
        $teacher->ignoresesskey = true;
        $this->setUser($teacher);
        $this->assertTrue($assign->testable_process_add_attempt($student->id));
        $this->add_submission($student, $assign, 'Second attempt');

        $this->mock_ai_success(9, 'Second attempt feedback');
        $freshassign = new \assign($assign->get_context(), $assign->get_course_module(), $course);
        $processor = new assign_submission((int) $student->id, $freshassign);
        $processor->process_submission_ai();

        $secondsubmission = $freshassign->get_user_submission($student->id, false);
        $this->assertNotEquals($firstsubmission->id, $secondsubmission->id);

        // The previous active record is frozen as superseded; the new attempt has its own record.
        $firstrecord = $DB->get_record('local_assign_ai_pending', ['id' => $firstrecord->id], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_SUPERSEDED, $firstrecord->status);

        $secondrecord = $DB->get_record(
            'local_assign_ai_pending',
            ['submissionid' => $secondsubmission->id],
            '*',
            MUST_EXIST
        );
        $this->assertSame(assign_submission::STATUS_APPROVED, $secondrecord->status);
        $this->assertEquals(9, $secondrecord->grade);
        $this->assertEquals(1, $secondrecord->attemptnumber);
    }

    /**
     * MDL-INT-011: Finalized records from previous attempts are preserved as history when a
     * new attempt is processed, and each attempt keeps its own record.
     *
     * @covers ::process_submission_ai
     * @covers ::supersede_previous_attempts
     */
    public function test_finalized_records_from_previous_attempts_are_preserved(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $this->configure_ai_provider();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->bump_assign_sequence(2100, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'attemptreopenmethod' => ASSIGN_ATTEMPT_REOPEN_METHOD_MANUAL,
            'maxattempts' => ASSIGN_UNLIMITED_ATTEMPTS,
        ]);
        $this->enable_autograde((int) $assign->get_instance()->id);

        // First attempt, processed and finalized (approved).
        $this->add_submission($student, $assign, 'First attempt');
        $firstsubmission = $assign->get_user_submission($student->id, false);

        $this->mock_ai_success(6, 'First attempt feedback');
        $processor = new assign_submission((int) $student->id, $assign);
        $processor->process_submission_ai();

        $firstrecord = $DB->get_record(
            'local_assign_ai_pending',
            ['submissionid' => $firstsubmission->id],
            '*',
            MUST_EXIST
        );
        $this->assertSame(assign_submission::STATUS_APPROVED, $firstrecord->status);

        // The teacher opens a new attempt and the student submits again.
        $teacher->ignoresesskey = true;
        $this->setUser($teacher);
        $this->assertTrue($assign->testable_process_add_attempt($student->id));
        $this->add_submission($student, $assign, 'Second attempt');

        $this->mock_ai_success(9, 'Second attempt feedback');
        $freshassign = new \assign($assign->get_context(), $assign->get_course_module(), $course);
        $processor = new assign_submission((int) $student->id, $freshassign);
        $processor->process_submission_ai();

        $secondsubmission = $freshassign->get_user_submission($student->id, false);

        // The finalized record from the first attempt is preserved untouched.
        $firstrecord = $DB->get_record('local_assign_ai_pending', ['id' => $firstrecord->id], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_APPROVED, $firstrecord->status);
        $this->assertEquals(6, $firstrecord->grade);
        $this->assertEquals(0, $firstrecord->attemptnumber);

        // Each attempt keeps its own record.
        $secondrecord = $DB->get_record(
            'local_assign_ai_pending',
            ['submissionid' => $secondsubmission->id],
            '*',
            MUST_EXIST
        );
        $this->assertNotEquals($firstrecord->id, $secondrecord->id);
        $this->assertSame(assign_submission::STATUS_APPROVED, $secondrecord->status);
        $this->assertEquals(1, $secondrecord->attemptnumber);
    }

    /**
     * MDL-INT-011: The grade produced for a reopened attempt should be linked to that attempt
     * number so it reaches the gradebook.
     *
     * feedback_applier calls get_user_grade($userid, true, $record->attemptnumber) so each
     * attempt gets its own assign_grades row, keyed by attemptnumber, rather than all grades
     * overwriting the same row (attemptnumber = -1 = latest).
     *
     * @covers ::process_submission_ai
     */
    public function test_grade_is_associated_to_the_processed_attempt_number(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $this->configure_ai_provider();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->bump_assign_sequence(2110, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'attemptreopenmethod' => ASSIGN_ATTEMPT_REOPEN_METHOD_MANUAL,
            'maxattempts' => 2,
        ]);
        $this->enable_autograde((int) $assign->get_instance()->id, (int) $teacher->id);

        // Attempt 0: student submits and AI processes.
        $this->add_submission($student, $assign, 'First attempt text');

        $this->mock_ai_success(6, 'First attempt feedback');
        $processor = new assign_submission((int) $student->id, $assign);
        $processor->process_submission_ai();

        // Grade for attempt 0 must exist with the correct attempt number and grade.
        $graderow0 = $assign->get_user_grade($student->id, false, 0);
        $this->assertNotEmpty($graderow0, 'Grade row for attempt 0 must exist');
        $this->assertEquals(0, (int) $graderow0->attemptnumber, 'Attempt 0 grade row must carry attemptnumber=0');
        $this->assertEquals(6.0, (float) $graderow0->grade, 'Attempt 0 grade must be 6');

        // Attempt 1: teacher reopens, student resubmits, AI processes.
        $teacher->ignoresesskey = true;
        $this->setUser($teacher);
        $this->assertTrue($assign->testable_process_add_attempt($student->id));

        $this->add_submission($student, $assign, 'Second attempt text');

        $this->mock_ai_success(9, 'Second attempt feedback');
        $freshassign = new \assign($assign->get_context(), $assign->get_course_module(), $course);
        $processor = new assign_submission((int) $student->id, $freshassign);
        $processor->process_submission_ai();

        // Grade for attempt 1 must be a separate row, not overwriting attempt 0.
        $graderow1 = $freshassign->get_user_grade($student->id, false, 1);
        $this->assertNotEmpty($graderow1, 'Grade row for attempt 1 must exist');
        $this->assertEquals(1, (int) $graderow1->attemptnumber, 'Attempt 1 grade row must carry attemptnumber=1');
        $this->assertEquals(9.0, (float) $graderow1->grade, 'Attempt 1 grade must be 9');

        // Both rows must be distinct records.
        $this->assertNotEquals($graderow0->id, $graderow1->id, 'Each attempt must have its own grade row');

        // The attempt 0 grade must remain unchanged.
        $graderow0again = $freshassign->get_user_grade($student->id, false, 0);
        $this->assertEquals(6.0, (float) $graderow0again->grade, 'Attempt 0 grade must remain 6 after attempt 1 is graded');

        $this->resetDebugging();
    }
}
