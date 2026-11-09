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
     * Skips every test when the Datacurso AI provider is not installed.
     */
    protected function setUp(): void {
        parent::setUp();
        // The CI pipeline does not install declared dependencies; without the Datacurso AI provider these tests cannot run.
        if (!class_exists(\aiprovider_datacurso\local\plugin_settings::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed.');
        }
    }

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

    /**
     * Without identity fields configured the table shows no email column.
     *
     * @covers ::__construct
     */
    public function test_no_email_column_when_identity_fields_are_empty(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('showuseridentity', '');

        [$assign] = $this->create_assign_with_student(true);
        $table = new review_table('test', $assign);

        $this->assertArrayNotHasKey('email', $table->columns);
    }

    /**
     * With email as identity field a teacher allowed to view identities sees the email column.
     *
     * @covers ::__construct
     * @covers ::other_cols
     */
    public function test_email_column_shown_to_teacher_with_identity_capability(): void {
        $this->resetAfterTest();
        set_config('showuseridentity', 'email');

        [$assign, $student] = $this->create_assign_with_student(true);
        $teacher = $this->getDataGenerator()->create_and_enrol($assign->get_course(), 'editingteacher');
        $this->setUser($teacher);
        $this->assertTrue(has_capability('moodle/site:viewuseridentity', $assign->get_context()));

        $table = new review_table('test', $assign);

        $this->assertArrayHasKey('email', $table->columns);
        $this->assertSame($student->email, $table->other_cols('email', (object) ['email' => $student->email]));
    }

    /**
     * A teacher without the identity capability never sees the email column.
     *
     * @covers ::__construct
     */
    public function test_email_column_hidden_without_identity_capability(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('showuseridentity', 'email');

        [$assign] = $this->create_assign_with_student(true);
        $teacher = $this->getDataGenerator()->create_and_enrol($assign->get_course(), 'editingteacher');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('moodle/site:viewuseridentity', CAP_PROHIBIT, $roleid, $assign->get_context()->id, true);
        $this->setUser($teacher);

        $table = new review_table('test', $assign);

        $this->assertArrayNotHasKey('email', $table->columns);
    }

    /**
     * Identity values are escaped when rendered.
     *
     * @covers ::other_cols
     */
    public function test_identity_value_is_escaped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('showuseridentity', 'email,department');

        [$assign] = $this->create_assign_with_student(true);
        $table = new review_table('test', $assign);

        $html = $table->other_cols('department', (object) ['department' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * The table query returns the identity fields of each student.
     *
     * @covers ::__construct
     */
    public function test_query_selects_identity_fields(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('showuseridentity', 'email,department');

        [$assign, $student] = $this->create_assign_with_student(true);
        $this->getDataGenerator()->get_plugin_generator('local_assign_ai')->create_pending([
            'assignmentid' => $assign->get_course_module()->id,
            'userid' => $student->id,
            'status' => assign_submission::STATUS_INITIAL,
        ]);
        $table = new review_table('test', $assign);
        $table->setup();
        $table->query_db(10, false);

        $rows = array_values($table->rawdata);
        $this->assertCount(1, $rows);
        $this->assertSame($student->email, $rows[0]->email);
        $this->assertTrue(property_exists($rows[0], 'department'));
    }

    /**
     * A hint that contains markup is escaped in the AI status column.
     *
     * @covers ::col_aistatus
     */
    public function test_failed_hint_with_markup_is_escaped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$assign, $student] = $this->create_assign_with_student(true);
        $table = new review_table('test', $assign);
        $row = $this->build_pending_row($assign, $student->id);
        $row->aistatus = assign_submission::STATUS_FAILED;
        $row->errormessage = '<script>alert("x")</script>';

        $html = $table->col_aistatus($row);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
