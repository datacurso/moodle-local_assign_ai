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

namespace local_assign_ai\observer;

use local_assign_ai\assign_submission;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');

/**
 * Retention of the plugin data when a user is deleted or a course is reset (LAA-AUD-015).
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class data_retention_test extends \advanced_testcase {
    /**
     * Inserts a pending AI review.
     *
     * @param int $courseid Course id.
     * @param int $cmid Course module id.
     * @param int $userid Student id.
     * @param int|null $usermodified Teacher who last edited the review.
     * @return int
     */
    private function create_pending(int $courseid, int $cmid, int $userid, ?int $usermodified = null): int {
        global $DB;
        return $DB->insert_record('local_assign_ai_pending', (object) [
            'courseid' => $courseid,
            'assignmentid' => $cmid,
            'title' => 'Review',
            'userid' => $userid,
            'submissionid' => 0,
            'attemptnumber' => 0,
            'submissionmodified' => 0,
            'edited' => 0,
            'message' => 'Private feedback',
            'grade' => 42,
            'status' => assign_submission::STATUS_APPROVED,
            'approval_token' => md5(uniqid('t', true)),
            'usermodified' => $usermodified,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Inserts a delayed queue entry.
     *
     * @param int $userid Student id.
     * @param int $cmid Course module id.
     * @return int
     */
    private function create_queue(int $userid, int $cmid): int {
        global $DB;
        return $DB->insert_record('local_assign_ai_queue', (object) [
            'type' => 'submission',
            'payload' => json_encode(['userid' => $userid, 'cmid' => $cmid, 'submissiontime' => time()]),
            'timecreated' => time(),
            'timetoprocess' => time(),
            'processed' => 0,
        ]);
    }

    /**
     * Creates an assignment in a course.
     *
     * @param \stdClass $course Course.
     * @return array [assign instance, course module]
     */
    private function create_assignment(\stdClass $course): array {
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        [, $cm] = get_course_and_cm_from_instance($instance->id, 'assign');
        return [$instance, $cm];
    }

    /**
     * Sets the grader and last modifier of the AI configuration of an assignment.
     *
     * @param int $instanceid Assign instance id.
     * @param int $userid Teacher id.
     * @return void
     */
    private function set_config_users(int $instanceid, int $userid): void {
        global $DB;
        $record = $DB->get_record('local_assign_ai_config', ['assignmentid' => $instanceid]);
        if (!$record) {
            $DB->insert_record('local_assign_ai_config', (object) [
                'assignmentid' => $instanceid,
                'enableai' => 1,
                'autograde' => 0,
                'graderid' => $userid,
                'usermodified' => $userid,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
            return;
        }
        $record->graderid = $userid;
        $record->usermodified = $userid;
        $DB->update_record('local_assign_ai_config', $record);
    }

    /**
     * Deleting a student removes their AI reviews and queue entries in every assignment, and nothing else.
     *
     * @covers \local_assign_ai\observer\user::user_deleted
     */
    public function test_deleting_a_student_removes_only_their_ai_data(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $deleted = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($deleted->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($deleted->id, $course2->id, 'student');
        $this->getDataGenerator()->enrol_user($other->id, $course1->id, 'student');
        [, $cm1] = $this->create_assignment($course1);
        [, $cm2] = $this->create_assignment($course2);

        $this->create_pending((int) $course1->id, (int) $cm1->id, (int) $deleted->id);
        $this->create_pending((int) $course2->id, (int) $cm2->id, (int) $deleted->id);
        $this->create_queue((int) $deleted->id, (int) $cm1->id);
        $otherpending = $this->create_pending((int) $course1->id, (int) $cm1->id, (int) $other->id);
        $otherqueue = $this->create_queue((int) $other->id, (int) $cm1->id);

        delete_user($DB->get_record('user', ['id' => $deleted->id]));

        $this->assertSame(0, $DB->count_records('local_assign_ai_pending', ['userid' => $deleted->id]));
        $remainingqueue = $DB->get_records('local_assign_ai_queue');
        $this->assertSame([(int) $otherqueue], array_map('intval', array_keys($remainingqueue)));
        $this->assertTrue($DB->record_exists('local_assign_ai_pending', ['id' => $otherpending, 'grade' => 42]));
    }

    /**
     * Deleting a student also removes their reviews whose assignment no longer exists.
     *
     * @covers \local_assign_ai\observer\user::user_deleted
     */
    public function test_deleting_a_student_removes_reviews_of_missing_assignments(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $student = $this->getDataGenerator()->create_user();
        $this->create_pending(999998, 999999, (int) $student->id);

        delete_user($DB->get_record('user', ['id' => $student->id]));

        $this->assertSame(0, $DB->count_records('local_assign_ai_pending', ['userid' => $student->id]));
    }

    /**
     * Deleting a teacher keeps the students' reviews and the configuration, without the teacher references.
     *
     * @covers \local_assign_ai\observer\user::user_deleted
     */
    public function test_deleting_a_teacher_anonymises_their_references(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        [$instance, $cm] = $this->create_assignment($course);
        $pendingid = $this->create_pending((int) $course->id, (int) $cm->id, (int) $student->id, (int) $teacher->id);
        $this->set_config_users((int) $instance->id, (int) $teacher->id);

        delete_user($DB->get_record('user', ['id' => $teacher->id]));

        $pending = $DB->get_record('local_assign_ai_pending', ['id' => $pendingid], '*', MUST_EXIST);
        $this->assertNull($pending->usermodified);
        $this->assertEquals(42, $pending->grade);
        $config = $DB->get_record('local_assign_ai_config', ['assignmentid' => $instance->id], '*', MUST_EXIST);
        $this->assertNull($config->graderid);
        $this->assertNull($config->usermodified);
    }

    /**
     * Resetting a course with "Delete all submissions" removes the AI reviews of its assignments only.
     *
     * @covers \local_assign_ai\observer\course::course_reset_ended
     */
    public function test_course_reset_with_submissions_removes_the_ai_reviews(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        [$instance, $cm] = $this->create_assignment($course);
        [, $othercm] = $this->create_assignment($othercourse);
        $this->create_pending((int) $course->id, (int) $cm->id, (int) $student->id);
        $this->create_queue((int) $student->id, (int) $cm->id);
        $this->set_config_users((int) $instance->id, (int) $student->id);
        $otherpending = $this->create_pending((int) $othercourse->id, (int) $othercm->id, (int) $student->id);
        $otherqueue = $this->create_queue((int) $student->id, (int) $othercm->id);

        reset_course_userdata((object) ['id' => $course->id, 'reset_assign_submissions' => 1]);

        $this->assertSame(0, $DB->count_records('local_assign_ai_pending', ['courseid' => $course->id]));
        $this->assertSame([(int) $otherqueue], array_map('intval', array_keys($DB->get_records('local_assign_ai_queue'))));
        $this->assertTrue($DB->record_exists('local_assign_ai_pending', ['id' => $otherpending]));
        $this->assertTrue($DB->record_exists('local_assign_ai_config', ['assignmentid' => $instance->id]));
    }

    /**
     * Resetting a course without deleting the submissions keeps the AI reviews.
     *
     * @covers \local_assign_ai\observer\course::course_reset_ended
     */
    public function test_course_reset_without_submissions_keeps_the_ai_reviews(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        [, $cm] = $this->create_assignment($course);
        $pendingid = $this->create_pending((int) $course->id, (int) $cm->id, (int) $student->id);

        reset_course_userdata((object) ['id' => $course->id, 'reset_assign_submissions' => 0]);

        $this->assertTrue($DB->record_exists('local_assign_ai_pending', ['id' => $pendingid]));
    }
}
