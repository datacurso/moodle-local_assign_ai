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

namespace local_assign_ai\local;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

use core_reportbuilder\system_report_factory;
use local_assign_ai\assign_submission;
use local_assign_ai\external\approve_all_pending;
use local_assign_ai\external\cancel_review;
use local_assign_ai\external\change_status;
use local_assign_ai\external\get_details;
use local_assign_ai\external\get_progress;
use local_assign_ai\external\get_token;
use local_assign_ai\external\process_submission;
use local_assign_ai\external\update_response;
use local_assign_ai\local\service\history_service;
use local_assign_ai\reportbuilder\systemreports\history as history_report;
use local_assign_ai\table\review_table;

/**
 * LAA-AUD-009: AI reviews honour separate groups.
 *
 * In separate groups mode a teacher without moodle/site:accessallgroups only sees, reads and
 * acts on the AI reviews of students of the groups they may access, as in mod_assign. A review
 * of another group fails exactly like an absent one (invalidpendingrecord).
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group     local_assign_ai
 */
final class separate_groups_test extends \advanced_testcase {
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
     * Creates a course in the given group mode with a non-editing teacher and a student in group A,
     * a student in group B, an editing teacher in no group, and one review per student.
     *
     * @param int $groupmode Group mode of the course (forced).
     * @param string $status Status of both reviews.
     * @return \stdClass Scenario with course, cm, teacher, editingteacher, studenta, studentb, pendinga, pendingb.
     */
    private function create_scenario(int $groupmode = SEPARATEGROUPS, string $status = 'pending'): \stdClass {
        $generator = $this->getDataGenerator();
        $aigenerator = $generator->get_plugin_generator('local_assign_ai');

        $course = $generator->create_course(['groupmode' => $groupmode, 'groupmodeforce' => 1]);
        $teacher = $generator->create_and_enrol($course, 'teacher');
        $editingteacher = $generator->create_and_enrol($course, 'editingteacher');
        $studenta = $generator->create_and_enrol($course, 'student');
        $studentb = $generator->create_and_enrol($course, 'student');
        $groupa = $generator->create_group(['courseid' => $course->id]);
        $groupb = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $studenta->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $studentb->id]);

        $instance = $generator->create_module('assign', ['course' => $course->id, 'grade' => 100]);
        [, $cm] = get_course_and_cm_from_instance($instance->id, 'assign');
        $pendinga = $aigenerator->create_pending([
            'assignmentid' => $cm->id, 'userid' => $studenta->id, 'status' => $status, 'grade' => 70, 'message' => 'A',
        ]);
        $pendingb = $aigenerator->create_pending([
            'assignmentid' => $cm->id, 'userid' => $studentb->id, 'status' => $status, 'grade' => 70, 'message' => 'B',
        ]);

        return (object) compact('course', 'cm', 'teacher', 'editingteacher', 'studenta', 'studentb', 'pendinga', 'pendingb');
    }

    /**
     * Asserts that a call fails with the uniform not-found error.
     *
     * @param callable $call The call.
     * @return void
     */
    private function assert_not_found(callable $call): void {
        try {
            $call();
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidpendingrecord', $e->errorcode);
            return;
        }
        $this->fail('invalidpendingrecord was expected.');
    }

    /**
     * Returns the status of a pending record.
     *
     * @param \stdClass $pending Pending record.
     * @return string
     */
    private function status_of(\stdClass $pending): string {
        global $DB;
        return (string) $DB->get_field('local_assign_ai_pending', 'status', ['id' => $pending->id]);
    }

    /**
     * get_details does not return the review of a student of another group.
     *
     * @covers \local_assign_ai\external\get_details::execute
     * @covers \local_assign_ai\local\activity_access::require_student_access
     * @runInSeparateProcess
     */
    public function test_get_details_hides_other_groups(): void {
        $this->resetAfterTest();
        $s = $this->create_scenario();
        $this->setUser($s->teacher);

        $own = get_details::execute((int) $s->course->id, (int) $s->cm->id, (int) $s->studenta->id);
        $this->assertSame('pending', $own['status']);
        $this->assert_not_found(fn() => get_details::execute((int) $s->course->id, (int) $s->cm->id, (int) $s->studentb->id));
    }

    /**
     * change_status and update_response cannot act on a review of another group.
     *
     * @covers \local_assign_ai\external\change_status::execute
     * @covers \local_assign_ai\external\update_response::execute
     * @covers \local_assign_ai\local\activity_access::require_pending_record
     * @runInSeparateProcess
     */
    public function test_change_status_and_update_response_refuse_other_groups(): void {
        $this->resetAfterTest();
        $s = $this->create_scenario();
        $this->setUser($s->teacher);

        $this->assert_not_found(
            fn() => change_status::execute((int) $s->course->id, (int) $s->cm->id, (int) $s->studentb->id, 'approve')
        );
        $this->assert_not_found(
            fn() => update_response::execute((int) $s->course->id, (int) $s->cm->id, (int) $s->studentb->id, 'Edited')
        );
        $this->assertSame('pending', $this->status_of($s->pendingb));

        $result = change_status::execute((int) $s->course->id, (int) $s->cm->id, (int) $s->studenta->id, 'rejected');
        $this->assertSame('rejected', $result['newstatus']);
    }

    /**
     * approve_all_pending only approves the reviews of the teacher's groups.
     *
     * @covers \local_assign_ai\external\approve_all_pending::execute
     * @covers \local_assign_ai\local\activity_access::get_student_filter_sql
     * @runInSeparateProcess
     */
    public function test_approve_all_pending_only_approves_own_groups(): void {
        $this->resetAfterTest();
        $s = $this->create_scenario();
        $this->setUser($s->teacher);

        $result = approve_all_pending::execute((int) $s->course->id, (int) $s->cm->id);
        $this->resetDebugging();

        $this->assertSame(1, $result['approved']);
        $this->assertSame('approve', $this->status_of($s->pendinga));
        $this->assertSame('pending', $this->status_of($s->pendingb));
    }

    /**
     * process_submission, cancel_review and get_token refuse a review of another group, and
     * "Review all" only queues the teacher's groups.
     *
     * @covers \local_assign_ai\external\process_submission::execute
     * @covers \local_assign_ai\external\cancel_review::execute
     * @covers \local_assign_ai\external\get_token::execute
     * @runInSeparateProcess
     */
    public function test_review_actions_refuse_other_groups(): void {
        $this->resetAfterTest();
        $s = $this->create_scenario(SEPARATEGROUPS, assign_submission::STATUS_INITIAL);
        $this->setUser($s->teacher);

        $this->assert_not_found(
            fn() => process_submission::execute((int) $s->cm->id, (int) $s->studentb->id, false, (int) $s->pendingb->id)
        );
        $this->assert_not_found(fn() => cancel_review::execute((int) $s->cm->id, (int) $s->pendingb->id));
        $this->assert_not_found(fn() => get_token::execute((int) $s->studentb->id, (int) $s->cm->id));
        $this->assertNotSame('', get_token::execute((int) $s->studenta->id, (int) $s->cm->id)['approval_token']);

        process_submission::execute((int) $s->cm->id, 0, true, 0);
        $this->assertSame(assign_submission::STATUS_QUEUED, $this->status_of($s->pendinga));
        $this->assertSame(assign_submission::STATUS_INITIAL, $this->status_of($s->pendingb));
    }

    /**
     * get_progress drops the records of other groups.
     *
     * @covers \local_assign_ai\external\get_progress::execute
     * @runInSeparateProcess
     */
    public function test_get_progress_drops_other_groups(): void {
        $this->resetAfterTest();
        $s = $this->create_scenario();
        $this->setUser($s->teacher);

        $result = get_progress::execute([(int) $s->pendinga->id, (int) $s->pendingb->id]);

        $this->assertSame([(int) $s->pendinga->id], array_column($result, 'id'));
    }

    /**
     * The review table lists only the students of the teacher's groups.
     *
     * @covers \local_assign_ai\table\review_table
     * @runInSeparateProcess
     */
    public function test_review_table_lists_only_own_groups(): void {
        $this->resetAfterTest();
        $s = $this->create_scenario();
        $this->setUser($s->teacher);

        $this->assertSame([(int) $s->studenta->id], $this->review_table_userids($s));
    }

    /**
     * The history report and its record actions only cover the teacher's groups.
     *
     * @covers \local_assign_ai\reportbuilder\systemreports\history
     * @covers \local_assign_ai\local\service\history_service
     * @runInSeparateProcess
     */
    public function test_history_only_covers_own_groups(): void {
        $this->resetAfterTest();
        $s = $this->create_scenario(SEPARATEGROUPS, assign_submission::STATUS_FAILED);
        $this->setUser($s->teacher);

        $this->assertSame([(int) $s->studenta->id], $this->history_userids($s));
        $this->assert_not_found(fn() => history_service::get_log_record((int) $s->cm->id, (int) $s->pendingb->id));
        $this->assert_not_found(fn() => history_service::retry_review((int) $s->cm->id, (int) $s->pendingb->id));
        $this->assertSame((int) $s->pendinga->id, (int) history_service::get_log_record((int) $s->cm->id, (int) $s->pendinga->id)->id);
    }

    /**
     * A teacher with moodle/site:accessallgroups sees and acts on every group.
     *
     * @covers \local_assign_ai\local\activity_access::get_student_filter_sql
     * @runInSeparateProcess
     */
    public function test_teacher_with_accessallgroups_sees_every_group(): void {
        $this->resetAfterTest();
        $s = $this->create_scenario();
        $this->setUser($s->editingteacher);

        $this->assertSame('pending', get_details::execute((int) $s->course->id, (int) $s->cm->id, (int) $s->studentb->id)['status']);
        $ids = [(int) $s->studenta->id, (int) $s->studentb->id];
        sort($ids);
        $this->assertSame($ids, $this->review_table_userids($s));
    }

    /**
     * In visible groups mode every teacher sees every group.
     *
     * @covers \local_assign_ai\local\activity_access::get_student_filter_sql
     * @runInSeparateProcess
     */
    public function test_visible_groups_do_not_restrict(): void {
        $this->resetAfterTest();
        $s = $this->create_scenario(VISIBLEGROUPS);
        $this->setUser($s->teacher);

        $this->assertSame('pending', get_details::execute((int) $s->course->id, (int) $s->cm->id, (int) $s->studentb->id)['status']);
        $ids = [(int) $s->studenta->id, (int) $s->studentb->id];
        sort($ids);
        $this->assertSame($ids, $this->review_table_userids($s));
    }

    /**
     * Returns the sorted student ids listed by the review table.
     *
     * @param \stdClass $s Scenario.
     * @return int[]
     */
    private function review_table_userids(\stdClass $s): array {
        $assign = new \assign(\context_module::instance($s->cm->id), $s->cm, $s->course);
        $table = new review_table('local_assign_ai_review_test', $assign);
        $table->setup();
        $table->query_db(100, false);
        $ids = array_map(fn($row) => (int) $row->userid, array_values($table->rawdata));
        sort($ids);
        return $ids;
    }

    /**
     * Returns the sorted student ids matched by the history report base condition.
     *
     * @param \stdClass $s Scenario.
     * @return int[]
     */
    private function history_userids(\stdClass $s): array {
        global $DB;
        $report = system_report_factory::create(history_report::class, \context_module::instance($s->cm->id), '', '', 0, [
            'courseid' => (int) $s->course->id,
            'assignmentid' => (int) $s->cm->id,
        ]);
        [$where, $params] = $report->get_base_condition();
        $alias = $report->get_main_table_alias();
        $ids = $DB->get_fieldset_sql("SELECT {$alias}.userid FROM {local_assign_ai_pending} {$alias} WHERE {$where}", $params);
        $ids = array_map('intval', $ids);
        sort($ids);
        return $ids;
    }
}
