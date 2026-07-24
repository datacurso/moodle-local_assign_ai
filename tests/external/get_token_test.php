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
 * Tests for the get_token external function.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai\external;

use local_assign_ai\assign_submission;

/**
 * Access-control tests for get_token.
 *
 * @coversDefaultClass \local_assign_ai\external\get_token
 * @group local_assign_ai
 */
final class get_token_test extends \advanced_testcase {
    /**
     * Creates a course, assignment and a pending record for a student.
     *
     * @return array [course, cmid, student, token]
     */
    private function create_scenario(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        [, $cm] = get_course_and_cm_from_instance($instance->id, 'assign');

        $token = 'secrettoken';
        $DB->insert_record('local_assign_ai_pending', (object) [
            'courseid' => $course->id,
            'assignmentid' => $cm->id,
            'title' => $instance->name,
            'userid' => $student->id,
            'submissionid' => 0,
            'attemptnumber' => 0,
            'submissionmodified' => 0,
            'edited' => 0,
            'message' => null,
            'grade' => null,
            'rubric_response' => null,
            'assessment_guide_response' => null,
            'errormessage' => null,
            'status' => assign_submission::STATUS_PENDING,
            'approval_token' => $token,
            'usermodified' => get_admin()->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        return [$course, $cm->id, $student, $token];
    }

    /**
     * A teacher with the review capability gets the token (legitimate flow).
     *
     * @covers ::execute
     * @runInSeparateProcess
     */
    public function test_teacher_can_get_token(): void {
        $this->resetAfterTest();
        [$course, $cmid, $student, $token] = $this->create_scenario();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = get_token::execute($student->id, $cmid);

        $this->assertSame($token, $result['approval_token']);
    }

    /**
     * A student cannot read another user's token (IDOR closed).
     *
     * @covers ::execute
     * @runInSeparateProcess
     */
    public function test_student_cannot_get_others_token(): void {
        $this->resetAfterTest();
        [$course, $cmid, $student, $token] = $this->create_scenario();
        $attacker = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($attacker);

        $this->expectException(\required_capability_exception::class);
        get_token::execute($student->id, $cmid);
    }
}
