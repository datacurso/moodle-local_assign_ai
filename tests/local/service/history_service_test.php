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

namespace local_assign_ai\local\service;

use local_assign_ai\assign_submission;

/**
 * Tests for the record actions of the history page (retry and log view).
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \local_assign_ai\local\service\history_service
 * @group     local_assign_ai
 */
final class history_service_test extends \advanced_testcase {
    /**
     * Skips every test when the Datacurso AI provider is not installed.
     */
    protected function setUp(): void {
        parent::setUp();
        // The CI pipeline does not install declared dependencies; without the Datacurso AI provider these tests cannot run.
        if (!class_exists(\aiprovider_datacurso\local\plugin_settings::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed.');
        }
    }

    /**
     * Creates two assignments, each with a pending record, and a teacher of the first course.
     *
     * @param string $status Status of the record of the first assignment.
     * @return array [cm, own record, foreign record, teacher]
     */
    private function create_scenario(string $status = 'failed'): array {
        $generator = $this->getDataGenerator();
        $aigenerator = $generator->get_plugin_generator('local_assign_ai');

        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $student = $generator->create_and_enrol($course, 'student');
        $instance = $generator->create_module('assign', ['course' => $course->id]);
        [, $cm] = get_course_and_cm_from_instance($instance->id, 'assign');
        $own = $aigenerator->create_pending([
            'assignmentid' => $cm->id,
            'userid' => $student->id,
            'status' => $status,
        ]);

        // The other activity lives in another course where the teacher also teaches, so the only
        // thing that must stop the request is the record not belonging to the requested activity.
        $othercourse = $generator->create_course();
        $generator->enrol_user($teacher->id, $othercourse->id, 'editingteacher');
        $otherstudent = $generator->create_and_enrol($othercourse, 'student');
        $otherinstance = $generator->create_module('assign', ['course' => $othercourse->id]);
        [, $othercm] = get_course_and_cm_from_instance($otherinstance->id, 'assign');
        $foreign = $aigenerator->create_pending([
            'assignmentid' => $othercm->id,
            'userid' => $otherstudent->id,
            'status' => 'failed',
        ]);

        return [$cm, $own, $foreign, $teacher];
    }

    /**
     * Runs a call and returns the exception it throws.
     *
     * @param callable $call The call expected to fail.
     * @return \Throwable The thrown exception.
     */
    private function capture(callable $call): \Throwable {
        try {
            $call();
        } catch (\Throwable $e) {
            return $e;
        }
        $this->fail('An exception was expected.');
    }

    /**
     * Assert two failures cannot be told apart.
     *
     * @param \Throwable $absent Failure for an id that does not exist.
     * @param \Throwable $foreign Failure for an id of another activity.
     * @return void
     */
    private function assert_same_failure(\Throwable $absent, \Throwable $foreign): void {
        $this->assertInstanceOf(\moodle_exception::class, $absent);
        $this->assertSame(get_class($absent), get_class($foreign));
        $this->assertSame('invalidpendingrecord', $absent->errorcode);
        $this->assertSame($absent->errorcode, $foreign->errorcode);
        $this->assertSame($absent->module, $foreign->module);
        $this->assertSame($absent->getMessage(), $foreign->getMessage());
    }

    /**
     * The log of a record of the activity is returned.
     *
     * @covers ::get_log_record
     */
    public function test_get_log_record_returns_a_record_of_the_activity(): void {
        $this->resetAfterTest();
        [$cm, $own, , $teacher] = $this->create_scenario();
        $this->setUser($teacher);

        $record = history_service::get_log_record((int) $cm->id, (int) $own->id);

        $this->assertSame((int) $own->id, (int) $record->id);
    }

    /**
     * Viewing the log of an absent id and of an id of another activity fail the same way.
     *
     * @covers ::get_log_record
     */
    public function test_get_log_record_fails_the_same_for_absent_and_foreign_ids(): void {
        $this->resetAfterTest();
        [$cm, , $foreign, $teacher] = $this->create_scenario();
        $this->setUser($teacher);

        $absent = $this->capture(fn() => history_service::get_log_record((int) $cm->id, (int) $foreign->id + 1000));
        $other = $this->capture(fn() => history_service::get_log_record((int) $cm->id, (int) $foreign->id));

        $this->assert_same_failure($absent, $other);
    }

    /**
     * Retrying an absent id and an id of another activity fail the same way and queue nothing.
     *
     * @covers ::retry_review
     */
    public function test_retry_review_fails_the_same_for_absent_and_foreign_ids(): void {
        global $DB;

        $this->resetAfterTest();
        [$cm, , $foreign, $teacher] = $this->create_scenario();
        $this->setUser($teacher);

        $absent = $this->capture(fn() => history_service::retry_review((int) $cm->id, (int) $foreign->id + 1000));
        $other = $this->capture(fn() => history_service::retry_review((int) $cm->id, (int) $foreign->id));

        $this->assert_same_failure($absent, $other);
        $status = $DB->get_field('local_assign_ai_pending', 'status', ['id' => $foreign->id]);
        $this->assertSame(assign_submission::STATUS_FAILED, $status);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(\local_assign_ai\task\process_review_submission::class));
    }

    /**
     * A failed record of the activity is re-queued in place.
     *
     * @covers ::retry_review
     */
    public function test_retry_review_requeues_a_failed_record(): void {
        global $DB;

        $this->resetAfterTest();
        [$cm, $own, , $teacher] = $this->create_scenario();
        $this->setUser($teacher);

        history_service::retry_review((int) $cm->id, (int) $own->id);

        $status = $DB->get_field('local_assign_ai_pending', 'status', ['id' => $own->id]);
        $this->assertSame(assign_submission::STATUS_QUEUED, $status);
        $tasks = \core\task\manager::get_adhoc_tasks(\local_assign_ai\task\process_review_submission::class);
        $this->assertCount(1, $tasks);
    }

    /**
     * An approved record is kept and a new queued attempt is created instead.
     *
     * @covers ::retry_review
     */
    public function test_retry_review_of_an_approved_record_creates_a_new_attempt(): void {
        global $DB;

        $this->resetAfterTest();
        [$cm, $own, , $teacher] = $this->create_scenario(assign_submission::STATUS_APPROVED);
        $this->setUser($teacher);

        history_service::retry_review((int) $cm->id, (int) $own->id);

        $status = $DB->get_field('local_assign_ai_pending', 'status', ['id' => $own->id]);
        $this->assertSame(assign_submission::STATUS_APPROVED, $status);
        $this->assertSame(1, $DB->count_records('local_assign_ai_pending', [
            'assignmentid' => $cm->id,
            'status' => assign_submission::STATUS_QUEUED,
        ]));
    }

    /**
     * A user without the review capability is rejected before any record is looked up.
     *
     * @covers ::get_log_record
     */
    public function test_get_log_record_requires_the_review_capability(): void {
        $this->resetAfterTest();
        [$cm, $own] = $this->create_scenario();
        $student = $this->getDataGenerator()->create_and_enrol(get_course($cm->course), 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        history_service::get_log_record((int) $cm->id, (int) $own->id);
    }
}
