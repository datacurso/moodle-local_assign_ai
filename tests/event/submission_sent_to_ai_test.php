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

namespace local_assign_ai\event;

use local_assign_ai\assign_submission;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');

/**
 * Tests for the audit event logged for every transfer of a submission to the AI service.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_assign_ai\event\submission_sent_to_ai
 * @covers    \local_assign_ai\assign_submission
 * @group     local_assign_ai
 */
final class submission_sent_to_ai_test extends \advanced_testcase {
    use \mod_assign_test_generator;

    /** @var string Text of the student submission, which must never reach the log. */
    private const SUBMISSION_TEXT = 'Confidential essay about photosynthesis';

    /** @var string Reply of the AI service, which must never reach the log. */
    private const AI_REPLY = 'Secret feedback from the service';

    /** @var int Next filler assign id; assignment_config caches by assignment id across tests. */
    private static int $nextassignid = 5100;

    /**
     * Replace the AI HTTP client so no network is used and the outcome can be scripted.
     *
     * @param callable $responder Returns the response array (or throws).
     * @return void
     */
    private function fake_ai_client(callable $responder): void {
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the AI pipeline cannot run.');
        }

        $spy = $this->getMockBuilder(\aiprovider_datacurso\httpclient\ai_services_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['request'])
            ->getMock();
        $spy->method('request')->willReturnCallback(
            fn(string $method, string $path, array $body = []): array => $responder($body)
        );
        $factory = $this->createStub(\local_assign_ai\api\ai_client_factory::class);
        $factory->method('create')->willReturn($spy);
        \core\di::set(\local_assign_ai\api\ai_client_factory::class, $factory);
    }

    /**
     * Create an assignment with a submitted text attempt.
     *
     * @param bool $autograde Whether autograde is enabled for the assignment.
     * @param bool $withgrader Whether the assignment has a configured grader.
     * @return array [assign, student, grader, reviewer, pending record or null]
     */
    private function create_scenario(bool $autograde, bool $withgrader = true): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student', ['firstname' => 'Zebulon']);
        $grader = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $reviewer = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
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
            'assignfeedback_comments_enabled' => 1,
        ]);
        $instanceid = (int) $assign->get_instance()->id;
        $DB->set_field('local_assign_ai_config', 'autograde', $autograde ? 1 : 0, ['assignmentid' => $instanceid]);
        $DB->set_field('local_assign_ai_config', 'graderid', $withgrader ? $grader->id : null, ['assignmentid' => $instanceid]);

        $this->add_submission($student, $assign, self::SUBMISSION_TEXT);
        if ($autograde) {
            return [$assign, $student, $grader, $reviewer, null];
        }

        (new assign_submission((int) $student->id, $assign))->process_submission_ai();
        $record = $DB->get_record('local_assign_ai_pending', ['userid' => $student->id], '*', MUST_EXIST);
        return [$assign, $student, $grader, $reviewer, $record];
    }

    /**
     * Run the manual review of a record as the queued task does, capturing the events.
     *
     * @param \assign $assign Assignment.
     * @param \stdClass $student Student.
     * @param \stdClass $reviewer Teacher who requested the review.
     * @param \stdClass $record Pending record.
     * @return submission_sent_to_ai[] Transfer events triggered.
     */
    private function review_and_capture(\assign $assign, \stdClass $student, \stdClass $reviewer, \stdClass $record): array {
        $sink = $this->redirectEvents();
        $proc = new assign_submission((int) $student->id, $assign);
        $proc->set_reviewerid((int) $reviewer->id);
        $proc->process_submission_ai_review((int) $record->id);
        $this->resetDebugging();
        $events = $this->transfer_events($sink->get_events());
        $sink->close();
        return $events;
    }

    /**
     * Keep only the transfer events.
     *
     * @param array $events Captured events.
     * @return submission_sent_to_ai[]
     */
    private function transfer_events(array $events): array {
        return array_values(array_filter($events, fn($e) => $e instanceof submission_sent_to_ai));
    }

    /**
     * Assert the event carries only the allowed metadata and none of the sent or received content.
     *
     * @param submission_sent_to_ai $event The event.
     * @param \stdClass $student The student.
     * @return void
     */
    private function assert_no_content(submission_sent_to_ai $event, \stdClass $student): void {
        $logged = json_encode($event->get_data()) . $event->get_description();
        $this->assertStringNotContainsString(self::SUBMISSION_TEXT, $logged);
        $this->assertStringNotContainsString(self::AI_REPLY, $logged);
        $this->assertStringNotContainsString('Zebulon', $logged);
        $this->assertStringNotContainsString(fullname($student), $logged);
    }

    /**
     * A successful manual review logs one transfer with the reviewer as actor and only metadata.
     */
    public function test_successful_review_logs_transfer_without_content(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->fake_ai_client(fn() => ['reply' => self::AI_REPLY, 'grade' => 7]);

        [$assign, $student, , $reviewer, $record] = $this->create_scenario(false);
        $events = $this->review_and_capture($assign, $student, $reviewer, $record);

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertEquals($reviewer->id, $event->userid);
        $this->assertEquals($student->id, $event->relateduserid);
        $this->assertEquals($assign->get_context()->id, $event->contextid);
        $this->assertEquals($assign->get_course()->id, $event->courseid);
        $this->assertEquals($record->submissionid, $event->objectid);
        $this->assertSame('r', $event->crud);
        $this->assertSame(['endpoint' => '/assign/answer', 'result' => 'success'], $event->other);
        $this->assert_no_content($event, $student);
        $this->assertNotEmpty($event->get_name());
        $this->assertNotEmpty($event->get_description());
        $this->assertInstanceOf(\moodle_url::class, $event->get_url());
    }

    /**
     * A failed transfer is logged too, with the error code of the provider exception.
     */
    public function test_failed_review_logs_transfer_with_errorcode(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectOutputRegex('/.*/s');
        $this->fake_ai_client(function (): array {
            throw new \moodle_exception('httperror', 'aiprovider_datacurso', '', 502);
        });

        [$assign, $student, , $reviewer, $record] = $this->create_scenario(false);
        $events = $this->review_and_capture($assign, $student, $reviewer, $record);

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertEquals($reviewer->id, $event->userid);
        $this->assertEquals($student->id, $event->relateduserid);
        $this->assertEquals($assign->get_context()->id, $event->contextid);
        $this->assertSame(
            ['endpoint' => '/assign/answer', 'result' => 'failure', 'errorcode' => 'httperror'],
            $event->other
        );
        $this->assert_no_content($event, $student);
    }

    /**
     * A failure that is not a Moodle exception is logged with the short class name, never its message.
     */
    public function test_failed_transfer_with_generic_error_logs_class_name(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->fake_ai_client(function (): array {
            throw new \RuntimeException('Connection refused while sending ' . self::SUBMISSION_TEXT);
        });

        [$assign, $student, , $reviewer, $record] = $this->create_scenario(false);
        $events = $this->review_and_capture($assign, $student, $reviewer, $record);

        $this->assertCount(1, $events);
        $this->assertSame(
            ['endpoint' => '/assign/answer', 'result' => 'failure', 'errorcode' => 'RuntimeException'],
            $events[0]->other
        );
        $this->assert_no_content($events[0], $student);
    }

    /**
     * A needs_review answer is still a completed transfer: the service received the submission.
     */
    public function test_needs_review_answer_is_logged_as_completed_transfer(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectOutputRegex('/.*/s');
        $this->fake_ai_client(fn() => [
            'reply' => self::AI_REPLY,
            'needs_review' => true,
            'review_reason' => 'unreadable_file',
        ]);

        [$assign, $student, , $reviewer, $record] = $this->create_scenario(false);
        $events = $this->review_and_capture($assign, $student, $reviewer, $record);

        $this->assertCount(1, $events);
        $this->assertSame(['endpoint' => '/assign/answer', 'result' => 'success'], $events[0]->other);
        $this->assert_no_content($events[0], $student);
    }

    /**
     * Autograde logs the transfer with the configured grader as actor.
     */
    public function test_autograde_logs_transfer_with_grader_as_actor(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->fake_ai_client(fn() => ['reply' => self::AI_REPLY, 'grade' => 7]);

        [$assign, $student, $grader] = $this->create_scenario(true);
        $sink = $this->redirectEvents();
        (new assign_submission((int) $student->id, $assign))->process_submission_ai();
        // Applying the grade emits developer debugging messages that are not under test.
        $this->resetDebugging();
        $events = $this->transfer_events($sink->get_events());
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertEquals($grader->id, $events[0]->userid);
        $this->assertEquals($student->id, $events[0]->relateduserid);
        $this->assertEquals($assign->get_context()->id, $events[0]->contextid);
        $this->assertSame(['endpoint' => '/assign/answer', 'result' => 'success'], $events[0]->other);
        $this->assert_no_content($events[0], $student);
    }

    /**
     * Without a reviewer or a configured grader the transfer is attributed to the system (user 0).
     */
    public function test_autograde_without_grader_logs_system_actor(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->fake_ai_client(fn() => ['reply' => self::AI_REPLY, 'grade' => 7]);

        [$assign, $student] = $this->create_scenario(true, false);

        $sink = $this->redirectEvents();
        (new assign_submission((int) $student->id, $assign))->process_submission_ai();
        // Applying the grade emits developer debugging messages that are not under test.
        $this->resetDebugging();
        $events = $this->transfer_events($sink->get_events());
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertEquals(0, $events[0]->userid);
        $this->assertEquals($student->id, $events[0]->relateduserid);
    }

    /**
     * Nothing is logged when the submission is never sent (the files exceed the size limit).
     */
    public function test_submission_not_sent_logs_nothing(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->fake_ai_client(fn() => ['reply' => self::AI_REPLY, 'grade' => 7]);

        [$assign, $student, , $reviewer, $record] = $this->create_scenario(false);
        $submission = $assign->get_user_submission($student->id, false);
        $file = get_file_storage()->create_file_from_string([
            'contextid' => $assign->get_context()->id,
            'component' => 'assignsubmission_file',
            'filearea' => 'submission_files',
            'itemid' => $submission->id,
            'filepath' => '/',
            'filename' => 'big.bin',
        ], 'tiny');
        $DB->set_field('files', 'filesize', 31 * 1048576, ['id' => $file->get_id()]);

        $this->assertCount(0, $this->review_and_capture($assign, $student, $reviewer, $record));
    }

    /**
     * The event refuses data without the transfer metadata.
     */
    public function test_event_requires_relateduser_and_result(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->create_instance($course);

        $this->expectException(\coding_exception::class);
        submission_sent_to_ai::create([
            'context' => $assign->get_context(),
            'objectid' => 1,
            'other' => ['endpoint' => '/assign/answer'],
        ]);
    }
}
