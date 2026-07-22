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
 * Tests for the AI feedback applier.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai\grading;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Unit tests for the feedback applier.
 *
 * @coversDefaultClass \local_assign_ai\grading\feedback_applier
 * @group local_assign_ai
 */
final class feedback_applier_test extends \advanced_testcase {
    /**
     * Creates a course, a student and an assignment, and returns the assign instance.
     *
     * @param bool $commentsenabled Whether the comments feedback plugin is enabled.
     * @param array $options Extra assignment settings.
     * @return array [assign, student]
     */
    private function create_assign_with_student(bool $commentsenabled, array $options = []): array {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $instance = $this->getDataGenerator()->create_module('assign', $options + [
            'course' => $course->id,
            'assignfeedback_comments_enabled' => $commentsenabled ? 1 : 0,
            'grade' => 100,
        ]);

        [, $cm] = get_course_and_cm_from_instance($instance->id, 'assign');
        $context = \context_module::instance($cm->id);
        $assign = new \assign($context, $cm, $course);

        return [$assign, $student];
    }

    /**
     * The helper reports whether the comments feedback plugin is active for the instance.
     *
     * @covers ::is_comments_plugin_active
     */
    public function test_is_comments_plugin_active(): void {
        $this->resetAfterTest();

        [$assigndisabled] = $this->create_assign_with_student(false);
        $this->assertFalse(feedback_applier::is_comments_plugin_active($assigndisabled));

        [$assignenabled] = $this->create_assign_with_student(true);
        $this->assertTrue(feedback_applier::is_comments_plugin_active($assignenabled));
    }

    /**
     * The comment must not be written when the comments feedback plugin is disabled.
     *
     * @covers ::save_feedback_comments
     */
    public function test_save_feedback_comments_skipped_when_plugin_disabled(): void {
        global $DB;
        $this->resetAfterTest();

        [$assign, $student] = $this->create_assign_with_student(false);
        $grade = $assign->get_user_grade($student->id, true);

        feedback_applier::save_feedback_comments($assign, $grade, '<p>AI feedback</p>');
        $this->resetDebugging();

        $this->assertFalse($DB->record_exists('assignfeedback_comments', ['grade' => $grade->id]));
    }

    /**
     * The comment is inserted and updated when the comments feedback plugin is enabled.
     *
     * @covers ::save_feedback_comments
     */
    public function test_save_feedback_comments_written_when_plugin_enabled(): void {
        global $DB;
        $this->resetAfterTest();

        [$assign, $student] = $this->create_assign_with_student(true);
        $grade = $assign->get_user_grade($student->id, true);

        feedback_applier::save_feedback_comments($assign, $grade, '<p>AI feedback</p>');

        $record = $DB->get_record('assignfeedback_comments', ['grade' => $grade->id], '*', MUST_EXIST);
        $this->assertSame('<p>AI feedback</p>', $record->commenttext);

        feedback_applier::save_feedback_comments($assign, $grade, '<p>Updated feedback</p>');

        $records = $DB->get_records('assignfeedback_comments', ['grade' => $grade->id]);
        $this->assertCount(1, $records);
        $this->assertSame('<p>Updated feedback</p>', reset($records)->commenttext);
    }

    /**
     * With the plugin disabled, the full apply flow still grades but skips the comment.
     *
     * This covers every approval flow (auto and manual), since all of them enter
     * through feedback_applier::apply_ai_feedback().
     *
     * @covers ::apply_ai_feedback
     */
    public function test_apply_ai_feedback_grades_without_comment_when_plugin_disabled(): void {
        global $DB;
        $this->resetAfterTest();

        [$assign, $student] = $this->create_assign_with_student(false);
        $teacher = $this->getDataGenerator()->create_and_enrol($assign->get_course(), 'editingteacher');

        $record = (object) [
            'userid' => $student->id,
            'attemptnumber' => 0,
            'message' => '<p>AI feedback</p>',
            'grade' => 85,
            'rubric_response' => null,
            'assessment_guide_response' => null,
        ];

        feedback_applier::apply_ai_feedback($assign, $record, $teacher->id);
        $this->resetDebugging();

        $grade = $assign->get_user_grade($student->id, false);
        $this->assertEquals(85.0, (float) $grade->grade);
        $this->assertFalse($DB->record_exists('assignfeedback_comments', ['grade' => $grade->id]));
    }

    /**
     * The grade must attach to the attempt recorded by the AI, not the latest one.
     *
     * @covers ::apply_ai_feedback
     */
    public function test_apply_ai_feedback_targets_record_attempt(): void {
        global $DB;
        $this->resetAfterTest();

        [$assign, $student] = $this->create_assign_with_student(true, [
            'maxattempts' => 3,
            'attemptreopenmethod' => ASSIGN_ATTEMPT_REOPEN_METHOD_MANUAL,
        ]);
        $teacher = $this->getDataGenerator()->create_and_enrol($assign->get_course(), 'editingteacher');

        // Student submitted attempt 0 and was reopened into attempt 1.
        $assign->get_user_submission($student->id, true, 0);
        $assign->get_user_submission($student->id, true, 1);

        $record = (object) [
            'userid' => $student->id,
            'attemptnumber' => 0,
            'message' => '<p>AI feedback</p>',
            'grade' => 85,
            'rubric_response' => null,
            'assessment_guide_response' => null,
        ];

        feedback_applier::apply_ai_feedback($assign, $record, $teacher->id);
        $this->resetDebugging();

        $graderecord = $DB->get_record('assign_grades', [
            'assignment' => $assign->get_instance()->id,
            'userid' => $student->id,
            'attemptnumber' => 0,
        ]);
        $this->assertNotEmpty($graderecord);
        $this->assertEquals(85.0, (float) $graderecord->grade);

        // The in-progress attempt 1 must not receive the AI grade.
        $latestgrade = $DB->get_record('assign_grades', [
            'assignment' => $assign->get_instance()->id,
            'userid' => $student->id,
            'attemptnumber' => 1,
        ]);
        $this->assertTrue($latestgrade === false || (float) $latestgrade->grade === -1.0);
    }

    /**
     * The grade propagates to the gradebook when the record targets the current later attempt.
     *
     * @covers ::apply_ai_feedback
     */
    public function test_apply_ai_feedback_propagates_to_gradebook_on_later_attempt(): void {
        global $DB;
        $this->resetAfterTest();

        [$assign, $student] = $this->create_assign_with_student(true, [
            'maxattempts' => 3,
            'attemptreopenmethod' => ASSIGN_ATTEMPT_REOPEN_METHOD_MANUAL,
        ]);
        $teacher = $this->getDataGenerator()->create_and_enrol($assign->get_course(), 'editingteacher');

        $assign->get_user_submission($student->id, true, 0);
        $assign->get_user_submission($student->id, true, 1);

        $record = (object) [
            'userid' => $student->id,
            'attemptnumber' => 1,
            'message' => '<p>AI feedback</p>',
            'grade' => 90,
            'rubric_response' => null,
            'assessment_guide_response' => null,
        ];

        feedback_applier::apply_ai_feedback($assign, $record, $teacher->id);
        $this->resetDebugging();

        $graderecord = $DB->get_record('assign_grades', [
            'assignment' => $assign->get_instance()->id,
            'userid' => $student->id,
            'attemptnumber' => 1,
        ], '*', MUST_EXIST);
        $this->assertEquals(90.0, (float) $graderecord->grade);

        $gradinginfo = grade_get_grades(
            $assign->get_course()->id,
            'mod',
            'assign',
            $assign->get_instance()->id,
            $student->id
        );
        $gradebookgrade = $gradinginfo->items[0]->grades[$student->id];
        $this->assertEquals(90.0, (float) $gradebookgrade->grade);
    }
}
