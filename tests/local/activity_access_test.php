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
 * Tests for the single authorization point of the plugin web services.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai\local;

/**
 * LAA-SEC-006: activity_access authorizes before any user-dependent query.
 *
 * @coversDefaultClass \local_assign_ai\local\activity_access
 * @group local_assign_ai
 */
final class activity_access_test extends \advanced_testcase {
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
     * Creates a course with an assignment, a teacher and a student holding a pending review.
     *
     * @return array [course, cm, teacher, student, pending record]
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
            'status' => 'pending',
        ]);

        return [$course, $cm, $teacher, $student, $pending];
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
     * An authorised user gets the activity module, its context and its course.
     *
     * @covers ::require_access
     */
    public function test_require_access_returns_the_activity_for_an_authorised_user(): void {
        $this->resetAfterTest();
        [$course, $cm, $teacher] = $this->create_scenario();
        $this->setUser($teacher);

        $access = activity_access::require_access((int) $cm->id, 'local/assign_ai:review', (int) $course->id);

        $this->assertSame((int) $cm->id, (int) $access->cm->id);
        $this->assertSame((int) $course->id, (int) $access->course->id);
        $this->assertSame(\context_module::instance($cm->id)->id, $access->context->id);
    }

    /**
     * A course id that does not own the activity is rejected.
     *
     * @covers ::require_access
     */
    public function test_require_access_rejects_a_course_that_does_not_own_the_activity(): void {
        $this->resetAfterTest();
        [, $cm, $teacher] = $this->create_scenario();
        $othercourse = $this->getDataGenerator()->create_course();
        $this->setUser($teacher);

        $e = $this->capture(fn() => activity_access::require_access(
            (int) $cm->id,
            'local/assign_ai:review',
            (int) $othercourse->id
        ));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('invalidcourseid', $e->errorcode);
    }

    /**
     * A user who is not enrolled in the course is rejected before anything else.
     *
     * @covers ::require_access
     */
    public function test_require_access_rejects_an_unenrolled_user(): void {
        $this->resetAfterTest();
        [$course, $cm] = $this->create_scenario();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\require_login_exception::class);
        activity_access::require_access((int) $cm->id, 'local/assign_ai:review', (int) $course->id);
    }

    /**
     * An absent record and a record of another activity fail with the same error.
     *
     * @covers ::require_pending_record
     */
    public function test_require_pending_record_fails_the_same_way_for_absent_and_foreign_records(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $cm, $teacher] = $this->create_scenario();
        [, , , , $foreign] = $this->create_scenario();
        $this->setUser($teacher);

        $access = activity_access::require_access((int) $cm->id, 'local/assign_ai:review', (int) $course->id);
        $absentid = (int) $DB->get_field_sql('SELECT MAX(id) FROM {local_assign_ai_pending}') + 1000;

        $absent = $this->capture(fn() => activity_access::require_pending_record($access, pendingid: $absentid));
        $other = $this->capture(fn() => activity_access::require_pending_record($access, pendingid: (int) $foreign->id));

        $this->assertSame(get_class($absent), get_class($other));
        $this->assertSame('invalidpendingrecord', $absent->errorcode);
        $this->assertSame('invalidpendingrecord', $other->errorcode);
    }

    /**
     * A record of the authorised activity is returned when it matches the conditions.
     *
     * @covers ::require_pending_record
     */
    public function test_require_pending_record_returns_the_record_of_the_activity(): void {
        $this->resetAfterTest();
        [$course, $cm, $teacher, $student, $pending] = $this->create_scenario();
        $this->setUser($teacher);

        $access = activity_access::require_access((int) $cm->id, 'local/assign_ai:review', (int) $course->id);
        $record = activity_access::require_pending_record($access, userid: (int) $student->id, status: 'pending');

        $this->assertSame((int) $pending->id, (int) $record->id);
    }
}
