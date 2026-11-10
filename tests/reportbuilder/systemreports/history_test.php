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
use context_system;
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

        $report = system_report_factory::create(history_report::class, context_system::instance(), '', '', 0, [
            'courseid' => 42,
            'assignmentid' => 84,
        ]);

        [$where, $params] = $report->get_base_condition();

        $this->assertStringContainsString('courseid =', $where);
        $this->assertStringContainsString('assignmentid =', $where);
        $this->assertStringContainsString('status IN', $where);
        $this->assertContains(42, $params);
        $this->assertContains(84, $params);
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
}
