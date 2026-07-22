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
 * Tests for the synchronization of approved AI records after manual grading.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');

/**
 * Unit tests for the synchronization of approved AI records after manual grading.
 *
 * These tests trigger the real mod_assign submission_graded event through the assign grading API
 * and rely on the registered non-internal observers, so every test disposes the test-wide
 * transaction via preventResetByRollback() before triggering events.
 *
 * @coversDefaultClass \local_assign_ai\pending\manager
 * @group local_assign_ai
 */
final class grading_sync_test extends \advanced_testcase {
    use \mod_assign_test_generator;

    /**
     * Bump the assign instance id sequence past the given id.
     *
     * assignment_config keeps a per-process static cache keyed by assignment id while the PHPUnit
     * database reset reuses ids across tests, so each test claims a process-unique id range to
     * guarantee it operates on an assignment id no other test has cached.
     *
     * @param int $id Filler id imported directly into the assign table.
     * @param int $courseid Course id used by the filler row.
     * @return void
     */
    private function bump_assign_sequence(int $id, int $courseid): void {
        global $DB;

        $DB->import_record('assign', (object) [
            'id' => $id,
            'course' => $courseid,
            'name' => 'filler',
            'intro' => '',
            'introformat' => FORMAT_HTML,
        ]);
        $DB->get_manager()->reset_sequence('assign');
    }

    /**
     * Insert an AI record for the given student, as stored by the plugin pipeline.
     *
     * @param \assign $assign The assignment instance.
     * @param \stdClass $student The student the AI feedback belongs to.
     * @param string $status Record status (approve, pending, ...).
     * @return \stdClass The inserted record.
     */
    private function create_ai_record(\assign $assign, \stdClass $student, string $status): \stdClass {
        global $DB;

        $now = time();
        $record = (object) [
            'courseid' => $assign->get_course()->id,
            'assignmentid' => $assign->get_course_module()->id,
            'title' => $assign->get_instance()->name,
            'userid' => $student->id,
            'submissionid' => null,
            'attemptnumber' => 0,
            'submissionmodified' => $now,
            'edited' => 0,
            'message' => 'Original AI feedback',
            'grade' => 55,
            'rubric_response' => null,
            'assessment_guide_response' => null,
            'status' => $status,
            'approval_token' => md5('local_assign_ai_test_' . $student->id . '_' . $now),
            'usermodified' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('local_assign_ai_pending', $record);

        return $record;
    }

    /**
     * MDL-INT-019: When a teacher manually re-grades a submission whose latest AI record is
     * approved, the record is synchronized with the teacher's grade and feedback comment.
     *
     * @covers ::sync_after_grading
     * @covers ::get_latest_record
     * @covers ::get_feedback_comment
     */
    public function test_manual_grading_syncs_approved_record(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->redirectMessages();
        $this->redirectEmails();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(4110, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignfeedback_comments_enabled' => 1,
        ]);

        $this->add_submission($student, $assign, 'My essay text');
        $record = $this->create_ai_record($assign, $student, assign_submission::STATUS_APPROVED);

        // The teacher manually grades the submission through the assign grading API.
        $this->mark_submission($teacher, $assign, $student, 77.0, [
            'assignfeedbackcomments_editor' => [
                'text' => 'Teacher improved feedback',
                'format' => FORMAT_HTML,
            ],
        ]);

        // The approved AI record now mirrors the teacher's grade and feedback comment.
        $record = $DB->get_record('local_assign_ai_pending', ['id' => $record->id], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_APPROVED, $record->status);
        $this->assertEquals(77, $record->grade);
        $this->assertSame('Teacher improved feedback', $record->message);
        $this->assertEquals($teacher->id, $record->usermodified);
    }

    /**
     * MDL-INT-019: When the latest AI record is not approved, manual grading does not alter it.
     *
     * @covers ::sync_after_grading
     * @covers ::get_latest_record
     */
    public function test_manual_grading_leaves_non_approved_record_untouched(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->redirectMessages();
        $this->redirectEmails();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(4120, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignfeedback_comments_enabled' => 1,
        ]);

        $this->add_submission($student, $assign, 'My essay text');
        $record = $this->create_ai_record($assign, $student, assign_submission::STATUS_PENDING);

        $this->mark_submission($teacher, $assign, $student, 88.0, [
            'assignfeedbackcomments_editor' => [
                'text' => 'Teacher feedback that must not be synced',
                'format' => FORMAT_HTML,
            ],
        ]);

        // The record still under review keeps the original AI suggestion untouched.
        $record = $DB->get_record('local_assign_ai_pending', ['id' => $record->id], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_PENDING, $record->status);
        $this->assertEquals(55, $record->grade);
        $this->assertSame('Original AI feedback', $record->message);
        $this->assertNull($record->usermodified);
    }
}
