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
}
