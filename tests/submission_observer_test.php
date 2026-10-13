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
     * Moodle 4.5 reads the license key from the plugin config and Moodle 5.0 from an enabled
     * provider instance, so both are set; site_uuid is set for determinism.
     *
     * @return void
     */
    private function configure_ai_provider(): void {
        global $DB;

        // The CI pipeline does not install declared dependencies, so without the Datacurso provider
        // there is no AI client to call and these pipeline tests have nothing to measure.
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the AI pipeline cannot run.');
        }

        set_config('licensekey', 'phpunit-license-key', 'aiprovider_datacurso');
        set_config('site_uuid', 'phpunit-site-uuid', 'aiprovider_datacurso');

        // aiprovider_datacurso 1.6.0 remembers the licence region in its config (fingerprinted by
        // the licence key) for a week, so it is preset here and no region lookup happens during
        // the tests; every curl mock is therefore consumed by the /assign/answer POST only.
        set_config(\aiprovider_datacurso\local\license_region::REGION, '0', 'aiprovider_datacurso');
        set_config(
            \aiprovider_datacurso\local\license_region::FINGERPRINT,
            sha1('phpunit-license-key'),
            'aiprovider_datacurso'
        );
        set_config(\aiprovider_datacurso\local\license_region::CHECKED, time(), 'aiprovider_datacurso');

        // Moodle 5.0 reads the license key from an enabled provider instance instead of the plugin config.
        $manager = new \core_ai\manager($DB);
        if (method_exists($manager, 'create_provider_instance')) {
            $manager->create_provider_instance(
                classname: \aiprovider_datacurso\provider::class,
                name: 'phpunit',
                enabled: true,
                config: ['licensekey' => 'phpunit-license-key'],
            );
        }
    }

    /**
     * Queue the mocked HTTP responses consumed by one client::send_to_ai() call.
     *
     * The licence region is preset by configure_ai_provider(), so one AI review makes a single
     * HTTP request: the /assign/answer POST.
     *
     * @param string $answerbody Body returned for the final /assign/answer POST.
     * @return void
     */
    private function mock_ai_pipeline(string $answerbody): void {
        \curl::mock_response($answerbody);
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
        $DB->set_field(
            'local_assign_ai_config',
            'autograde',
            1,
            ['assignmentid' => $assign->get_instance()->id]
        );

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

    /**
     * Insert a delayed-processing queue row with the given payload identifiers.
     *
     * The identifiers are stored exactly as given (int or string) so the tests can cover both
     * the numeric and the string JSON encodings the queue has historically contained.
     *
     * @param int|string $userid User id as it should appear in the JSON payload.
     * @param int|string $cmid Course module id as it should appear in the JSON payload.
     * @param string $type Queue row type.
     * @param int $processed Whether the row has already been processed.
     * @return int The inserted row id.
     */
    private function create_queue_row($userid, $cmid, string $type = 'submission', int $processed = 0): int {
        global $DB;

        return $DB->insert_record('local_assign_ai_queue', (object) [
            'type' => $type,
            'payload' => json_encode(['userid' => $userid, 'cmid' => $cmid, 'submissiontime' => time()]),
            'timecreated' => time(),
            'timetoprocess' => time() + HOURSECS,
            'processed' => $processed,
        ]);
    }

    /**
     * Seed the queue with two rows for the target user/activity (numeric and string encoded ids)
     * plus decoy rows that share a digit prefix, belong to another user, another activity or
     * another queue type.
     *
     * @param int $userid Target user id.
     * @param int $cmid Target course module id.
     * @param int $otheruserid A different user id.
     * @return array{targets: int[], decoys: int[]} Inserted row ids.
     */
    private function seed_queue_fixture(int $userid, int $cmid, int $otheruserid): array {
        return [
            'targets' => [
                $this->create_queue_row($userid, $cmid),
                $this->create_queue_row((string) $userid, (string) $cmid),
            ],
            'decoys' => [
                // Same digit prefix: user 12 must not match user 123, cm 5 must not match cm 50.
                $this->create_queue_row((int) ($userid . '3'), $cmid),
                $this->create_queue_row($userid, (int) ($cmid . '0')),
                $this->create_queue_row($otheruserid, $cmid),
                $this->create_queue_row($userid, $cmid, 'other'),
            ],
        ];
    }

    /**
     * LAA-SEC-005: Removing a submission clears only the queue rows of that exact user in that
     * exact activity; rows whose ids merely share a digit prefix, rows of other users, other
     * activities or other queue types are kept.
     *
     * @covers ::submission_removed
     */
    public function test_removing_a_submission_only_clears_the_queue_rows_of_that_user_and_activity(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->redirectMessages();
        $this->redirectEmails();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(1050, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);
        $cmid = (int) $assign->get_course_module()->id;

        // Keep the master switch off while seeding the submission so nothing is queued by the observers.
        set_config('enableassignai', 0, 'local_assign_ai');
        $this->add_submission($student, $assign, 'Essay text');

        $rows = $this->seed_queue_fixture((int) $student->id, $cmid, (int) $other->id);

        $this->assertTrue($assign->remove_submission($student->id));

        foreach ($rows['targets'] as $id) {
            $this->assertFalse($DB->record_exists('local_assign_ai_queue', ['id' => $id]), "Target row {$id} must be deleted");
        }
        foreach ($rows['decoys'] as $id) {
            $this->assertTrue($DB->record_exists('local_assign_ai_queue', ['id' => $id]), "Decoy row {$id} must be kept");
        }
    }

    /**
     * LAA-SEC-005: A delayed submission replaces the previously queued rows of that exact user
     * in that exact activity (numeric or string encoded ids) and leaves every other queue row alone.
     *
     * @covers ::submission_created
     */
    public function test_delayed_submission_replaces_only_the_queue_rows_of_that_user_and_activity(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->redirectMessages();
        $this->redirectEmails();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(1060, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);
        $cmid = (int) $assign->get_course_module()->id;
        $DB->set_field('local_assign_ai_config', 'usedelay', 1, ['assignmentid' => $assign->get_instance()->id]);
        $DB->set_field('local_assign_ai_config', 'delayminutes', 30, ['assignmentid' => $assign->get_instance()->id]);

        $rows = $this->seed_queue_fixture((int) $student->id, $cmid, (int) $other->id);

        $this->add_submission($student, $assign, 'Essay text');

        foreach ($rows['targets'] as $id) {
            $this->assertFalse($DB->record_exists('local_assign_ai_queue', ['id' => $id]), "Stale row {$id} must be replaced");
        }
        foreach ($rows['decoys'] as $id) {
            $this->assertTrue($DB->record_exists('local_assign_ai_queue', ['id' => $id]), "Decoy row {$id} must be kept");
        }

        // Exactly one fresh row remains for this user in this activity.
        $fresh = 0;
        foreach ($DB->get_records('local_assign_ai_queue', ['type' => 'submission']) as $row) {
            $data = json_decode($row->payload);
            if ((int) $data->userid === (int) $student->id && (int) $data->cmid === $cmid) {
                $fresh++;
            }
        }
        $this->assertSame(1, $fresh);
    }

    /**
     * RES-001: Only unprocessed queue rows can still be cancelled, so removing a submission
     * deletes the pending row of that user and activity but leaves the already processed
     * history row untouched.
     *
     * @covers ::submission_removed
     */
    public function test_removing_a_submission_keeps_already_processed_queue_rows(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->redirectMessages();
        $this->redirectEmails();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(1070, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);
        $cmid = (int) $assign->get_course_module()->id;

        // Keep the master switch off while seeding the submission so nothing is queued by the observers.
        set_config('enableassignai', 0, 'local_assign_ai');
        $this->add_submission($student, $assign, 'Essay text');

        $processed = $this->create_queue_row((int) $student->id, $cmid, 'submission', 1);
        $pending = $this->create_queue_row((int) $student->id, $cmid, 'submission', 0);

        $this->assertTrue($assign->remove_submission($student->id));

        $this->assertTrue($DB->record_exists('local_assign_ai_queue', ['id' => $processed]), 'Processed row must be kept');
        $this->assertFalse($DB->record_exists('local_assign_ai_queue', ['id' => $pending]), 'Pending row must be deleted');
    }
}
