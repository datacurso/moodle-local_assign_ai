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
}
