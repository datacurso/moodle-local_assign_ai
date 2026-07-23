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
 * Tests for the UI error message sanitiser.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai;

/**
 * Ensures raw exception detail is not surfaced to the UI.
 *
 * @coversDefaultClass \local_assign_ai\assign_submission
 * @group local_assign_ai
 */
final class error_message_test extends \advanced_testcase {
    /**
     * Our own localized exceptions are preserved for the teacher.
     *
     * @covers ::ui_error_message
     */
    public function test_curated_exception_is_preserved(): void {
        $e = new \moodle_exception('error_rubricmismatch', 'local_assign_ai', '', 'Redacción');
        $message = assign_submission::ui_error_message($e);

        $this->assertStringContainsString('Redacción', $message);
    }

    /**
     * Raw/unknown exceptions are replaced by a generic message, leaking nothing.
     *
     * @covers ::ui_error_message
     */
    public function test_raw_exception_is_genericised(): void {
        $e = new \Exception('conn to https://ai.internal/answer?key=SECRET failed');
        $message = assign_submission::ui_error_message($e);

        $this->assertSame(get_string('error_generic', 'local_assign_ai'), $message);
        $this->assertStringNotContainsString('internal', $message);
        $this->assertStringNotContainsString('key=', $message);
        $this->assertStringNotContainsString('SECRET', $message);
    }

    /**
     * register_failure persists the sanitised message, not the raw one.
     *
     * @covers ::register_failure
     */
    public function test_register_failure_persists_sanitised_message(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        [, $cm] = get_course_and_cm_from_instance($instance->id, 'assign');

        $pendingid = $DB->insert_record('local_assign_ai_pending', (object) [
            'courseid' => $course->id,
            'assignmentid' => $cm->id,
            'title' => 'T',
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
            'retries' => 0,
            'status' => assign_submission::STATUS_PROCESSING,
            'approval_token' => assign_submission::generate_approval_token(),
            'usermodified' => get_admin()->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        assign_submission::register_failure(new \Exception('boom http://internal/x'), $pendingid);
        $this->resetDebugging();

        $stored = $DB->get_field('local_assign_ai_pending', 'errormessage', ['id' => $pendingid]);
        $this->assertSame(get_string('error_generic', 'local_assign_ai'), $stored);
        $this->assertStringNotContainsString('internal', $stored);
    }
}
