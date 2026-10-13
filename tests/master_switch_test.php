<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_assign_ai;

use local_assign_ai\config\assignment_config;
use local_assign_ai\external\process_submission;
use local_assign_ai\local\tenant_config;

/**
 * Tests that the master switches (site or tenant) stop every AI processing path.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_assign_ai\config\assignment_config::is_ai_available
 * @covers    \local_assign_ai\config\assignment_config::is_ai_enabled_for_assignment
 * @covers    \local_assign_ai\task\process_ai_queue
 * @covers    \local_assign_ai\task\retry_failed_submissions
 * @covers    \local_assign_ai\external\process_submission
 * @group     local_assign_ai
 */
final class master_switch_test extends \advanced_testcase {
    /**
     * Skips the test when Moodle Workplace tenancy is not installed.
     */
    private function require_tenancy(): void {
        if (!class_exists('\tool_tenant\tenancy')) {
            $this->markTestSkipped('Moodle Workplace (tool_tenant) is not installed.');
        }
    }

    /**
     * Creates a course (inside a new tenant when requested) with an assignment and a student.
     *
     * @param bool $intenant Whether the course belongs to a new tenant.
     * @return array [courseid, cmid, assignid, studentid, tenantid]
     */
    private function create_environment(bool $intenant): array {
        $tenantid = 0;
        $options = [];
        if ($intenant) {
            $category = $this->getDataGenerator()->create_category();
            $tenant = $this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_tenant([
                'name' => 'Switch tenant',
                'categoryid' => $category->id,
            ]);
            $tenantid = (int) $tenant->id;
            $options['category'] = $category->id;
        }
        $course = $this->getDataGenerator()->create_course($options);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $instance->id);

        return [(int) $course->id, (int) $cm->id, (int) $instance->id, (int) $student->id, $tenantid];
    }

    /**
     * Turns a master switch off for a tenant (or for the site when there is no tenant).
     *
     * @param int $tenantid Tenant id, 0 for the site.
     * @param string $setting Setting name (enableassignai or defaultenableai).
     */
    private function switch_off(int $tenantid, string $setting): void {
        if ($tenantid) {
            tenant_config::set($setting, $tenantid, 0);
        } else {
            set_config($setting, 0, 'local_assign_ai');
        }
    }

    /**
     * Combinations of tenancy and master switch.
     *
     * @return array
     */
    public static function switch_provider(): array {
        return [
            'site, feature switch' => [false, 'enableassignai'],
            'site, default ai switch' => [false, 'defaultenableai'],
            'tenant, feature switch' => [true, 'enableassignai'],
            'tenant, default ai switch' => [true, 'defaultenableai'],
        ];
    }

    /**
     * The availability helpers follow the switches of the tenant of the assignment.
     *
     * @dataProvider switch_provider
     * @param bool $intenant Whether the course belongs to a tenant.
     * @param string $setting Switch that is turned off.
     */
    public function test_availability_follows_the_switches(bool $intenant, string $setting): void {
        $this->resetAfterTest();
        if ($intenant) {
            $this->require_tenancy();
        }
        [, , $assignid, , $tenantid] = $this->create_environment($intenant);

        $this->assertTrue(assignment_config::is_ai_available($tenantid));
        $this->assertTrue(assignment_config::is_ai_enabled_for_assignment($assignid));

        $this->switch_off($tenantid, $setting);

        $this->assertFalse(assignment_config::is_ai_available($tenantid));
        $this->assertFalse(assignment_config::is_ai_enabled_for_assignment($assignid));
    }

    /**
     * A delay queue row queued before the switch went off is dropped, not turned into a record.
     *
     * @dataProvider switch_provider
     * @param bool $intenant Whether the course belongs to a tenant.
     * @param string $setting Switch that is turned off.
     */
    public function test_queued_row_is_dropped_when_ai_is_disabled(bool $intenant, string $setting): void {
        global $DB;

        $this->resetAfterTest();
        if ($intenant) {
            $this->require_tenancy();
        }
        [, $cmid, , $studentid, $tenantid] = $this->create_environment($intenant);

        $id = $DB->insert_record('local_assign_ai_queue', (object) [
            'type' => 'submission',
            'payload' => json_encode(['userid' => $studentid, 'cmid' => $cmid]),
            'timecreated' => time() - HOURSECS,
            'timetoprocess' => time() - MINSECS,
            'processed' => 0,
        ]);

        $this->switch_off($tenantid, $setting);
        (new \local_assign_ai\task\process_ai_queue())->execute();

        $this->assertFalse($DB->record_exists('local_assign_ai_queue', ['id' => $id]));
        $this->assertSame(0, $DB->count_records('local_assign_ai_pending'));
    }

    /**
     * The retry task does not requeue (nor count a retry for) failed reviews while the AI is disabled.
     *
     * @dataProvider switch_provider
     * @param bool $intenant Whether the course belongs to a tenant.
     * @param string $setting Switch that is turned off.
     */
    public function test_retry_task_skips_disabled_tenants(bool $intenant, string $setting): void {
        global $DB;

        $this->resetAfterTest();
        if ($intenant) {
            $this->require_tenancy();
        }
        [$courseid, $cmid, , $studentid, $tenantid] = $this->create_environment($intenant);
        $failedid = assign_submission::create_pending_submission((object) [
            'courseid' => $courseid,
            'assignmentid' => $cmid,
            'userid' => $studentid,
            'title' => 'Assignment under test',
            'message' => null,
            'grade' => null,
            'status' => assign_submission::STATUS_FAILED,
        ]);

        $this->switch_off($tenantid, $setting);
        $this->expectOutputRegex('/auto-retried 0 failed AI review\(s\)/');
        (new \local_assign_ai\task\retry_failed_submissions())->execute();

        $record = $DB->get_record('local_assign_ai_pending', ['id' => $failedid], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_FAILED, $record->status);
        $this->assertEquals(0, $record->retries);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(\local_assign_ai\task\process_review_submission::class));
    }

    /**
     * The web service refuses to start AI processing while the AI is disabled and leaves the record alone.
     *
     * @dataProvider switch_provider
     * @param bool $intenant Whether the course belongs to a tenant.
     * @param string $setting Switch that is turned off.
     */
    public function test_process_submission_refuses_when_disabled(bool $intenant, string $setting): void {
        global $DB;

        $this->resetAfterTest();
        if ($intenant) {
            $this->require_tenancy();
        }
        [$courseid, $cmid, , $studentid, $tenantid] = $this->create_environment($intenant);
        $pendingid = assign_submission::create_pending_submission((object) [
            'courseid' => $courseid,
            'assignmentid' => $cmid,
            'userid' => $studentid,
            'title' => 'Assignment under test',
            'message' => null,
            'grade' => null,
            'status' => assign_submission::STATUS_INITIAL,
        ]);
        $this->setAdminUser();

        $this->switch_off($tenantid, $setting);

        foreach ([[$studentid, false, $pendingid], [0, true, 0]] as [$userid, $all, $pending]) {
            try {
                process_submission::execute($cmid, $userid, $all, $pending);
                $this->fail('Starting AI processing must be refused while the AI is disabled.');
            } catch (\moodle_exception $e) {
                $this->assertSame('aiunavailable', $e->errorcode);
            }
        }

        $this->assertSame(
            assign_submission::STATUS_INITIAL,
            $DB->get_field('local_assign_ai_pending', 'status', ['id' => $pendingid])
        );
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(\local_assign_ai\task\process_review_submission::class));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(\local_assign_ai\task\process_all_submissions::class));
    }
}
