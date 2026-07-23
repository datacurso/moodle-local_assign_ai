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
 * Tests for the process_submission external function.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai\external;

use local_assign_ai\assign_submission;

/**
 * Access-control tests for process_submission (write IDOR).
 *
 * @coversDefaultClass \local_assign_ai\external\process_submission
 * @group local_assign_ai
 */
final class process_submission_test extends \advanced_testcase {
    /**
     * Creates a course, assignment and a pending record for a student.
     *
     * @param int|null $userid Optional student id to reuse; created if null.
     * @return array [course, cmid, studentid, pendingid]
     */
    private function create_record(?int $userid = null): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        if ($userid === null) {
            $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
            $userid = $student->id;
        } else {
            $this->getDataGenerator()->enrol_user($userid, $course->id, 'student');
        }
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        [, $cm] = get_course_and_cm_from_instance($instance->id, 'assign');

        $pendingid = $DB->insert_record('local_assign_ai_pending', (object) [
            'courseid' => $course->id,
            'assignmentid' => $cm->id,
            'title' => $instance->name,
            'userid' => $userid,
            'submissionid' => 0,
            'attemptnumber' => 0,
            'submissionmodified' => 0,
            'edited' => 0,
            'message' => null,
            'grade' => null,
            'rubric_response' => null,
            'assessment_guide_response' => null,
            'errormessage' => null,
            'status' => assign_submission::STATUS_INITIAL,
            'approval_token' => md5(uniqid('t', true)),
            'usermodified' => get_admin()->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        return [$course, (int) $cm->id, (int) $userid, (int) $pendingid];
    }

    /**
     * The legitimate single-user flow queues the record.
     *
     * @covers ::execute
     * @runInSeparateProcess
     */
    public function test_queues_own_record(): void {
        global $DB;
        $this->resetAfterTest();

        [$course, $cmid, $studentid, $pendingid] = $this->create_record();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = process_submission::execute($cmid, $studentid, false, $pendingid);

        $this->assertSame('queued', $result['status']);
        $this->assertSame(
            assign_submission::STATUS_QUEUED,
            $DB->get_field('local_assign_ai_pending', 'status', ['id' => $pendingid])
        );
    }

    /**
     * A record from another activity cannot be queued (write IDOR closed).
     *
     * @covers ::execute
     * @runInSeparateProcess
     */
    public function test_cannot_queue_foreign_activity_record(): void {
        global $DB;
        $this->resetAfterTest();

        [$coursea, $cmida] = $this->create_record();
        $teacher = $this->getDataGenerator()->create_and_enrol($coursea, 'editingteacher');

        // Foreign record in another course/activity.
        [, , $studentb, $pendingidb] = $this->create_record();

        $this->setUser($teacher);

        try {
            process_submission::execute($cmida, $studentb, false, $pendingidb);
            $this->fail('Expected a moodle_exception for the foreign pending record.');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        // The foreign record must remain untouched (not queued).
        $this->assertSame(
            assign_submission::STATUS_INITIAL,
            $DB->get_field('local_assign_ai_pending', 'status', ['id' => $pendingidb])
        );
    }

    /**
     * A pending record of this activity but a mismatched user is rejected.
     *
     * @covers ::execute
     * @runInSeparateProcess
     */
    public function test_cannot_queue_with_mismatched_user(): void {
        global $DB;
        $this->resetAfterTest();

        [$course, $cmid, $studentid, $pendingid] = $this->create_record();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($teacher);

        try {
            process_submission::execute($cmid, $other->id, false, $pendingid);
            $this->fail('Expected a moodle_exception for the mismatched user.');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertSame(
            assign_submission::STATUS_INITIAL,
            $DB->get_field('local_assign_ai_pending', 'status', ['id' => $pendingid])
        );
    }
}
