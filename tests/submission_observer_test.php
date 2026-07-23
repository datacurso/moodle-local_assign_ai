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
 * Tests for the submission event observers.
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
 * Unit tests for the submission event observers.
 *
 * These tests trigger the real mod_assign events (save/submit through the assign API) and rely
 * on the registered non-internal observers, so every test disposes the test-wide transaction
 * via preventResetByRollback() before triggering events.
 *
 * @coversDefaultClass \local_assign_ai\observer\submission
 * @group local_assign_ai
 */
final class submission_observer_test extends \advanced_testcase {
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
     * Count the queued ad-hoc tasks of the given class.
     *
     * @param string $classname Fully qualified task class name.
     * @return int
     */
    private function count_adhoc_tasks(string $classname): int {
        return count(\core\task\manager::get_adhoc_tasks($classname));
    }

    /**
     * MDL-INT-003: With submission drafts enabled, saving a draft neither creates a pending
     * record nor queues any processing; only submitting for grading registers the submission.
     *
     * @covers ::submission_created
     * @covers ::assessable_submitted
     */
    public function test_draft_save_is_ignored_until_submitted_for_grading(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->redirectMessages();
        $this->redirectEmails();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(1010, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 1,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);

        $this->add_submission($student, $assign, 'Draft essay text');

        $this->assertSame(0, $DB->count_records('local_assign_ai_queue'));
        $this->assertSame(0, $this->count_adhoc_tasks(\local_assign_ai\task\process_submission_ai::class));
        $this->assertSame(0, $DB->count_records('local_assign_ai_pending'));

        $this->submit_for_grading($student, $assign);

        $this->assertSame(1, $this->count_adhoc_tasks(\local_assign_ai\task\process_submission_ai::class));

        \phpunit_util::run_all_adhoc_tasks();

        $records = $DB->get_records('local_assign_ai_pending');
        $this->assertCount(1, $records);
        $record = reset($records);
        $this->assertSame(assign_submission::STATUS_INITIAL, $record->status);
        $this->assertEquals($student->id, $record->userid);
        $this->assertEquals($assign->get_course_module()->id, $record->assignmentid);
    }

    /**
     * MDL-INT-003: With submission drafts disabled, saving the submission registers it immediately.
     *
     * @covers ::submission_created
     */
    public function test_submission_without_drafts_is_registered_immediately(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->redirectMessages();
        $this->redirectEmails();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(1020, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);

        $this->add_submission($student, $assign, 'Essay text');

        $this->assertSame(1, $this->count_adhoc_tasks(\local_assign_ai\task\process_submission_ai::class));

        \phpunit_util::run_all_adhoc_tasks();

        $records = $DB->get_records('local_assign_ai_pending');
        $this->assertCount(1, $records);
        $record = reset($records);
        $this->assertSame(assign_submission::STATUS_INITIAL, $record->status);
        $this->assertEquals($student->id, $record->userid);
    }

    /**
     * MDL-INT-012: After a finalized (approved) record, a student edit with autograde enabled
     * queues a new evaluation that is stored as a separate record flagged as edited.
     *
     * @covers ::submission_updated
     */
    public function test_student_edit_after_finalized_record_creates_new_edited_evaluation(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->redirectMessages();
        $this->redirectEmails();

        $this->configure_ai_provider();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(1030, $course->id);

        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignfeedback_comments_enabled' => 1,
        ]);

        // Keep the master switch off while seeding the initial submission so the observers stay quiet.
        set_config('enableassignai', 0, 'local_assign_ai');
        $this->add_submission($student, $assign, 'Original essay');
        set_config('enableassignai', 1, 'local_assign_ai');

        $cmid = $assign->get_course_module()->id;
        $DB->set_field('local_assign_ai_config', 'autograde', 1,
            ['assignmentid' => $assign->get_instance()->id]);

        // Simulate an evaluation that was already finalized before the student edit.
        $submission = $assign->get_user_submission($student->id, false);
        $finalizedid = assign_submission::create_pending_submission((object) [
            'courseid' => $course->id,
            'assignmentid' => $cmid,
            'userid' => $student->id,
            'submissionid' => (int) $submission->id,
            'attemptnumber' => (int) $submission->attemptnumber,
            'submissionmodified' => (int) $submission->timemodified - 10,
            'title' => $assign->get_instance()->name,
            'message' => 'Old feedback',
            'grade' => 7,
            'status' => assign_submission::STATUS_APPROVED,
        ]);

        $this->mock_ai_pipeline(json_encode([
            'reply' => 'Feedback for the edited essay',
            'grade' => 9,
            'rubric' => null,
            'assessment_guide' => null,
        ]));

        $this->add_submission($student, $assign, 'Edited essay');
        \phpunit_util::run_all_adhoc_tasks();

        $records = $DB->get_records('local_assign_ai_pending', ['submissionid' => $submission->id], 'id ASC');
        $this->assertCount(2, $records);

        $finalized = $records[$finalizedid];
        $this->assertSame(assign_submission::STATUS_APPROVED, $finalized->status);
        $this->assertEquals(7, $finalized->grade);
        $this->assertEquals(0, $finalized->edited);

        unset($records[$finalizedid]);
        $newrecord = reset($records);
        $this->assertSame(assign_submission::STATUS_APPROVED, $newrecord->status);
        $this->assertEquals(9, $newrecord->grade);
        $this->assertSame('Feedback for the edited essay', $newrecord->message);
        $this->assertEquals(1, $newrecord->edited);
    }

    /**
     * MDL-INT-012: After a finalized record, a student edit with autograde disabled lands the new
     * evaluation as an initial record (pending for the teacher to send), also flagged as edited.
     *
     * @covers ::submission_updated
     */
    public function test_student_edit_with_autograde_off_creates_initial_record(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->redirectMessages();
        $this->redirectEmails();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(1040, $course->id);

        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);

        // Keep the master switch off while seeding the initial submission so the observers stay quiet.
        set_config('enableassignai', 0, 'local_assign_ai');
        $this->add_submission($student, $assign, 'Original essay');
        set_config('enableassignai', 1, 'local_assign_ai');

        $cmid = $assign->get_course_module()->id;

        // Simulate an evaluation that was already finalized before the student edit.
        $submission = $assign->get_user_submission($student->id, false);
        $finalizedid = assign_submission::create_pending_submission((object) [
            'courseid' => $course->id,
            'assignmentid' => $cmid,
            'userid' => $student->id,
            'submissionid' => (int) $submission->id,
            'attemptnumber' => (int) $submission->attemptnumber,
            'submissionmodified' => (int) $submission->timemodified - 10,
            'title' => $assign->get_instance()->name,
            'message' => 'Old feedback',
            'grade' => 7,
            'status' => assign_submission::STATUS_APPROVED,
        ]);

        $this->add_submission($student, $assign, 'Edited essay');
        \phpunit_util::run_all_adhoc_tasks();

        $records = $DB->get_records('local_assign_ai_pending', ['submissionid' => $submission->id], 'id ASC');
        $this->assertCount(2, $records);

        $finalized = $records[$finalizedid];
        $this->assertSame(assign_submission::STATUS_APPROVED, $finalized->status);

        unset($records[$finalizedid]);
        $newrecord = reset($records);
        $this->assertSame(assign_submission::STATUS_INITIAL, $newrecord->status);
        $this->assertNull($newrecord->grade);
        $this->assertEquals(1, $newrecord->edited);
    }
}
