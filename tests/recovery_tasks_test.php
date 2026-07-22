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
 * Tests for the stuck/failed AI review recovery tasks.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai;

/**
 * Unit tests for the stuck/failed AI review recovery scheduled tasks.
 *
 * @group local_assign_ai
 */
final class recovery_tasks_test extends \advanced_testcase {
    /**
     * Create a course with an assignment and a student, returning ids used by pending records.
     *
     * @return array Array with courseid, cmid and the student user record.
     */
    private function create_assign_environment(): array {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $instance->id);

        return [(int) $course->id, (int) $cm->id, $student];
    }

    /**
     * Create a pending AI record with the given status.
     *
     * @param int $courseid Course id.
     * @param int $cmid Assignment course module id.
     * @param int $userid Student user id.
     * @param string $status Pending record status.
     * @param array $overrides Extra column overrides applied after creation.
     * @return int The pending record id.
     */
    private function create_pending_record(int $courseid, int $cmid, int $userid, string $status,
            array $overrides = []): int {
        global $DB;

        $id = assign_submission::create_pending_submission((object) [
            'courseid' => $courseid,
            'assignmentid' => $cmid,
            'userid' => $userid,
            'title' => 'Assignment under test',
            'message' => null,
            'grade' => null,
            'status' => $status,
        ]);

        foreach ($overrides as $field => $value) {
            $DB->set_field('local_assign_ai_pending', $field, $value, ['id' => $id]);
        }

        return $id;
    }

    /**
     * MDL-INT-013: Records stuck in queued/processing for longer than the timeout are marked
     * as failed with the timeout error message, while recent ones are left untouched.
     *
     * @covers \local_assign_ai\task\reap_stuck_submissions::execute
     */
    public function test_stuck_records_are_marked_failed_after_timeout(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectOutputRegex('/marked 2 stuck AI review\(s\) as failed/');

        [$courseid, $cmid, $student] = $this->create_assign_environment();

        $stuckthreshold = time() - (16 * MINSECS);
        $stuckqueued = $this->create_pending_record($courseid, $cmid, (int) $student->id,
            assign_submission::STATUS_QUEUED, ['timemodified' => $stuckthreshold]);
        $stuckprocessing = $this->create_pending_record($courseid, $cmid, (int) $student->id,
            assign_submission::STATUS_PROCESSING, ['timemodified' => $stuckthreshold]);
        $recentqueued = $this->create_pending_record($courseid, $cmid, (int) $student->id,
            assign_submission::STATUS_QUEUED);

        (new \local_assign_ai\task\reap_stuck_submissions())->execute();

        $timeoutmessage = get_string('error_processing_timeout', 'local_assign_ai');

        $record = $DB->get_record('local_assign_ai_pending', ['id' => $stuckqueued], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_FAILED, $record->status);
        $this->assertSame($timeoutmessage, $record->errormessage);

        $record = $DB->get_record('local_assign_ai_pending', ['id' => $stuckprocessing], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_FAILED, $record->status);
        $this->assertSame($timeoutmessage, $record->errormessage);

        $record = $DB->get_record('local_assign_ai_pending', ['id' => $recentqueued], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_QUEUED, $record->status);
        $this->assertNull($record->errormessage);
    }

    /**
     * MDL-INT-013: Failed records with automatic retries left are re-queued and the retry
     * counter is incremented.
     *
     * @covers \local_assign_ai\task\retry_failed_submissions::execute
     */
    public function test_failed_records_with_retries_left_are_requeued(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectOutputRegex('/auto-retried 1 failed AI review\(s\)/');

        [$courseid, $cmid, $student] = $this->create_assign_environment();

        $failedid = $this->create_pending_record($courseid, $cmid, (int) $student->id,
            assign_submission::STATUS_FAILED);

        (new \local_assign_ai\task\retry_failed_submissions())->execute();

        $record = $DB->get_record('local_assign_ai_pending', ['id' => $failedid], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_QUEUED, $record->status);
        $this->assertEquals(1, $record->retries);

        $tasks = \core\task\manager::get_adhoc_tasks(\local_assign_ai\task\process_review_submission::class);
        $this->assertCount(1, $tasks);
    }

    /**
     * MDL-INT-013: A failed record that already reached the retry limit is not re-queued and
     * stays failed for manual retry.
     *
     * @covers \local_assign_ai\task\retry_failed_submissions::execute
     */
    public function test_failed_record_at_retry_limit_is_not_requeued(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        [$courseid, $cmid, $student] = $this->create_assign_environment();

        $failedid = $this->create_pending_record($courseid, $cmid, (int) $student->id,
            assign_submission::STATUS_FAILED, ['retries' => 3]);

        (new \local_assign_ai\task\retry_failed_submissions())->execute();

        $record = $DB->get_record('local_assign_ai_pending', ['id' => $failedid], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_FAILED, $record->status);
        $this->assertEquals(3, $record->retries);

        $tasks = \core\task\manager::get_adhoc_tasks(\local_assign_ai\task\process_review_submission::class);
        $this->assertCount(0, $tasks);
    }
}
