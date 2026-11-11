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
 * Tests for the update_response external function.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai\external;

use local_assign_ai\assign_submission;

/**
 * Ensures update_response sanitizes the stored message.
 *
 * @coversDefaultClass \local_assign_ai\external\update_response
 * @group local_assign_ai
 */
final class update_response_test extends \advanced_testcase {
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
     * A script payload must be stripped while legitimate HTML is kept.
     *
     * @covers ::execute
     * @runInSeparateProcess
     */
    public function test_execute_sanitizes_message(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        [, $cm] = get_course_and_cm_from_instance($instance->id, 'assign');

        $recordid = $DB->insert_record('local_assign_ai_pending', (object) [
            'courseid' => $course->id,
            'assignmentid' => $cm->id,
            'title' => $instance->name,
            'userid' => $student->id,
            'submissionid' => 0,
            'attemptnumber' => 0,
            'submissionmodified' => 0,
            'edited' => 0,
            'message' => 'original',
            'grade' => null,
            'rubric_response' => null,
            'assessment_guide_response' => null,
            'errormessage' => null,
            'status' => assign_submission::STATUS_PENDING,
            'approval_token' => md5(uniqid('test_', true)),
            'usermodified' => get_admin()->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        update_response::execute(
            $course->id,
            $cm->id,
            $student->id,
            '<p>ok</p><script>alert(1)</script>'
        );

        $stored = $DB->get_field('local_assign_ai_pending', 'message', ['id' => $recordid]);
        $this->assertStringContainsString('<p>ok</p>', $stored);
        $this->assertStringNotContainsString('<script>', $stored);
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
            'message' => 'original',
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
     * LAA-SEC-006: an unenrolled user cannot tell whether a student has a pending review.
     *
     * @covers ::execute
     * @runInSeparateProcess
     */
    public function test_unenrolled_user_gets_the_same_error_whether_or_not_a_review_exists(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $cmid, , $reviewed, $notreviewed, $pending] = $this->create_scenario();
        $this->setUser($this->getDataGenerator()->create_user());

        $existing = $this->capture(fn() => update_response::execute((int) $course->id, $cmid, (int) $reviewed->id, 'x'));
        $absent = $this->capture(fn() => update_response::execute((int) $course->id, $cmid, (int) $notreviewed->id, 'x'));

        $this->assertInstanceOf(\require_login_exception::class, $existing);
        $this->assertSame(get_class($existing), get_class($absent));
        $this->assertSame($existing->errorcode, $absent->errorcode);
        $this->assertSame('original', $DB->get_field('local_assign_ai_pending', 'message', ['id' => $pending->id]));
    }

    /**
     * LAA-SEC-006: a course id that does not own the activity is rejected.
     *
     * @covers ::execute
     * @runInSeparateProcess
     */
    public function test_rejects_a_course_that_does_not_own_the_activity(): void {
        $this->resetAfterTest();
        [, $cmid, $teacher, $reviewed] = $this->create_scenario();
        $othercourse = $this->getDataGenerator()->create_course();
        $this->setUser($teacher);

        $e = $this->capture(fn() => update_response::execute((int) $othercourse->id, $cmid, (int) $reviewed->id, 'x'));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('invalidcourseid', $e->errorcode);
    }

    /**
     * LAA-SEC-006: an authorised teacher without a pending review for the student gets the uniform not-found error.
     *
     * @covers ::execute
     * @runInSeparateProcess
     */
    public function test_teacher_gets_the_uniform_error_when_there_is_no_pending_review(): void {
        $this->resetAfterTest();
        [$course, $cmid, $teacher, , $notreviewed] = $this->create_scenario();
        $this->setUser($teacher);

        $e = $this->capture(fn() => update_response::execute((int) $course->id, $cmid, (int) $notreviewed->id, 'x'));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('invalidpendingrecord', $e->errorcode);
    }
}
