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
 * Tests for the get_progress external function.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai\external;

use local_assign_ai\assign_submission;

/**
 * Access-control tests for get_progress.
 *
 * @coversDefaultClass \local_assign_ai\external\get_progress
 * @group local_assign_ai
 */
final class get_progress_test extends \advanced_testcase {
    /**
     * Creates a course with an assignment and one pending record; returns its id.
     *
     * @param object $student Student to attach the record to.
     * @return array [course, pendingid]
     */
    private function create_record(object $student): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        [, $cm] = get_course_and_cm_from_instance($instance->id, 'assign');

        $pendingid = $DB->insert_record('local_assign_ai_pending', (object) [
            'courseid' => $course->id,
            'assignmentid' => $cm->id,
            'title' => $instance->name,
            'userid' => $student->id,
            'submissionid' => 0,
            'attemptnumber' => 0,
            'submissionmodified' => 0,
            'edited' => 0,
            'message' => null,
            'grade' => 55,
            'rubric_response' => null,
            'assessment_guide_response' => null,
            'errormessage' => null,
            'status' => assign_submission::STATUS_PENDING,
            'approval_token' => md5(uniqid('t', true)),
            'usermodified' => get_admin()->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        return [$course, $pendingid];
    }

    /**
     * Only records the caller can review are returned; others are silently dropped.
     *
     * @covers ::execute
     * @runInSeparateProcess
     */
    public function test_unauthorized_records_are_dropped(): void {
        $this->resetAfterTest();

        $student = $this->getDataGenerator()->create_user();
        [$coursea, $allowedid] = $this->create_record($student);
        [, $forbiddenid] = $this->create_record($student);

        // Teacher only in course A.
        $teacher = $this->getDataGenerator()->create_and_enrol($coursea, 'editingteacher');
        $this->setUser($teacher);

        $result = get_progress::execute([$allowedid, $forbiddenid]);

        $ids = array_column($result, 'id');
        $this->assertContains($allowedid, $ids);
        $this->assertNotContains($forbiddenid, $ids);
    }

    /**
     * A teacher sees the records of their own course.
     *
     * @covers ::execute
     * @runInSeparateProcess
     */
    public function test_authorized_records_are_returned(): void {
        $this->resetAfterTest();

        $student = $this->getDataGenerator()->create_user();
        [$course, $pendingid] = $this->create_record($student);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = get_progress::execute([$pendingid]);

        $this->assertCount(1, $result);
        $this->assertSame($pendingid, $result[0]['id']);
        $this->assertSame(55, $result[0]['grade']);
    }
}
