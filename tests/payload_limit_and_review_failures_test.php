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

use local_assign_ai\task\retry_failed_submissions;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');

/**
 * Tests for the size guards, non-retryable failures and needs_review answers handled as terminal failures.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \local_assign_ai\assign_submission
 * @group local_assign_ai
 */
final class payload_limit_and_review_failures_test extends \advanced_testcase {
    use \mod_assign_test_generator;

    /** @var int Next filler assign id; assignment_config caches by assignment id across tests. */
    private static int $nextassignid = 3100;

    /** @var int Number of requests that reached the faked HTTP client. */
    private int $requests = 0;

    /**
     * Replace the AI HTTP client so no network is used and the outcome can be scripted.
     *
     * @param callable $responder Receives the request body and returns the response array (or throws).
     * @return void
     */
    private function fake_ai_client(callable $responder): void {
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the AI pipeline cannot run.');
        }

        $this->requests = 0;
        $spy = $this->getMockBuilder(\aiprovider_datacurso\httpclient\ai_services_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['request'])
            ->getMock();
        $spy->method('request')->willReturnCallback(
            function (string $method, string $path, array $body = []) use ($responder): array {
                $this->requests++;
                return $responder($body);
            }
        );
        $factory = $this->createStub(\local_assign_ai\api\ai_client_factory::class);
        $factory->method('create')->willReturn($spy);
        \core\di::set(\local_assign_ai\api\ai_client_factory::class, $factory);
    }

    /**
     * Create an assignment with a submitted text attempt and a manual-review ("initial") record.
     *
     * @param bool $autograde Whether autograde is enabled for the assignment.
     * @return array [assign, student, teacher, record]
     */
    private function create_scenario(bool $autograde = false): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        // The assignment_config static cache is keyed by assign id and ids repeat across tests.
        $DB->import_record('assign', (object) [
            'id' => self::$nextassignid,
            'course' => $course->id,
            'name' => 'filler',
            'intro' => '',
            'introformat' => FORMAT_HTML,
        ]);
        $DB->get_manager()->reset_sequence('assign');
        self::$nextassignid += 10;

        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 5,
            'assignsubmission_file_maxsizebytes' => 0,
            'assignfeedback_comments_enabled' => 1,
        ]);
        $instanceid = (int) $assign->get_instance()->id;
        $DB->set_field('local_assign_ai_config', 'autograde', $autograde ? 1 : 0, ['assignmentid' => $instanceid]);
        $DB->set_field('local_assign_ai_config', 'graderid', $teacher->id, ['assignmentid' => $instanceid]);

        $this->add_submission($student, $assign, 'My essay text');
        if ($autograde) {
            return [$assign, $student, $teacher, null];
        }

        (new assign_submission((int) $student->id, $assign))->process_submission_ai();
        $record = $DB->get_record('local_assign_ai_pending', ['userid' => $student->id], '*', MUST_EXIST);
        return [$assign, $student, $teacher, $record];
    }

    /**
     * Store an extra file of the given size in the student's submission.
     *
     * @param \assign $assign Assignment.
     * @param \stdClass $student Student.
     * @param int $bytes File size in bytes.
     * @return void
     */
    private function add_file(\assign $assign, \stdClass $student, int $bytes): void {
        $submission = $assign->get_user_submission($student->id, false);
        get_file_storage()->create_file_from_string([
            'contextid' => $assign->get_context()->id,
            'component' => 'assignsubmission_file',
            'filearea' => 'submission_files',
            'itemid' => $submission->id,
            'filepath' => '/',
            'filename' => 'big.bin',
        ], str_repeat('a', $bytes));
    }

    /**
     * Store a small file whose recorded size is overridden, to exercise the size guards without
     * allocating tens of megabytes (the guards only look at the stored size, never at the content).
     *
     * @param \assign $assign Assignment.
     * @param \stdClass $student Student.
     * @param string $filename File name.
     * @param int $declaredbytes Size to record for the file.
     * @return void
     */
    private function add_file_with_declared_size(\assign $assign, \stdClass $student, string $filename, int $declaredbytes): void {
        global $DB;

        $submission = $assign->get_user_submission($student->id, false);
        $file = get_file_storage()->create_file_from_string([
            'contextid' => $assign->get_context()->id,
            'component' => 'assignsubmission_file',
            'filearea' => 'submission_files',
            'itemid' => $submission->id,
            'filepath' => '/',
            'filename' => $filename,
        ], 'tiny ' . $filename);
        $DB->set_field('files', 'filesize', $declaredbytes, ['id' => $file->get_id()]);
    }

    /**
     * Run the manual review pipeline for a record, as the queued task would.
     *
     * @param \assign $assign Assignment.
     * @param \stdClass $student Student.
     * @param \stdClass $record Pending record.
     * @return \stdClass Reloaded record.
     */
    private function run_review(\assign $assign, \stdClass $student, \stdClass $record): \stdClass {
        global $DB;

        (new assign_submission((int) $student->id, $assign))->process_submission_ai_review((int) $record->id);
        // register_failure() always emits a developer debugging message for the failures under test.
        $this->resetDebugging();
        return $DB->get_record('local_assign_ai_pending', ['id' => $record->id], '*', MUST_EXIST);
    }

    /**
     * Files above the configured limit are never sent: the record fails, non-retryable, with a
     * message that carries the limit and the total.
     *
     * @covers ::process_submission_ai_review
     * @covers ::get_submission_files
     */
    public function test_oversized_submission_fails_without_calling_the_ai(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->fake_ai_client(fn() => ['reply' => 'x', 'grade' => 1]);

        [$assign, $student, , $record] = $this->create_scenario();
        $this->add_file_with_declared_size($assign, $student, 'big.bin', 31 * 1048576);

        $record = $this->run_review($assign, $student, $record);

        $this->assertSame(0, $this->requests);
        $this->assertSame(assign_submission::STATUS_FAILED, $record->status);
        $this->assertSame(
            get_string('error_payload_too_large', 'local_assign_ai', (object) ['limit' => '30', 'total' => '31']),
            $record->errormessage
        );
        $this->assertSame(retry_failed_submissions::MAX_RETRIES, (int) $record->retries);
        $this->resetDebugging();
    }

    /**
     * Files within the limit are sent normally.
     *
     * @covers ::get_submission_files
     */
    public function test_submission_within_limit_is_sent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->fake_ai_client(fn() => ['reply' => 'ok', 'grade' => 5]);

        [$assign, $student, , $record] = $this->create_scenario();
        $this->add_file($assign, $student, 1024);

        $record = $this->run_review($assign, $student, $record);

        $this->assertSame(1, $this->requests);
        $this->assertSame(assign_submission::STATUS_PENDING, $record->status);
    }

    /**
     * An oversized failure is never re-queued by the automatic retry task, and the grader is told once.
     *
     * @covers ::register_failure
     * @covers \local_assign_ai\task\retry_failed_submissions::execute
     */
    public function test_oversized_failure_is_not_retried_and_grader_notified_once(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectOutputRegex('/.*/s');
        $this->fake_ai_client(fn() => ['reply' => 'x', 'grade' => 1]);

        [$assign, $student, $teacher, $record] = $this->create_scenario();
        $this->add_file_with_declared_size($assign, $student, 'big.bin', 31 * 1048576);

        $sink = $this->redirectMessages();
        $record = $this->run_review($assign, $student, $record);
        (new retry_failed_submissions())->execute();
        $this->resetDebugging();

        $record = $DB->get_record('local_assign_ai_pending', ['id' => $record->id], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_FAILED, $record->status);
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertEquals($teacher->id, $messages[0]->useridto);
    }

    /**
     * Autograde: an oversized submission creates a failed, non-retryable record without an AI call.
     *
     * @covers ::process_submission_ai
     */
    public function test_autograde_oversized_creates_terminal_failed_record(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->fake_ai_client(fn() => ['reply' => 'x', 'grade' => 1]);

        [$assign, $student] = $this->create_scenario(true);
        $this->add_file_with_declared_size($assign, $student, 'big.bin', 31 * 1048576);

        (new assign_submission((int) $student->id, $assign))->process_submission_ai();
        $this->resetDebugging();

        $record = $DB->get_record('local_assign_ai_pending', ['userid' => $student->id], '*', MUST_EXIST);
        $this->assertSame(0, $this->requests);
        $this->assertSame(assign_submission::STATUS_FAILED, $record->status);
        $this->assertSame(retry_failed_submissions::MAX_RETRIES, (int) $record->retries);
    }

    /**
     * A 413 coming back from the provider is terminal: no automatic retry.
     *
     * @covers ::register_failure
     */
    public function test_provider_413_is_not_retried(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectOutputRegex('/.*/s');
        $this->fake_ai_client(function (): array {
            // The provider reports an HTTP 413 as its generic httperror exception.
            throw new \moodle_exception('httperror', 'aiprovider_datacurso', '', 413);
        });

        [$assign, $student, , $record] = $this->create_scenario();
        $sink = $this->redirectMessages();
        $record = $this->run_review($assign, $student, $record);
        (new retry_failed_submissions())->execute();
        $this->resetDebugging();

        $record = $DB->get_record('local_assign_ai_pending', ['id' => $record->id], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_FAILED, $record->status);
        $this->assertSame(retry_failed_submissions::MAX_RETRIES, (int) $record->retries);
        $this->assertSame(
            get_string('error_service_payload_rejected', 'local_assign_ai'),
            $record->errormessage
        );
        $this->assertCount(1, $sink->get_messages());
    }

    /**
     * A transient 502/503/504 stays retryable by the automatic retry task.
     *
     * @covers ::register_failure
     */
    public function test_provider_502_is_still_retried(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectOutputRegex('/auto-retried 1 failed AI review/');
        $this->fake_ai_client(function (): array {
            throw new \moodle_exception('httperror', 'aiprovider_datacurso', '', 502);
        });

        [$assign, $student, , $record] = $this->create_scenario();
        $record = $this->run_review($assign, $student, $record);
        $this->assertSame(assign_submission::STATUS_FAILED, $record->status);
        $this->assertSame(0, (int) $record->retries);

        (new retry_failed_submissions())->execute();
        $this->resetDebugging();

        $record = $DB->get_record('local_assign_ai_pending', ['id' => $record->id], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_QUEUED, $record->status);
        $this->assertSame(1, (int) $record->retries);
        // The message shown is the provider's own generic one, not the 413 message.
        $this->assertSame(
            get_string('httperror', 'aiprovider_datacurso', 502),
            $record->errormessage
        );
    }

    /**
     * The same provider exception with another status is not mistaken for a 413.
     *
     * @covers ::ui_error_message
     */
    public function test_ui_error_message_only_maps_413(): void {
        $this->assertSame(
            get_string('error_service_payload_rejected', 'local_assign_ai'),
            assign_submission::ui_error_message(new \moodle_exception('httperror', 'aiprovider_datacurso', '', 413))
        );
        $this->assertSame(
            get_string('httperror', 'aiprovider_datacurso', 503),
            assign_submission::ui_error_message(new \moodle_exception('httperror', 'aiprovider_datacurso', '', 503))
        );
    }

    /**
     * Response of the AI service for an unreadable file.
     *
     * @return array
     */
    private static function unreadable_response(): array {
        return [
            'reply' => 'The file could not be read.',
            'needs_review' => true,
            'review_reason' => 'unreadable_file',
        ];
    }

    /**
     * The client forwards needs_review/review_reason and tolerates the omitted grade.
     *
     * @covers \local_assign_ai\api\client::send_to_ai
     */
    public function test_client_forwards_needs_review_fields(): void {
        $this->resetAfterTest();
        $this->fake_ai_client(fn() => self::unreadable_response());

        $result = \local_assign_ai\api\client::send_to_ai(['userid' => '1', 'submission_assign' => 'x']);

        $this->assertTrue($result['needs_review']);
        $this->assertSame('unreadable_file', $result['review_reason']);
        $this->assertNull($result['grade']);
    }

    /**
     * Normal responses do not flag the review.
     *
     * @covers \local_assign_ai\api\client::send_to_ai
     */
    public function test_client_normal_response_is_not_flagged(): void {
        $this->resetAfterTest();
        $this->fake_ai_client(fn() => ['reply' => 'ok', 'grade' => 4]);

        $result = \local_assign_ai\api\client::send_to_ai(['userid' => '1']);

        $this->assertFalse($result['needs_review']);
        $this->assertNull($result['review_reason']);
    }

    /**
     * Asserts a record is the terminal failure registered for a needs_review answer.
     *
     * @param \stdClass $record Reloaded pending record.
     * @param string $stringkey Expected error string key.
     * @return void
     */
    private function assert_terminal_review_failure(\stdClass $record, string $stringkey): void {
        $this->assertSame(assign_submission::STATUS_FAILED, $record->status);
        $this->assertSame(retry_failed_submissions::MAX_RETRIES, (int) $record->retries);
        $this->assertNull($record->grade);
        $this->assertNull($record->rubric_response);
        $this->assertNull($record->assessment_guide_response);
        $this->assertSame(get_string($stringkey, 'local_assign_ai'), $record->errormessage);
    }

    /**
     * Manual review: an unreadable-file answer is a terminal failure, notified once, never retried.
     *
     * @covers ::process_submission_ai_review
     * @covers ::register_failure
     */
    public function test_review_unreadable_file_is_terminal_failure(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectOutputRegex('/.*/s');
        $this->fake_ai_client(fn() => self::unreadable_response());

        [$assign, $student, $teacher, $record] = $this->create_scenario();
        $sink = $this->redirectMessages();
        $record = $this->run_review($assign, $student, $record);

        $this->assert_terminal_review_failure($record, 'error_unreadable_file');
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertEquals($teacher->id, $messages[0]->useridto);

        // The retry task does not pick it up and nothing else is sent.
        (new retry_failed_submissions())->execute();
        $this->resetDebugging();
        $this->assertSame(1, $this->requests);
        $this->assertSame(
            assign_submission::STATUS_FAILED,
            $DB->get_field('local_assign_ai_pending', 'status', ['id' => $record->id])
        );
        $this->assertCount(1, $sink->get_messages());
    }

    /**
     * Manual review: an inconsistent-evaluation answer is a terminal failure with its own message.
     *
     * @covers ::process_submission_ai_review
     */
    public function test_review_inconsistent_evaluation_is_terminal_failure(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectOutputRegex('/.*/s');
        $this->fake_ai_client(fn() => [
            'reply' => 'The evaluations differ.',
            'needs_review' => true,
            'review_reason' => 'inconsistent_evaluation',
        ]);

        [$assign, $student, , $record] = $this->create_scenario();
        $record = $this->run_review($assign, $student, $record);

        $this->assert_terminal_review_failure($record, 'error_inconsistent_evaluation');
    }

    /**
     * An unknown review reason falls back to the generic message.
     *
     * @covers ::assert_gradable
     */
    public function test_review_unknown_reason_uses_generic_message(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectOutputRegex('/.*/s');
        $this->fake_ai_client(fn() => ['reply' => 'x', 'needs_review' => true, 'review_reason' => 'something_new']);

        [$assign, $student, , $record] = $this->create_scenario();
        $record = $this->run_review($assign, $student, $record);

        $this->assert_terminal_review_failure($record, 'error_needsreview_generic');
    }

    /**
     * Autograde: a needs_review answer creates a terminal failed record, nothing is applied to the
     * gradebook and the grader is notified exactly once, even if the submission is processed again.
     *
     * @covers ::process_submission_ai
     */
    public function test_autograde_needs_review_is_terminal_failure_notified_once(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectOutputRegex('/.*/s');
        $this->fake_ai_client(fn() => self::unreadable_response());

        [$assign, $student, $teacher] = $this->create_scenario(true);
        $sink = $this->redirectMessages();
        (new assign_submission((int) $student->id, $assign))->process_submission_ai();

        $record = $DB->get_record('local_assign_ai_pending', ['userid' => $student->id], '*', MUST_EXIST);
        $this->assert_terminal_review_failure($record, 'error_unreadable_file');
        $grade = $assign->get_user_grade($student->id, false);
        $this->assertTrue(!$grade || $grade->grade === null || (float) $grade->grade < 0);

        (new retry_failed_submissions())->execute();
        $this->resetDebugging();

        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertEquals($teacher->id, $messages[0]->useridto);
        $this->assertStringContainsString(get_string('error_unreadable_file', 'local_assign_ai'), $messages[0]->fullmessage);
    }

    /**
     * The review table shows a needs_review failure as the red Error badge with the message.
     *
     * @covers \local_assign_ai\table\review_table::col_aistatus
     */
    public function test_review_table_shows_needs_review_failure_as_error(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectOutputRegex('/.*/s');
        $this->fake_ai_client(fn() => self::unreadable_response());

        [$assign, $student, , $record] = $this->create_scenario();
        $record = $this->run_review($assign, $student, $record);

        $table = new \local_assign_ai\table\review_table('test', $assign);
        $row = (object) [
            'id' => $record->id,
            'courseid' => $record->courseid,
            'assignmentid' => $record->assignmentid,
            'userid' => $student->id,
            'aistatus' => $record->status,
            'errormessage' => $record->errormessage,
        ];
        $status = $table->col_aistatus($row);
        $this->assertStringContainsString(get_string('statuserror', 'local_assign_ai'), $status);
        $this->assertStringContainsString(s(get_string('error_unreadable_file', 'local_assign_ai')), $status);
    }

    /**
     * The history report lists a needs_review failure with its message and as a failure.
     *
     * @covers \local_assign_ai\reportbuilder\systemreports\history
     */
    public function test_history_report_shows_needs_review_failure_message(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectOutputRegex('/.*/s');
        $this->fake_ai_client(fn() => self::unreadable_response());

        [$assign, $student, , $record] = $this->create_scenario();
        $record = $this->run_review($assign, $student, $record);

        // Creating the submission switched the current user to the student: view as an admin.
        $this->setAdminUser();
        $PAGE->set_url('/local/assign_ai/history.php', ['id' => $record->assignmentid]);
        $params = ['courseid' => $record->courseid, 'assignmentid' => $record->assignmentid];
        $report = \core_reportbuilder\system_report_factory::create(
            \local_assign_ai\reportbuilder\systemreports\history::class,
            $assign->get_context(),
            '',
            '',
            0,
            $params
        );
        $table = \core_reportbuilder\table\system_report_table::create(
            $report->get_report_persistent()->get('id'),
            $params
        );
        $table->define_baseurl(new \moodle_url('/local/assign_ai/history.php', ['id' => $record->assignmentid]));
        $table->setup();
        $table->query_db(0, false);
        $cells = [];
        foreach ($table->rawdata as $raw) {
            $cells[] = implode(' | ', array_map('strval', $table->format_row($raw)));
        }
        $table->close_recordset();

        $this->assertCount(1, $cells);
        $this->assertStringContainsString(get_string('statuserror', 'local_assign_ai'), $cells[0]);
        $this->assertStringContainsString(get_string('logfailed', 'local_assign_ai'), $cells[0]);
        $this->assertStringContainsString(s(get_string('error_unreadable_file', 'local_assign_ai')), $cells[0]);
    }

    /**
     * The size limits are code constants: no admin setting offers them and nothing reads a config value.
     *
     * @covers ::assert_gradable
     */
    public function test_limits_are_constants_not_settings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertSame(45, assign_submission::MAX_FILE_MB);
        $this->assertSame(30, assign_submission::MAX_TOTAL_MB);

        $page = \admin_get_root(true, true)->locate('local_assign_ai_settings');
        // Loading the whole admin tree triggers an unrelated local_adminer XMLDB debugging notice.
        $this->resetDebugging();
        $this->assertNotNull($page);
        foreach ((array) $page->settings as $candidate) {
            $this->assertStringNotContainsString('maxpayloadmb', $candidate->get_full_name());
        }

        $this->assertFalse(class_exists(\local_assign_ai\admin\setting_configint_min::class));

        // A leftover stored value has no effect on the guard: a 5 MB submission is still sent.
        set_config('maxpayloadmb', 1, 'local_assign_ai');
        $this->fake_ai_client(fn() => ['reply' => 'ok', 'grade' => 5]);
        [$assign, $student, , $record] = $this->create_scenario();
        $this->add_file_with_declared_size($assign, $student, 'a.pdf', 5 * 1048576);
        $record = $this->run_review($assign, $student, $record);
        $this->assertSame(1, $this->requests);
        $this->assertSame(assign_submission::STATUS_PENDING, $record->status);
    }

    /**
     * A single file above the per-file limit is rejected before any AI call, terminal, notified once,
     * with a message naming the file and both sizes. The total guard is not reached first.
     *
     * @covers ::get_submission_files
     */
    public function test_single_oversized_file_is_rejected_with_specific_message(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectOutputRegex('/.*/s');
        $this->fake_ai_client(fn() => ['reply' => 'x', 'grade' => 1]);

        [$assign, $student, $teacher, $record] = $this->create_scenario();
        $this->add_file_with_declared_size($assign, $student, 'scan.pdf', 46 * 1048576);

        $sink = $this->redirectMessages();
        $record = $this->run_review($assign, $student, $record);
        (new retry_failed_submissions())->execute();
        $this->resetDebugging();

        $record = $DB->get_record('local_assign_ai_pending', ['id' => $record->id], '*', MUST_EXIST);
        $this->assertSame(0, $this->requests);
        $this->assertSame(assign_submission::STATUS_FAILED, $record->status);
        $this->assertSame(retry_failed_submissions::MAX_RETRIES, (int) $record->retries);
        $this->assertSame(
            get_string('error_file_too_large', 'local_assign_ai', (object) ['file' => 'scan.pdf', 'size' => '46', 'limit' => '45']),
            $record->errormessage
        );
        $this->assertStringContainsString('scan.pdf', $record->errormessage);
        $this->assertCount(1, $sink->get_messages());
        $this->assertEquals($teacher->id, $sink->get_messages()[0]->useridto);
    }

    /**
     * Two files under the per-file limit whose total exceeds the configured limit hit the total guard.
     *
     * @covers ::get_submission_files
     */
    public function test_total_over_limit_uses_total_message(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectOutputRegex('/.*/s');
        $this->fake_ai_client(fn() => ['reply' => 'x', 'grade' => 1]);

        [$assign, $student, , $record] = $this->create_scenario();
        $this->add_file_with_declared_size($assign, $student, 'a.pdf', 20 * 1048576);
        $this->add_file_with_declared_size($assign, $student, 'b.pdf', 20 * 1048576);

        $record = $this->run_review($assign, $student, $record);
        $this->resetDebugging();

        $this->assertSame(0, $this->requests);
        $this->assertSame(assign_submission::STATUS_FAILED, $record->status);
        $this->assertSame(
            get_string('error_payload_too_large', 'local_assign_ai', (object) ['limit' => '30', 'total' => '40']),
            $record->errormessage
        );
    }

    /**
     * A submission just under both limits (29 MB total, files below 45 MB) is sent.
     *
     * @covers ::get_submission_files
     */
    public function test_submission_under_both_limits_is_sent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->fake_ai_client(fn() => ['reply' => 'ok', 'grade' => 5]);

        [$assign, $student, , $record] = $this->create_scenario();
        $this->add_file_with_declared_size($assign, $student, 'a.pdf', 14 * 1048576);
        $this->add_file_with_declared_size($assign, $student, 'b.pdf', 15 * 1048576);

        $record = $this->run_review($assign, $student, $record);

        $this->assertSame(1, $this->requests);
        $this->assertSame(assign_submission::STATUS_PENDING, $record->status);
    }

    /**
     * Boundaries are inclusive: exactly 30 MB in total is sent; 45 MB in one file exceeds the total, and
     * only a file above 45 MB gets the per-file message.
     *
     * @covers ::get_submission_files
     */
    public function test_exact_limits(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectOutputRegex('/.*/s');
        $this->fake_ai_client(fn() => ['reply' => 'ok', 'grade' => 5]);

        // Exactly 30 MB in total.
        [$assign, $student, , $record] = $this->create_scenario();
        $this->add_file_with_declared_size($assign, $student, 'a.pdf', 20 * 1048576);
        $this->add_file_with_declared_size($assign, $student, 'b.pdf', 10 * 1048576);
        $record = $this->run_review($assign, $student, $record);
        $this->assertSame(1, $this->requests);
        $this->assertSame(assign_submission::STATUS_PENDING, $record->status);

        // One byte over 30 MB in total: total message.
        [$assign, $student, , $record] = $this->create_scenario();
        $this->add_file_with_declared_size($assign, $student, 'a.pdf', 30 * 1048576 + 1);
        $record = $this->run_review($assign, $student, $record);
        $this->assertSame(1, $this->requests);
        $this->assertStringContainsString('30', $record->errormessage);
        $this->assertSame(
            get_string('error_payload_too_large', 'local_assign_ai', (object) ['limit' => '30', 'total' => '30']),
            $record->errormessage
        );

        // Exactly 45 MB in one file: rejected by the total guard, not by the per-file one.
        [$assign, $student, , $record] = $this->create_scenario();
        $this->add_file_with_declared_size($assign, $student, 'big.pdf', 45 * 1048576);
        $record = $this->run_review($assign, $student, $record);
        $this->assertSame(
            get_string('error_payload_too_large', 'local_assign_ai', (object) ['limit' => '30', 'total' => '45']),
            $record->errormessage
        );

        // Just over 45 MB in one file: the per-file message.
        [$assign, $student, , $record] = $this->create_scenario();
        $this->add_file_with_declared_size($assign, $student, 'huge.pdf', 45 * 1048576 + 1048576);
        $record = $this->run_review($assign, $student, $record);
        $this->assertStringContainsString('huge.pdf', $record->errormessage);
        $this->assertSame(1, $this->requests);
        $this->resetDebugging();
    }
}
