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
 * Tests for the cancel_review external function.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai\external;

use local_assign_ai\assign_submission;

/**
 * LAA-SEC-006: cancel_review authorizes first and does not reveal records of other activities.
 *
 * @coversDefaultClass \local_assign_ai\external\cancel_review
 * @group local_assign_ai
 */
final class cancel_review_test extends \advanced_testcase {
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
     * Creates an assignment with a teacher and a student whose review is queued.
     *
     * @return array [course, cmid, teacher, queued record]
     */
    private function create_scenario(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        [, $cm] = get_course_and_cm_from_instance($instance->id, 'assign');
        $pending = $this->getDataGenerator()->get_plugin_generator('local_assign_ai')->create_pending([
            'assignmentid' => $cm->id,
            'userid' => $student->id,
            'status' => assign_submission::STATUS_QUEUED,
        ]);

        return [$course, (int) $cm->id, $teacher, $pending];
    }

    /**
     * Returns an id that no pending record uses.
     *
     * @return int The absent id.
     */
    private function absent_pendingid(): int {
        global $DB;
        return (int) $DB->get_field_sql('SELECT MAX(id) FROM {local_assign_ai_pending}') + 1000;
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
     * An unenrolled user gets the same error for an existing and an absent record.
     *
     * @covers ::execute
     * @runInSeparateProcess
     */
    public function test_unenrolled_user_gets_the_same_error_whether_or_not_the_record_exists(): void {
        $this->resetAfterTest();
        [, $cmid, , $pending] = $this->create_scenario();
        $this->setUser($this->getDataGenerator()->create_user());

        $existing = $this->capture(fn() => cancel_review::execute($cmid, (int) $pending->id));
        $absent = $this->capture(fn() => cancel_review::execute($cmid, $this->absent_pendingid()));

        $this->assertInstanceOf(\require_login_exception::class, $existing);
        $this->assertSame(get_class($existing), get_class($absent));
        $this->assertSame($existing->errorcode, $absent->errorcode);
    }

    /**
     * A record of another activity and an absent record fail with the same error.
     *
     * @covers ::execute
     * @runInSeparateProcess
     */
    public function test_foreign_and_absent_records_fail_the_same_way(): void {
        global $DB;
        $this->resetAfterTest();
        [, $cmid, $teacher] = $this->create_scenario();
        [, , , $foreign] = $this->create_scenario();
        $this->setUser($teacher);

        $other = $this->capture(fn() => cancel_review::execute($cmid, (int) $foreign->id));
        $absent = $this->capture(fn() => cancel_review::execute($cmid, $this->absent_pendingid()));

        $this->assertSame(get_class($other), get_class($absent));
        $this->assertSame('invalidpendingrecord', $other->errorcode);
        $this->assertSame('invalidpendingrecord', $absent->errorcode);
        $this->assertSame(
            assign_submission::STATUS_QUEUED,
            $DB->get_field('local_assign_ai_pending', 'status', ['id' => $foreign->id])
        );
    }

    /**
     * An authorised teacher cancels a queued review of the activity.
     *
     * @covers ::execute
     * @runInSeparateProcess
     */
    public function test_teacher_cancels_a_queued_review(): void {
        global $DB;
        $this->resetAfterTest();
        [, $cmid, $teacher, $pending] = $this->create_scenario();
        $this->setUser($teacher);

        $result = cancel_review::execute($cmid, (int) $pending->id);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(
            assign_submission::STATUS_INITIAL,
            $DB->get_field('local_assign_ai_pending', 'status', ['id' => $pending->id])
        );
    }
}
