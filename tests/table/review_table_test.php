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
 * Tests for the review table.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai\table;

use local_assign_ai\assign_submission;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Unit tests for the review table actions column.
 *
 * @coversDefaultClass \local_assign_ai\table\review_table
 * @group local_assign_ai
 */
final class review_table_test extends \advanced_testcase {
    /**
     * Creates an assignment and returns the assign instance with a student.
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
     * Builds a pending-status stub row for the actions column.
     *
     * @param \assign $assign The assignment instance.
     * @param int $userid The student user ID.
     * @return \stdClass
     */
    private function build_pending_row(\assign $assign, int $userid): \stdClass {
        return (object) [
            'id' => 1,
            'courseid' => $assign->get_course()->id,
            'assignmentid' => $assign->get_course_module()->id,
            'userid' => $userid,
            'aistatus' => assign_submission::STATUS_PENDING,
        ];
    }

    /**
     * The details button is hidden when the comments feedback plugin is disabled.
     *
     * @covers ::col_actions
     */
    public function test_details_button_hidden_when_comments_disabled(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$assign, $student] = $this->create_assign_with_student(false);
        $table = new review_table('test', $assign);

        $html = $table->col_actions($this->build_pending_row($assign, $student->id));

        $this->assertStringNotContainsString('js-btn-details', $html);
    }

    /**
     * The details button is shown when the comments feedback plugin is enabled.
     *
     * @covers ::col_actions
     */
    public function test_details_button_shown_when_comments_enabled(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$assign, $student] = $this->create_assign_with_student(true);
        $table = new review_table('test', $assign);

        $html = $table->col_actions($this->build_pending_row($assign, $student->id));

        $this->assertStringContainsString('js-btn-details', $html);
    }
}
