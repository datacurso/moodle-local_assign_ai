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

declare(strict_types=1);

namespace local_assign_ai;

use advanced_testcase;
use core_reportbuilder\exception\report_access_exception;
use core_reportbuilder\external\systemreports\retrieve;
use core_reportbuilder\system_report_factory;
use local_assign_ai\reportbuilder\systemreports\history as history_report;

/**
 * Tests for the history report.
 *
 * @package    local_assign_ai
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class history_test extends advanced_testcase {
    /**
     * The report restricts results to the given course/assignment and approved/failed statuses.
     *
     * @covers \local_assign_ai\reportbuilder\systemreports\history
     */
    public function test_filters_to_current_assignment_and_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $cm, $context] = $this->create_assignment_with_review();

        $report = system_report_factory::create(history_report::class, $context, '', '', 0, [
            'courseid' => (int) $course->id,
            'assignmentid' => (int) $cm->id,
        ]);

        [$where, $params] = $report->get_base_condition();

        $this->assertStringContainsString('courseid =', $where);
        $this->assertStringContainsString('assignmentid =', $where);
        $this->assertStringContainsString('status IN', $where);
        $this->assertContains((int) $course->id, $params);
        $this->assertContains((int) $cm->id, $params);
    }

    /**
     * Creates the history report of a new assignment, in its module context, as the given user.
     *
     * @param string $role Role of the viewer in the course.
     * @param bool $canviewidentity Whether the viewer role keeps moodle/site:viewuseridentity.
     * @return history_report
     */
    private function create_report_as(string $role, bool $canviewidentity = true): history_report {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $viewer = $this->getDataGenerator()->create_and_enrol($course, $role);
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        [, $cm] = get_course_and_cm_from_instance($instance->id, 'assign');
        $context = \context_module::instance($cm->id);
        if (!$canviewidentity) {
            $roleid = (int) $DB->get_field('role', 'id', ['shortname' => $role], MUST_EXIST);
            assign_capability('moodle/site:viewuseridentity', CAP_PROHIBIT, $roleid, $context->id, true);
        }
        $this->setUser($viewer);

        return system_report_factory::create(history_report::class, $context, '', '', 0, [
            'courseid' => (int) $course->id,
            'assignmentid' => (int) $cm->id,
        ]);
    }

    /**
     * LAA-AUD-010: with no identity fields configured the report has no email column or filter.
     *
     * @covers \local_assign_ai\reportbuilder\systemreports\history
     */
    public function test_no_email_column_or_filter_when_identity_fields_are_empty(): void {
        $this->resetAfterTest();
        set_config('showuseridentity', '');

        $report = $this->create_report_as('editingteacher');

        $this->assertNull($report->get_column('history:email'));
        $this->assertNull($report->get_filter('history:email'));
        $this->assertNotNull($report->get_column('history:fullname'));
    }

    /**
     * LAA-AUD-010: with email as identity field, a teacher allowed to view identity sees and filters it.
     *
     * @covers \local_assign_ai\reportbuilder\systemreports\history
     */
    public function test_email_column_and_filter_shown_with_identity_capability(): void {
        $this->resetAfterTest();
        set_config('showuseridentity', 'email');

        $report = $this->create_report_as('editingteacher');

        $this->assertNotNull($report->get_column('history:email'));
        $this->assertNotNull($report->get_filter('history:email'));
    }

    /**
     * LAA-AUD-010: without moodle/site:viewuseridentity the email is hidden even when configured.
     *
     * @covers \local_assign_ai\reportbuilder\systemreports\history
     */
    public function test_email_column_and_filter_hidden_without_identity_capability(): void {
        $this->resetAfterTest();
        set_config('showuseridentity', 'email');

        $report = $this->create_report_as('teacher', false);

        $this->assertNull($report->get_column('history:email'));
        $this->assertNull($report->get_filter('history:email'));
    }

    /**
     * Creates a course with one assignment and one approved AI review of an enrolled student.
     *
     * @return array [course, course module, module context]
     */
    private function create_assignment_with_review(): array {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        [, $cm] = get_course_and_cm_from_instance($instance->id, 'assign');
        assign_submission::create_pending_submission((object) [
            'courseid' => $course->id,
            'assignmentid' => $cm->id,
            'userid' => $student->id,
            'title' => 'Review',
            'message' => 'Private feedback',
            'grade' => 42,
            'status' => assign_submission::STATUS_APPROVED,
        ]);
        return [$course, $cm, \context_module::instance($cm->id)];
    }

    /**
     * Retrieves the history report as the dynamic table reload does.
     *
     * @param \context $context Report context sent by the client.
     * @param int $courseid Course id parameter sent by the client.
     * @param int $cmid Assignment (course module) id parameter sent by the client.
     * @return array Rows returned by core_reportbuilder_retrieve_system_report.
     */
    private function retrieve_rows(\context $context, int $courseid, int $cmid): array {
        $result = retrieve::execute(history_report::class, ['contextid' => $context->id], '', '', 0, [
            ['name' => 'courseid', 'value' => (string) $courseid],
            ['name' => 'assignmentid', 'value' => (string) $cmid],
        ], 0, 20);
        return $result['data']['rows'];
    }

    /**
     * LAA-AUD-014: a teacher of course 1 cannot read the history of course 2 with the context of their own assignment.
     *
     * @covers \local_assign_ai\reportbuilder\systemreports\history::can_view
     */
    public function test_retrieve_refuses_another_assignment_with_own_context(): void {
        $this->resetAfterTest();
        [$course1, $cm1, $context1] = $this->create_assignment_with_review();
        [$course2, $cm2] = $this->create_assignment_with_review();
        $teacher = $this->getDataGenerator()->create_and_enrol($course1, 'teacher');
        $this->setUser($teacher);

        $this->expectException(report_access_exception::class);
        $this->retrieve_rows($context1, (int) $course2->id, (int) $cm2->id);
    }

    /**
     * LAA-AUD-014: a course id that does not own the assignment of the context is refused.
     *
     * @covers \local_assign_ai\reportbuilder\systemreports\history::can_view
     */
    public function test_retrieve_refuses_a_course_that_does_not_own_the_assignment(): void {
        $this->resetAfterTest();
        [$course1, $cm1, $context1] = $this->create_assignment_with_review();
        [$course2] = $this->create_assignment_with_review();
        $teacher = $this->getDataGenerator()->create_and_enrol($course1, 'teacher');
        $this->setUser($teacher);

        $this->expectException(report_access_exception::class);
        $this->retrieve_rows($context1, (int) $course2->id, (int) $cm1->id);
    }

    /**
     * LAA-AUD-014: the download recreates the report with client parameters and must refuse another assignment.
     *
     * @covers \local_assign_ai\reportbuilder\systemreports\history::can_view
     */
    public function test_download_refuses_another_assignment_with_own_context(): void {
        $this->resetAfterTest();
        [$course1, $cm1, $context1] = $this->create_assignment_with_review();
        [$course2, $cm2] = $this->create_assignment_with_review();
        $teacher = $this->getDataGenerator()->create_and_enrol($course1, 'teacher');
        $this->setUser($teacher);

        // The download page recreates the report from the client parameters: the tampered ones are refused.
        try {
            system_report_factory::create(history_report::class, $context1, '', '', 0, [
                'courseid' => (int) $course2->id,
                'assignmentid' => (int) $cm2->id,
            ]);
            $this->fail('The report was created for another assignment');
        } catch (report_access_exception $e) {
            $this->assertInstanceOf(report_access_exception::class, $e);
        }

        $own = system_report_factory::create(history_report::class, $context1, '', '', 0, [
            'courseid' => (int) $course1->id,
            'assignmentid' => (int) $cm1->id,
        ]);
        $this->assertTrue($own->can_be_downloaded());
    }

    /**
     * LAA-AUD-014: the history report needs the module context of the assignment it shows.
     *
     * @covers \local_assign_ai\reportbuilder\systemreports\history::can_view
     */
    public function test_refuses_a_context_other_than_the_assignment_module(): void {
        $this->resetAfterTest();
        [$course1, $cm1] = $this->create_assignment_with_review();
        $this->setAdminUser();

        $this->expectException(report_access_exception::class);
        system_report_factory::create(history_report::class, \context_course::instance($course1->id), '', '', 0, [
            'courseid' => (int) $course1->id,
            'assignmentid' => (int) $cm1->id,
        ]);
    }

    /**
     * LAA-AUD-014: the teacher still reads the history of their own assignment.
     *
     * @covers \local_assign_ai\reportbuilder\systemreports\history::can_view
     */
    public function test_retrieve_returns_own_assignment_history(): void {
        $this->resetAfterTest();
        [$course1, $cm1, $context1] = $this->create_assignment_with_review();
        $this->create_assignment_with_review();
        $teacher = $this->getDataGenerator()->create_and_enrol($course1, 'teacher');
        $this->setUser($teacher);

        $rows = $this->retrieve_rows($context1, (int) $course1->id, (int) $cm1->id);

        $this->assertCount(1, $rows);
    }
}
