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
 * Tests for the delayed AI processing queue.
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
require_once($CFG->dirroot . '/webservice/lib.php');

/**
 * Unit tests for the delayed AI processing queue and its scheduled task.
 *
 * These tests trigger the real mod_assign events (save through the assign API) and rely on the
 * registered non-internal observers, so every test disposes the test-wide transaction via
 * preventResetByRollback() before triggering events.
 *
 * @coversDefaultClass \local_assign_ai\task\process_ai_queue
 * @group local_assign_ai
 */
final class process_ai_queue_task_test extends \advanced_testcase {
    use \mod_assign_test_generator;

    /**
     * Configure the Datacurso AI provider so real pipeline calls can run against curl mocks.
     *
     * The /assign/answer endpoint requires the Datacurso webservice to be fully configured
     * (webservices + REST enabled, service user, role, external service, token and a verified
     * registration), so this seeds all of it.
     *
     * @return void
     */
    private function configure_ai_provider(): void {
        global $DB;

        set_config('licensekey', 'phpunit-license-key', 'aiprovider_datacurso');
        set_config('site_uuid', 'phpunit-site-uuid', 'aiprovider_datacurso');
        set_config('registration_verified', 1, 'aiprovider_datacurso');
        set_config('enablewebservices', 1);
        set_config('webserviceprotocols', 'rest');

        $wsuser = $this->getDataGenerator()->create_user([
            'username' => \aiprovider_datacurso\webservice_config::USERNAME,
        ]);
        $roleid = create_role('Datacurso WS', \aiprovider_datacurso\webservice_config::ROLESHORTNAME, '');
        $systemcontext = \context_system::instance();
        role_assign($roleid, $wsuser->id, $systemcontext->id);

        $webservicemanager = new \webservice();
        $serviceid = $webservicemanager->add_external_service((object) [
            'name' => \aiprovider_datacurso\webservice_config::SERVICENAME,
            'shortname' => \aiprovider_datacurso\webservice_config::SERVICESHORTNAME,
            'enabled' => 1,
            'restrictedusers' => 1,
            'downloadfiles' => 0,
            'uploadfiles' => 0,
        ]);
        $service = $DB->get_record('external_services', ['id' => $serviceid], '*', MUST_EXIST);
        \core_external\util::generate_token(EXTERNAL_TOKEN_PERMANENT, $service, $wsuser->id, $systemcontext);
    }

    /**
     * Queue the mocked HTTP responses consumed by one client::send_to_ai() call.
     *
     * One AI review makes four HTTP requests (region lookup, webservice status region lookup,
     * registration status, and the final /assign/answer POST). Mock responses are consumed in
     * LIFO order, so the /assign/answer body is queued first.
     *
     * @param string $answerbody Body returned for the final /assign/answer POST.
     * @return void
     */
    private function mock_ai_pipeline(string $answerbody): void {
        $infra = json_encode(['is_for_eu' => false, 'is_registered' => true]);
        \curl::mock_response($answerbody);
        \curl::mock_response($infra);
        \curl::mock_response($infra);
        \curl::mock_response($infra);
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
     * Configure the delay queue (and autograde) on the auto-created assignment config row.
     *
     * @param int $assignmentid Assignment instance id.
     * @param int $delayminutes Delay in minutes before AI processing.
     * @return void
     */
    private function enable_delay(int $assignmentid, int $delayminutes): void {
        global $DB;

        $DB->set_field('local_assign_ai_config', 'usedelay', 1, ['assignmentid' => $assignmentid]);
        $DB->set_field('local_assign_ai_config', 'delayminutes', $delayminutes, ['assignmentid' => $assignmentid]);
        $DB->set_field('local_assign_ai_config', 'autograde', 1, ['assignmentid' => $assignmentid]);
    }

    /**
     * MDL-INT-010: With the delay enabled, a submission inserts a queue row scheduled at the
     * submission time plus the delay, is not processed immediately, and the scheduled task
     * leaves it untouched before its time arrives.
     *
     * @covers ::execute
     */
    public function test_delayed_submission_is_queued_and_not_processed_early(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->redirectMessages();
        $this->redirectEmails();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(3010, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);
        $this->enable_delay((int) $assign->get_instance()->id, 30);

        $this->add_submission($student, $assign, 'Delayed essay');

        // The submission is queued for later processing instead of being processed now.
        $rows = $DB->get_records('local_assign_ai_queue', ['type' => 'submission']);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertEquals(0, $row->processed);

        $submission = $assign->get_user_submission($student->id, false);
        $submissiontime = max((int) $submission->timecreated, (int) $submission->timemodified);
        $this->assertEquals($submissiontime + (30 * MINSECS), (int) $row->timetoprocess);

        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(\local_assign_ai\task\process_submission_ai::class));
        $this->assertSame(0, $DB->count_records('local_assign_ai_pending'));

        // Running the scheduled task before the queued time leaves everything unprocessed.
        (new \local_assign_ai\task\process_ai_queue())->execute();

        $row = $DB->get_record('local_assign_ai_queue', ['id' => $row->id], '*', MUST_EXIST);
        $this->assertEquals(0, $row->processed);
        $this->assertSame(0, $DB->count_records('local_assign_ai_pending'));
    }

    /**
     * MDL-INT-010: Editing the submission replaces the queued row, resetting the delay timer.
     *
     * @covers ::execute
     */
    public function test_submission_edit_replaces_queued_row_and_resets_timer(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->redirectMessages();
        $this->redirectEmails();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(3020, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);
        $this->enable_delay((int) $assign->get_instance()->id, 30);

        $this->add_submission($student, $assign, 'Delayed essay');

        $rows = $DB->get_records('local_assign_ai_queue', ['type' => 'submission']);
        $this->assertCount(1, $rows);
        $oldrow = reset($rows);

        // Pretend the first row was about to be processed, then the student edits the submission.
        $DB->set_field('local_assign_ai_queue', 'timetoprocess', time() - 600, ['id' => $oldrow->id]);

        $this->add_submission($student, $assign, 'Edited delayed essay');

        $rows = $DB->get_records('local_assign_ai_queue', ['type' => 'submission']);
        $this->assertCount(1, $rows);
        $newrow = reset($rows);
        $this->assertNotEquals($oldrow->id, $newrow->id);
        $this->assertGreaterThan(time(), (int) $newrow->timetoprocess);
        $this->assertFalse($DB->record_exists('local_assign_ai_queue', ['id' => $oldrow->id]));
    }

    /**
     * MDL-INT-010: Once the delay has elapsed, the scheduled task processes the submission
     * against the mocked AI service and marks the queue row as processed.
     *
     * @covers ::execute
     */
    public function test_task_processes_submission_after_delay_elapsed(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->redirectMessages();
        $this->redirectEmails();

        $this->configure_ai_provider();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(3030, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);
        $this->enable_delay((int) $assign->get_instance()->id, 30);

        $this->add_submission($student, $assign, 'Delayed essay');

        $rows = $DB->get_records('local_assign_ai_queue', ['type' => 'submission']);
        $this->assertCount(1, $rows);
        $row = reset($rows);

        // Move the submission and the queue row back in time so the delay has elapsed.
        $submission = $assign->get_user_submission($student->id, false);
        $DB->set_field('assign_submission', 'timecreated', $submission->timecreated - HOURSECS,
            ['id' => $submission->id]);
        $DB->set_field('assign_submission', 'timemodified', $submission->timemodified - HOURSECS,
            ['id' => $submission->id]);
        $DB->set_field('local_assign_ai_queue', 'timetoprocess', time() - (30 * MINSECS), ['id' => $row->id]);

        $this->mock_ai_pipeline(json_encode([
            'reply' => 'Delayed feedback',
            'grade' => 8,
            'rubric' => null,
            'assessment_guide' => null,
        ]));

        (new \local_assign_ai\task\process_ai_queue())->execute();

        $row = $DB->get_record('local_assign_ai_queue', ['id' => $row->id], '*', MUST_EXIST);
        $this->assertEquals(1, $row->processed);

        $record = $DB->get_record('local_assign_ai_pending', ['userid' => $student->id], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_APPROVED, $record->status);
        $this->assertEquals(8, $record->grade);
        $this->assertSame('Delayed feedback', $record->message);
    }
}
