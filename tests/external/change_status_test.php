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
 * Tests for the change_status external function.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai\external;

use local_assign_ai\assign_submission;

/**
 * LAA-SEC-006: change_status authorizes before looking up the review and only accepts known actions.
 *
 * @coversDefaultClass \local_assign_ai\external\change_status
 * @group local_assign_ai
 */
final class change_status_test extends \advanced_testcase {
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
     * Creates an assignment with a teacher, a student with a pending review and a student without one.
     *
     * @return array [course, cmid, teacher, reviewed student, student without review, pending record]
     */
    private function create_scenario(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $reviewed = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $notreviewed = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        [, $cm] = get_course_and_cm_from_instance($instance->id, 'assign');
        $pending = $this->getDataGenerator()->get_plugin_generator('local_assign_ai')->create_pending([
            'assignmentid' => $cm->id,
            'userid' => $reviewed->id,
            'status' => assign_submission::STATUS_PENDING,
            'grade' => 70,
        ]);

        return [$course, (int) $cm->id, $teacher, $reviewed, $notreviewed, $pending];
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
     * An unenrolled user cannot tell whether a student has a pending review.
     *
     * @covers ::execute
     * @runInSeparateProcess
     */
    public function test_unenrolled_user_gets_the_same_error_whether_or_not_a_review_exists(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $cmid, , $reviewed, $notreviewed, $pending] = $this->create_scenario();
        $this->setUser($this->getDataGenerator()->create_user());

        $existing = $this->capture(fn() => change_status::execute((int) $course->id, $cmid, (int) $reviewed->id, 'rejected'));
        $absent = $this->capture(fn() => change_status::execute((int) $course->id, $cmid, (int) $notreviewed->id, 'rejected'));

        $this->assertInstanceOf(\require_login_exception::class, $existing);
        $this->assertSame(get_class($existing), get_class($absent));
        $this->assertSame($existing->errorcode, $absent->errorcode);
        $this->assertSame(
            assign_submission::STATUS_PENDING,
            $DB->get_field('local_assign_ai_pending', 'status', ['id' => $pending->id])
        );
    }

    /**
     * Only the approve and rejected actions sent by the review page are accepted.
     *
     * @covers ::execute
     * @runInSeparateProcess
     */
    public function test_rejects_an_unknown_action(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $cmid, $teacher, $reviewed, , $pending] = $this->create_scenario();
        $this->setUser($teacher);

        $e = $this->capture(fn() => change_status::execute((int) $course->id, $cmid, (int) $reviewed->id, 'failed'));

        $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
        $this->assertSame(
            assign_submission::STATUS_PENDING,
            $DB->get_field('local_assign_ai_pending', 'status', ['id' => $pending->id])
        );
    }

    /**
     * A course id that does not own the activity is rejected.
     *
     * @covers ::execute
     * @runInSeparateProcess
     */
    public function test_rejects_a_course_that_does_not_own_the_activity(): void {
        $this->resetAfterTest();
        [, $cmid, $teacher, $reviewed] = $this->create_scenario();
        $othercourse = $this->getDataGenerator()->create_course();
        $this->setUser($teacher);

        $e = $this->capture(fn() => change_status::execute((int) $othercourse->id, $cmid, (int) $reviewed->id, 'rejected'));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('invalidcourseid', $e->errorcode);
    }

    /**
     * An authorised teacher without a pending review for the student gets the uniform not-found error.
     *
     * @covers ::execute
     * @runInSeparateProcess
     */
    public function test_teacher_gets_the_uniform_error_when_there_is_no_pending_review(): void {
        $this->resetAfterTest();
        [$course, $cmid, $teacher, , $notreviewed] = $this->create_scenario();
        $this->setUser($teacher);

        $e = $this->capture(fn() => change_status::execute((int) $course->id, $cmid, (int) $notreviewed->id, 'rejected'));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('invalidpendingrecord', $e->errorcode);
    }

    /**
     * An authorised teacher can reject a pending review.
     *
     * @covers ::execute
     * @runInSeparateProcess
     */
    public function test_teacher_rejects_a_pending_review(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $cmid, $teacher, $reviewed, , $pending] = $this->create_scenario();
        $this->setUser($teacher);

        $result = change_status::execute((int) $course->id, $cmid, (int) $reviewed->id, 'rejected');

        $this->assertSame('rejected', $result['newstatus']);
        $this->assertSame(
            assign_submission::STATUS_REJECTED,
            $DB->get_field('local_assign_ai_pending', 'status', ['id' => $pending->id])
        );
    }
}
