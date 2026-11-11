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
 * Tests for the get_details external function.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai\external;

/**
 * LAA-SEC-006: get_details authorizes before looking up the review.
 *
 * @coversDefaultClass \local_assign_ai\external\get_details
 * @group local_assign_ai
 */
final class get_details_test extends \advanced_testcase {
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
     * Creates an assignment with a teacher, a student with a review and a student without one.
     *
     * @return array [course, cmid, teacher, reviewed student, student without review]
     */
    private function create_scenario(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $reviewed = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $notreviewed = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        [, $cm] = get_course_and_cm_from_instance($instance->id, 'assign');
        $this->getDataGenerator()->get_plugin_generator('local_assign_ai')->create_pending([
            'assignmentid' => $cm->id,
            'userid' => $reviewed->id,
            'status' => 'pending',
            'message' => 'AI feedback',
            'grade' => 70,
        ]);

        return [$course, (int) $cm->id, $teacher, $reviewed, $notreviewed];
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
     * An unenrolled user cannot tell whether a student has an AI review.
     *
     * @covers ::execute
     * @runInSeparateProcess
     */
    public function test_unenrolled_user_gets_the_same_error_whether_or_not_a_review_exists(): void {
        $this->resetAfterTest();
        [$course, $cmid, , $reviewed, $notreviewed] = $this->create_scenario();
        $this->setUser($this->getDataGenerator()->create_user());

        $existing = $this->capture(fn() => get_details::execute((int) $course->id, $cmid, (int) $reviewed->id));
        $absent = $this->capture(fn() => get_details::execute((int) $course->id, $cmid, (int) $notreviewed->id));

        $this->assertInstanceOf(\require_login_exception::class, $existing);
        $this->assertSame(get_class($existing), get_class($absent));
        $this->assertSame($existing->errorcode, $absent->errorcode);
    }

    /**
     * A student of the course cannot tell whether a classmate has an AI review.
     *
     * @covers ::execute
     * @runInSeparateProcess
     */
    public function test_student_gets_the_same_error_whether_or_not_a_review_exists(): void {
        $this->resetAfterTest();
        [$course, $cmid, , $reviewed, $notreviewed] = $this->create_scenario();
        $this->setUser($notreviewed);

        $existing = $this->capture(fn() => get_details::execute((int) $course->id, $cmid, (int) $reviewed->id));
        $absent = $this->capture(fn() => get_details::execute((int) $course->id, $cmid, (int) $notreviewed->id));

        $this->assertInstanceOf(\required_capability_exception::class, $existing);
        $this->assertSame(get_class($existing), get_class($absent));
        $this->assertSame($existing->errorcode, $absent->errorcode);
    }

    /**
     * An authorised teacher gets the review details.
     *
     * @covers ::execute
     * @runInSeparateProcess
     */
    public function test_teacher_gets_the_review_details(): void {
        $this->resetAfterTest();
        [$course, $cmid, $teacher, $reviewed] = $this->create_scenario();
        $this->setUser($teacher);

        $result = get_details::execute((int) $course->id, $cmid, (int) $reviewed->id);

        $this->assertSame('pending', $result['status']);
        $this->assertSame('AI feedback', $result['message']);
    }

    /**
     * An authorised teacher gets the status none when the student has no review.
     *
     * @covers ::execute
     * @runInSeparateProcess
     */
    public function test_teacher_gets_status_none_when_there_is_no_review(): void {
        $this->resetAfterTest();
        [$course, $cmid, $teacher, , $notreviewed] = $this->create_scenario();
        $this->setUser($teacher);

        $result = get_details::execute((int) $course->id, $cmid, (int) $notreviewed->id);

        $this->assertSame('none', $result['status']);
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

        $e = $this->capture(fn() => get_details::execute((int) $othercourse->id, $cmid, (int) $reviewed->id));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('invalidcourseid', $e->errorcode);
    }
}
