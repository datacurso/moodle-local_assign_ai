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

namespace local_assign_ai\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

use local_assign_ai\assign_submission;

/**
 * LAA-AUD-007: approving an AI review honours the mod_assign grading capabilities.
 *
 * Approving writes the grade, so it requires mod/assign:grade. Under marking workflow the
 * state only moves to released when the approver may release grades; otherwise it stops at
 * the most advanced state the approver may set, as mod_assign does.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group     local_assign_ai
 */
final class grade_capability_test extends \advanced_testcase {
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
     * Creates a marking workflow assignment with a grader of the given role and a pending review.
     *
     * @param string $role Role shortname of the grader.
     * @return array [course, cm, grader, student, pending record]
     */
    private function create_scenario(string $role): array {
        $course = $this->getDataGenerator()->create_course();
        $grader = $this->getDataGenerator()->create_and_enrol($course, $role);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'grade' => 100,
            'markingworkflow' => 1,
            'assignfeedback_comments_enabled' => 1,
        ]);
        [, $cm] = get_course_and_cm_from_instance($instance->id, 'assign');
        $pending = $this->getDataGenerator()->get_plugin_generator('local_assign_ai')->create_pending([
            'assignmentid' => $cm->id,
            'userid' => $student->id,
            'status' => assign_submission::STATUS_PENDING,
            'grade' => 70,
            'message' => '<p>AI feedback</p>',
        ]);

        return [$course, $cm, $grader, $student, $pending];
    }

    /**
     * Returns the marking workflow state and the grade of a student.
     *
     * @param \stdClass $course Course.
     * @param \cm_info|\stdClass $cm Course module.
     * @param int $userid Student id.
     * @return array [workflow state, grade or null]
     */
    private function get_student_state(\stdClass $course, $cm, int $userid): array {
        $assign = new \assign(\context_module::instance($cm->id), $cm, $course);
        $flags = $assign->get_user_flags($userid, false);
        $grade = $assign->get_user_grade($userid, false);
        return [$flags->workflowstate ?? null, $grade ? (float) $grade->grade : null];
    }

    /**
     * Prohibits a capability for a role in the module context.
     *
     * @param string $capability Capability name.
     * @param string $role Role shortname.
     * @param \cm_info|\stdClass $cm Course module.
     */
    private function prohibit(string $capability, string $role, $cm): void {
        global $DB;
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => $role], MUST_EXIST);
        assign_capability($capability, CAP_PROHIBIT, $roleid, \context_module::instance($cm->id)->id, true);
    }

    /**
     * An editing teacher approves: the grade is written and released.
     *
     * @covers \local_assign_ai\external\change_status::execute
     * @covers \local_assign_ai\grading\feedback_applier::advance_marking_workflow
     * @runInSeparateProcess
     */
    public function test_editing_teacher_approval_releases_the_grade(): void {
        $this->resetAfterTest();
        [$course, $cm, $grader, $student] = $this->create_scenario('editingteacher');
        $this->setUser($grader);

        change_status::execute((int) $course->id, (int) $cm->id, (int) $student->id, 'approve');
        // The feedback applier traces its steps with developer debugging.
        $this->resetDebugging();

        $this->assertSame([ASSIGN_MARKING_WORKFLOW_STATE_RELEASED, 70.0], $this->get_student_state($course, $cm, $student->id));
    }

    /**
     * A non-editing teacher (no review or release capability) approves: the grade is written but
     * the workflow stops at "ready for review", the last state that role may set in mod_assign.
     *
     * @covers \local_assign_ai\external\change_status::execute
     * @covers \local_assign_ai\grading\feedback_applier::advance_marking_workflow
     * @runInSeparateProcess
     */
    public function test_non_editing_teacher_approval_does_not_release_the_grade(): void {
        $this->resetAfterTest();
        [$course, $cm, $grader, $student] = $this->create_scenario('teacher');
        $this->setUser($grader);

        change_status::execute((int) $course->id, (int) $cm->id, (int) $student->id, 'approve');
        // The feedback applier traces its steps with developer debugging.
        $this->resetDebugging();

        $this->assertSame(
            [ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW, 70.0],
            $this->get_student_state($course, $cm, $student->id)
        );
    }

    /**
     * A grader who may review but not release stops at "ready for release".
     *
     * @covers \local_assign_ai\external\approve_all_pending::execute
     * @covers \local_assign_ai\grading\feedback_applier::advance_marking_workflow
     * @runInSeparateProcess
     */
    public function test_grader_without_releasegrades_leaves_the_grade_ready_for_release(): void {
        $this->resetAfterTest();
        [$course, $cm, $grader, $student] = $this->create_scenario('editingteacher');
        $this->prohibit('mod/assign:releasegrades', 'editingteacher', $cm);
        $this->prohibit('mod/assign:managegrades', 'editingteacher', $cm);
        $this->setUser($grader);

        $result = approve_all_pending::execute((int) $course->id, (int) $cm->id);
        $this->resetDebugging();

        $this->assertSame(1, $result['approved']);
        $this->assertSame(
            [ASSIGN_MARKING_WORKFLOW_STATE_READYFORRELEASE, 70.0],
            $this->get_student_state($course, $cm, $student->id)
        );
    }

    /**
     * Without mod/assign:grade, approving one review is refused and nothing changes.
     *
     * @covers \local_assign_ai\external\change_status::execute
     * @runInSeparateProcess
     */
    public function test_approval_without_grade_capability_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $cm, $grader, $student, $pending] = $this->create_scenario('teacher');
        $this->prohibit('mod/assign:grade', 'teacher', $cm);
        $this->setUser($grader);

        try {
            change_status::execute((int) $course->id, (int) $cm->id, (int) $student->id, 'approve');
            $this->fail('A required_capability_exception was expected.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('mod/assign:grade'), $e->a);
        }

        $this->assertSame(
            assign_submission::STATUS_PENDING,
            $DB->get_field('local_assign_ai_pending', 'status', ['id' => $pending->id])
        );
        $this->assertSame([null, null], $this->get_student_state($course, $cm, $student->id));
    }

    /**
     * Without mod/assign:grade, approving every pending review is refused and nothing changes.
     *
     * @covers \local_assign_ai\external\approve_all_pending::execute
     * @runInSeparateProcess
     */
    public function test_bulk_approval_without_grade_capability_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $cm, $grader, $student, $pending] = $this->create_scenario('teacher');
        $this->prohibit('mod/assign:grade', 'teacher', $cm);
        $this->setUser($grader);

        try {
            approve_all_pending::execute((int) $course->id, (int) $cm->id);
            $this->fail('A required_capability_exception was expected.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('mod/assign:grade'), $e->a);
        }

        $this->assertSame(
            assign_submission::STATUS_PENDING,
            $DB->get_field('local_assign_ai_pending', 'status', ['id' => $pending->id])
        );
        $this->assertSame([null, null], $this->get_student_state($course, $cm, $student->id));
    }

    /**
     * Rejecting a review writes no grade, so it does not need mod/assign:grade.
     *
     * @covers \local_assign_ai\external\change_status::execute
     * @runInSeparateProcess
     */
    public function test_rejection_without_grade_capability_is_allowed(): void {
        $this->resetAfterTest();
        [$course, $cm, $grader, $student] = $this->create_scenario('teacher');
        $this->prohibit('mod/assign:grade', 'teacher', $cm);
        $this->setUser($grader);

        $result = change_status::execute((int) $course->id, (int) $cm->id, (int) $student->id, 'rejected');

        $this->assertSame('rejected', $result['newstatus']);
    }
}
