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
     * @return array [assign, student]
     */
    private function create_assign_with_student(bool $commentsenabled): array {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $instance = $this->getDataGenerator()->create_module('assign', [
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
}
