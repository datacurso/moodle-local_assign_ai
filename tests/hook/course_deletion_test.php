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

namespace local_assign_ai\hook;

use local_assign_ai\assign_submission;

/**
 * LAA-AUD-008: deleting a course removes the plugin data of its assignments.
 *
 * Course deletion removes the modules without the course_module_deleted event, so the plugin
 * cleans its rows in the before_course_deleted hook, while the course modules still exist.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \local_assign_ai\hook\course_deletion
 * @group     local_assign_ai
 */
final class course_deletion_test extends \advanced_testcase {
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
     * Creates a course with two assignments, each with pending, config and queue rows.
     *
     * @return array [course, list of [cmid, assign instance id]]
     */
    private function create_course_with_ai_data(): array {
        global $DB;
        $generator = $this->getDataGenerator();
        $aigenerator = $generator->get_plugin_generator('local_assign_ai');

        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student');
        $activities = [];
        for ($i = 0; $i < 2; $i++) {
            $instance = $generator->create_module('assign', ['course' => $course->id]);
            [, $cm] = get_course_and_cm_from_instance($instance->id, 'assign');
            $aigenerator->create_pending([
                'assignmentid' => $cm->id,
                'userid' => $student->id,
                'status' => assign_submission::STATUS_PENDING,
                'grade' => 70,
                'message' => '<p>AI feedback</p>',
            ]);
            $aigenerator->create_config(['assignmentid' => $instance->id, 'graderid' => $student->id]);
            $DB->insert_record('local_assign_ai_queue', (object) [
                'type' => 'submission',
                'payload' => json_encode(['userid' => $student->id, 'cmid' => (int) $cm->id, 'submissiontime' => time()]),
                'timecreated' => time(),
                'timetoprocess' => time(),
                'processed' => 0,
            ]);
            $activities[] = [(int) $cm->id, (int) $instance->id];
        }

        return [$course, $activities];
    }

    /**
     * Counts the plugin rows of a list of activities.
     *
     * @param array $activities List of [cmid, assign instance id].
     * @return int Number of pending, config and queue rows.
     */
    private function count_rows(array $activities): int {
        global $DB;
        $count = 0;
        foreach ($activities as [$cmid, $instanceid]) {
            $count += $DB->count_records('local_assign_ai_pending', ['assignmentid' => $cmid]);
            $count += $DB->count_records('local_assign_ai_config', ['assignmentid' => $instanceid]);
            foreach ($DB->get_records('local_assign_ai_queue') as $row) {
                $count += ((int) (json_decode($row->payload)->cmid ?? 0) === $cmid) ? 1 : 0;
            }
        }
        return $count;
    }

    /**
     * Deleting a course leaves no plugin row of its assignments and keeps the rows of other courses.
     *
     * @covers ::before_course_deleted
     * @covers \local_assign_ai\local\activity_data_cleanup
     */
    public function test_deleting_a_course_removes_its_plugin_rows_only(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $activities] = $this->create_course_with_ai_data();
        [, $otheractivities] = $this->create_course_with_ai_data();
        $this->assertSame(6, $this->count_rows($activities));

        delete_course($course, false);

        $this->assertSame(0, $this->count_rows($activities));
        $this->assertSame(6, $this->count_rows($otheractivities));
    }

    /**
     * Pending rows recorded against the course are removed even when their activity is gone.
     *
     * @covers ::before_course_deleted
     * @covers \local_assign_ai\local\activity_data_cleanup
     */
    public function test_deleting_a_course_removes_pending_rows_by_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course] = $this->create_course_with_ai_data();
        $student = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_assign_ai_pending', (object) [
            'courseid' => $course->id,
            'assignmentid' => 999999,
            'userid' => $student->id,
            'title' => 'orphan',
            'status' => assign_submission::STATUS_FAILED,
            'approval_token' => 'token',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        delete_course($course, false);

        $this->assertSame(0, $DB->count_records('local_assign_ai_pending', ['courseid' => $course->id]));
    }

    /**
     * Deleting a single assignment still cleans its rows through the course module observer.
     *
     * @covers \local_assign_ai\observer\module::course_module_deleted
     * @covers \local_assign_ai\local\activity_data_cleanup
     */
    public function test_deleting_one_assignment_still_removes_its_rows(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $activities] = $this->create_course_with_ai_data();

        course_delete_module($activities[0][0]);

        $this->assertSame(0, $this->count_rows([$activities[0]]));
        $this->assertSame(3, $this->count_rows([$activities[1]]));
    }
}
