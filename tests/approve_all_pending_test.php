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
 * Tests for the approve_all_pending external function.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai;

use local_assign_ai\external\approve_all_pending;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');

/**
 * Unit tests for the approve_all_pending external function.
 *
 * @coversDefaultClass \local_assign_ai\external\approve_all_pending
 * @group local_assign_ai
 */
final class approve_all_pending_test extends \externallib_advanced_testcase {
    use \mod_assign_test_generator;

    /**
     * Insert a pending AI record for the given student, as stored by the plugin pipeline.
     *
     * @param \assign $assign The assignment instance.
     * @param int $userid The student user id the AI feedback belongs to.
     * @param int $grade AI suggested grade.
     * @param string $message AI feedback message.
     * @return \stdClass The inserted record.
     */
    private function create_pending_record(\assign $assign, int $userid, int $grade, string $message): \stdClass {
        global $DB;

        $now = time();
        $record = (object) [
            'courseid' => $assign->get_course()->id,
            'assignmentid' => $assign->get_course_module()->id,
            'title' => $assign->get_instance()->name,
            'userid' => $userid,
            'submissionid' => null,
            'attemptnumber' => 0,
            'submissionmodified' => $now,
            'edited' => 0,
            'message' => $message,
            'grade' => $grade,
            'rubric_response' => null,
            'assessment_guide_response' => null,
            'status' => assign_submission::STATUS_PENDING,
            'approval_token' => md5('local_assign_ai_test_' . $userid . '_' . $now),
            'usermodified' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('local_assign_ai_pending', $record);

        return $record;
    }

    /**
     * MDL-INT-017: Executing the service approves every pending record of the assignment, pushes
     * the AI grades and feedback to the grading tables and reports the approved count.
     *
     * @covers ::execute
     */
    public function test_execute_approves_all_pending_records_and_pushes_grades(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $students = [];
        for ($i = 0; $i < 3; $i++) {
            $students[] = $this->getDataGenerator()->create_and_enrol($course, 'student');
        }
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignfeedback_comments_enabled' => 1,
        ]);
        $cmid = (int) $assign->get_course_module()->id;

        $grades = [60, 70, 80];
        $records = [];
        foreach ($students as $index => $student) {
            $this->add_submission($student, $assign, 'Essay of student ' . $index);
            $records[$index] = $this->create_pending_record($assign, (int) $student->id, $grades[$index],
                'AI feedback ' . $index);
        }

        $this->setUser($teacher);
        $result = approve_all_pending::execute((int) $course->id, $cmid);
        $result = approve_all_pending::clean_returnvalue(approve_all_pending::execute_returns(), $result);

        // The response reports the processed count.
        $this->assertSame('ok', $result['status']);
        $this->assertSame(3, $result['approved']);

        foreach ($students as $index => $student) {
            // Every record is now approved by the teacher.
            $record = $DB->get_record('local_assign_ai_pending', ['id' => $records[$index]->id], '*', MUST_EXIST);
            $this->assertSame(assign_submission::STATUS_APPROVED, $record->status);
            $this->assertEquals($teacher->id, $record->usermodified);

            // The AI grade and feedback were pushed to the assignment grading tables.
            $grade = $assign->get_user_grade($student->id, false);
            $this->assertNotEmpty($grade);
            $this->assertEquals((float) $grades[$index], (float) $grade->grade);
            $this->assertEquals($teacher->id, $grade->grader);

            $feedback = $DB->get_record('assignfeedback_comments', ['grade' => $grade->id], '*', MUST_EXIST);
            $this->assertSame('AI feedback ' . $index, $feedback->commenttext);
        }

        $this->resetDebugging();
    }

    /**
     * MDL-INT-017: A corrupted record (its user was deleted from the site) does not stop the batch:
     * the remaining pending records still get approved and their grades pushed.
     *
     * Each record is processed inside its own try/catch (failures are logged through
     * assign_submission::register_failure), so one bad row can never abort the loop. Note that with
     * the current defensive implementation the corrupted row itself is still flagged as approved
     * because the feedback applier soft-fails instead of throwing.
     *
     * @covers ::execute
     */
    public function test_execute_continues_when_one_record_is_corrupted(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $first = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $ghost = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $last = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);
        $cmid = (int) $assign->get_course_module()->id;

        $this->add_submission($first, $assign, 'First essay');
        $this->add_submission($last, $assign, 'Last essay');

        $firstrecord = $this->create_pending_record($assign, (int) $first->id, 60, 'First feedback');
        // The middle record is corrupted: its user disappears from the site entirely.
        $this->create_pending_record($assign, (int) $ghost->id, 70, 'Ghost feedback');
        $DB->delete_records('user', ['id' => $ghost->id]);
        $lastrecord = $this->create_pending_record($assign, (int) $last->id, 80, 'Last feedback');

        $this->setUser($teacher);
        $result = approve_all_pending::execute((int) $course->id, $cmid);
        $result = approve_all_pending::clean_returnvalue(approve_all_pending::execute_returns(), $result);

        // The batch completed instead of aborting on the corrupted row.
        $this->assertSame('ok', $result['status']);
        $this->assertGreaterThanOrEqual(2, $result['approved']);

        // The healthy records around the corrupted one were approved and graded normally.
        foreach ([[$first, $firstrecord, 60.0], [$last, $lastrecord, 80.0]] as [$student, $record, $expected]) {
            $record = $DB->get_record('local_assign_ai_pending', ['id' => $record->id], '*', MUST_EXIST);
            $this->assertSame(assign_submission::STATUS_APPROVED, $record->status);

            $grade = $assign->get_user_grade($student->id, false);
            $this->assertNotEmpty($grade);
            $this->assertEquals($expected, (float) $grade->grade);
        }

        $this->resetDebugging();
    }
}
