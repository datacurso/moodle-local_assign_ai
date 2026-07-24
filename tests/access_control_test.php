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
 * Tests for the capability checks of the plugin external functions.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai;

use local_assign_ai\external\approve_all_pending;
use local_assign_ai\external\cancel_review;
use local_assign_ai\external\change_status;
use local_assign_ai\external\get_details;
use local_assign_ai\external\get_progress;
use local_assign_ai\external\get_token;
use local_assign_ai\external\process_submission;
use local_assign_ai\external\update_response;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Unit tests for the capability checks of the plugin external functions (MDL-INT-020).
 *
 * @covers \local_assign_ai\external\process_submission
 * @covers \local_assign_ai\external\cancel_review
 * @covers \local_assign_ai\external\get_details
 * @covers \local_assign_ai\external\update_response
 * @covers \local_assign_ai\external\change_status
 * @covers \local_assign_ai\external\approve_all_pending
 * @group local_assign_ai
 */
final class access_control_test extends \externallib_advanced_testcase {
    /**
     * Create a course with an assignment, an enrolled student and its module context.
     *
     * @return array The course, assign object, course module id and student user.
     */
    private function create_env(): array {
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $instance->id);
        $context = \context_module::instance($cm->id);
        $assign = new \assign($context, $cm, $course);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        return [$course, $assign, (int) $cm->id, $student];
    }

    /**
     * Insert a pending AI record for the given student.
     *
     * The change_status/update_response/get_details services resolve the record before running
     * their capability checks, so one must exist to exercise those checks.
     *
     * @param \assign $assign The assignment instance.
     * @param int $userid The student user id the AI feedback belongs to.
     * @return \stdClass The inserted record.
     */
    private function create_pending_record(\assign $assign, int $userid): \stdClass {
        global $DB;

        $now = time();
        $record = (object) [
            'courseid' => $assign->get_course()->id,
            'assignmentid' => $assign->get_course_module()->id,
            'title' => $assign->get_instance()->name,
            'userid' => $userid,
            'submissionid' => null,
            'attemptnumber' => 0,
            'submissionmodified' => $now,
            'edited' => 0,
            'message' => 'AI feedback message',
            'grade' => 50,
            'rubric_response' => null,
            'assessment_guide_response' => null,
            'status' => assign_submission::STATUS_PENDING,
            'approval_token' => md5('local_assign_ai_test_' . $userid . '_' . $now),
            'usermodified' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('local_assign_ai_pending', $record);

        return $record;
    }

    /**
     * Create an enrolled user holding only the local/assign_ai:review capability.
     *
     * @param \stdClass $course The course to enrol in.
     * @return \stdClass The reviewer user.
     */
    private function create_review_only_user(\stdClass $course): \stdClass {
        $reviewer = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'aireviewonly']);
        assign_capability('local/assign_ai:review', CAP_ALLOW, $roleid, \context_course::instance($course->id)->id);
        role_assign($roleid, $reviewer->id, \context_course::instance($course->id)->id);

        return $reviewer;
    }

    /**
     * MDL-INT-020: A user without local/assign_ai:review cannot trigger AI processing.
     */
    public function test_process_submission_requires_review_capability(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [, , $cmid, $student] = $this->create_env();

        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        process_submission::execute($cmid);
    }

    /**
     * MDL-INT-020: A user without local/assign_ai:review cannot cancel a queued review.
     */
    public function test_cancel_review_requires_review_capability(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [, $assign, $cmid, $student] = $this->create_env();
        $record = $this->create_pending_record($assign, (int) $student->id);

        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        cancel_review::execute($cmid, (int) $record->id);
    }

    /**
     * MDL-INT-020: get_progress silently drops records the caller cannot review.
     *
     * A student (no local/assign_ai:review capability) who passes a valid pending record id
     * must receive an empty result — the record is not returned and no exception is thrown,
     * so callers cannot enumerate activity ids they do not own.
     */
    public function test_get_progress_should_require_review_capability(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [, $assign, , $student] = $this->create_env();
        $record = $this->create_pending_record($assign, (int) $student->id);

        // Switch to the student, who holds no local/assign_ai:review capability.
        $this->setUser($student);

        $result = get_progress::execute([(int) $record->id]);

        // The record must be silently dropped — not returned, no exception.
        $this->assertSame([], $result);
    }

    /**
     * MDL-INT-020: A user without local/assign_ai:viewdetails cannot read AI feedback details.
     */
    public function test_get_details_requires_viewdetails_capability(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $assign, $cmid, $student] = $this->create_env();
        $this->create_pending_record($assign, (int) $student->id);

        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        get_details::execute((int) $course->id, $cmid, (int) $student->id);
    }

    /**
     * MDL-INT-020: Holding local/assign_ai:review alone is not enough to edit the AI message;
     * update_response demands local/assign_ai:changestatus.
     */
    public function test_update_response_requires_changestatus_capability(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $assign, $cmid, $student] = $this->create_env();
        $this->create_pending_record($assign, (int) $student->id);
        $reviewer = $this->create_review_only_user($course);

        $this->setUser($reviewer);
        $this->expectException(\required_capability_exception::class);
        update_response::execute((int) $course->id, $cmid, (int) $student->id, 'Tampered message');
    }

    /**
     * MDL-INT-020: Holding local/assign_ai:review alone is not enough to approve or reject;
     * change_status demands local/assign_ai:changestatus.
     */
    public function test_change_status_requires_changestatus_capability(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $assign, $cmid, $student] = $this->create_env();
        $this->create_pending_record($assign, (int) $student->id);
        $reviewer = $this->create_review_only_user($course);

        $this->setUser($reviewer);
        $this->expectException(\required_capability_exception::class);
        change_status::execute((int) $course->id, $cmid, (int) $student->id, 'approve');
    }

    /**
     * MDL-INT-020: Students hold none of the plugin capabilities, so the management services
     * (here the bulk approval) are rejected for them as well.
     */
    public function test_students_cannot_use_management_externals(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $assign, $cmid, $student] = $this->create_env();
        $this->create_pending_record($assign, (int) $student->id);

        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        approve_all_pending::execute((int) $course->id, $cmid);
    }

    /**
     * MDL-INT-020: get_token must throw when the caller lacks local/assign_ai:review.
     *
     * The execute method resolves the module context from the assignmentid (cmid) and calls
     * require_capability('local/assign_ai:review', ...), so a student without that capability
     * must receive a required_capability_exception.
     */
    public function test_get_token_should_require_capability_validation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [, $assign, $cmid, $student] = $this->create_env();
        $this->create_pending_record($assign, (int) $student->id);

        // Switch to the student, who holds no local/assign_ai:review capability.
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        get_token::execute((int) $student->id, $cmid);
    }
}
