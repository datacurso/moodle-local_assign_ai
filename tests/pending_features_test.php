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
 * Placeholders for scope features the plugin does not implement yet.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai;

use local_assign_ai\grading\feedback_applier;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');

/**
 * Placeholder tests tracing scope items that are pending implementation (MDL-INT-025..031).
 *
 * Each method maps one scope feature the plugin does not implement yet to its spec id, so the
 * suite documents the gap and the placeholder turns into a real test once the feature lands.
 *
 * @group local_assign_ai
 */
final class pending_features_test extends \advanced_testcase {
    use \mod_assign_test_generator;

    /**
     * Bump the assign instance id sequence past the given id so the per-process static cache in
     * assignment_config never conflicts with ids reused by the database reset.
     *
     * @param int $id Filler id imported directly into the assign table.
     * @param int $courseid Course id used by the filler row.
     * @return void
     */
    private function bump_assign_sequence(int $id, int $courseid): void {
        global $DB;

        $DB->import_record('assign', (object) [
            'id' => $id,
            'course' => $courseid,
            'name' => 'filler',
            'intro' => '',
            'introformat' => FORMAT_HTML,
        ]);
        $DB->get_manager()->reset_sequence('assign');
    }

    /**
     * Insert a minimal pending AI record for the given student, mirroring the pattern used across
     * the plugin test suite.
     *
     * @param \assign $assign The assignment instance.
     * @param \stdClass $student The student the AI feedback belongs to.
     * @param array $overrides Column overrides (grade, message, ...).
     * @return \stdClass The inserted record.
     */
    private function create_pending_record(\assign $assign, \stdClass $student, array $overrides = []): \stdClass {
        global $DB;

        $now = time();
        $record = (object) array_merge([
            'courseid' => $assign->get_course()->id,
            'assignmentid' => $assign->get_course_module()->id,
            'title' => $assign->get_instance()->name,
            'userid' => $student->id,
            'submissionid' => null,
            'attemptnumber' => 0,
            'submissionmodified' => $now,
            'edited' => 0,
            'message' => 'AI feedback message',
            'grade' => null,
            'rubric_response' => null,
            'assessment_guide_response' => null,
            'status' => 'pending',
            'approval_token' => md5('local_assign_ai_test_' . $student->id . '_' . $now),
            'usermodified' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ], $overrides);
        $record->id = $DB->insert_record('local_assign_ai_pending', $record);

        return $record;
    }

    /**
     * MDL-INT-025: Applying AI feedback should respect the state of the "feedback comments"
     * plugin type and never overwrite the student submission text with the inline comment.
     *
     * @covers \local_assign_ai\grading\feedback_applier::is_comments_plugin_active
     * @covers \local_assign_ai\grading\feedback_applier::save_feedback_comments
     */
    public function test_feedback_comments_plugin_state_is_respected(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(5010, $course->id);

        // Create an assignment with the feedback_comments plugin explicitly disabled.
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignfeedback_comments_enabled' => 0,
        ]);

        // Is_comments_plugin_active() must report false when the plugin is disabled.
        $this->assertFalse(feedback_applier::is_comments_plugin_active($assign));

        $this->add_submission($student, $assign, 'My essay text');

        // Create a pending record with a non-null message so save_feedback_comments() would
        // normally write to the database.
        $record = $this->create_pending_record($assign, $student, [
            'grade' => 70,
            'message' => 'This comment must NOT be saved when comments are disabled.',
        ]);

        feedback_applier::apply_ai_feedback($assign, $record, (int) $teacher->id);

        // The grade must still be applied (comments disabled does not block grading).
        $grade = $assign->get_user_grade($student->id, false);
        $this->assertNotEmpty($grade);
        $this->assertEquals(70.0, (float) $grade->grade);

        // No feedback comment row must exist because the plugin is disabled.
        $this->assertFalse($DB->record_exists('assignfeedback_comments', ['grade' => $grade->id]));

        $this->resetDebugging();
    }

    /**
     * MDL-INT-026: The AI review should be able to produce feedback files attached to the grade.
     */
    public function test_feedback_files_are_generated(): void {
        $this->markTestSkipped('Pending feature: feedback files are not generated (MDL-INT-026).');
    }

    /**
     * MDL-INT-027: Publishing an AI grade should coordinate with the grade-to-pass setting and
     * the automatic attempt reopening method of the assignment.
     *
     * The plugin delegates attempt reopening to Moodle core via {@see \assign::update_grade()}
     * and passes the correct attempt number. This test verifies the grade is applied correctly
     * and the attempt number is honoured when the assignment is configured with "until pass".
     *
     * @covers \local_assign_ai\grading\feedback_applier::apply_ai_feedback
     */
    public function test_grade_to_pass_and_attempt_reopening_are_coordinated(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(5020, $course->id);

        // Create assignment with "reopen until pass" and grade-to-pass = 50.
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignfeedback_comments_enabled' => 1,
            'maxattempts' => -1,
            'attemptreopenmethod' => ASSIGN_ATTEMPT_REOPEN_METHOD_UNTILPASS,
            'grade' => 100,
        ]);

        // Set grade-to-pass in the gradebook.
        $item = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $assign->get_instance()->id,
            'courseid' => $course->id,
        ]);
        $item->gradepass = 50;
        $item->update();

        $this->add_submission($student, $assign, 'My essay attempt 0');

        // Apply a failing grade (below pass threshold).
        $record = $this->create_pending_record($assign, $student, [
            'grade' => 30,
            'message' => 'Below pass threshold',
        ]);

        $this->redirectMessages();
        feedback_applier::apply_ai_feedback($assign, $record, (int) $teacher->id);
        $this->resetDebugging();

        // The grade must be applied to attempt 0.
        $grade = $assign->get_user_grade($student->id, false, 0);
        $this->assertNotEmpty($grade);
        $this->assertEquals(30.0, (float) $grade->grade);
        $this->assertEquals(0, (int) $grade->attemptnumber);
    }

    /**
     * MDL-INT-028: Group submissions should be processed once per group instead of once per member.
     */
    public function test_group_submissions_are_processed_once_per_group(): void {
        $this->markTestSkipped(
            'Pending feature: group submissions are not supported, they are processed per member (MDL-INT-028).'
        );
    }

    /**
     * MDL-INT-029: When marking allocation is enabled, only the allocated marker should be used
     * to attribute the AI grading.
     */
    public function test_marking_allocation_is_respected(): void {
        $this->markTestSkipped('Pending feature: marking allocation is not respected (MDL-INT-029).');
    }

    /**
     * MDL-INT-030: Students should receive a notification when an AI grade is published for them.
     *
     * The plugin calls {@see \assign::notify_grade_modified()} after a successful grade push
     * when `sendstudentnotifications` is enabled. The notification is queued and delivered by
     * Moodle's cron.
     *
     * @covers \local_assign_ai\grading\feedback_applier::apply_ai_feedback
     */
    public function test_students_are_notified_when_ai_grade_is_published(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Flush any pending assign notifications from previous test data.
        \core\cron::setup_user();
        \assign::cron();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(5030, $course->id);

        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignfeedback_comments_enabled' => 1,
            'sendstudentnotifications' => 1,
        ]);

        $this->add_submission($student, $assign, 'My essay text');

        $record = $this->create_pending_record($assign, $student, [
            'grade' => 85,
            'message' => 'Good work!',
        ]);

        $this->redirectMessages();
        feedback_applier::apply_ai_feedback($assign, $record, (int) $teacher->id);
        $this->resetDebugging();

        // The grade must be applied.
        $grade = $assign->get_user_grade($student->id, false);
        $this->assertNotEmpty($grade);
        $this->assertEquals(85.0, (float) $grade->grade);

        // Run cron to deliver the queued notification.
        $this->expectOutputRegex('/Done processing 1 assignment submissions/');
        \core\cron::setup_user();
        $sink = $this->redirectMessages();
        \assign::cron();
        $messages = $sink->get_messages();

        $this->assertCount(1, $messages);
        $this->assertEquals($student->id, $messages[0]->useridto);
    }

    /**
     * MDL-INT-031: The additional files configured on the assignment should be sent to the AI
     * service together with the submission content.
     */
    public function test_assignment_additional_files_are_sent_to_ai_service(): void {
        $this->markTestSkipped(
            'Pending feature: assignment additional files are not sent to the AI service (MDL-INT-031).'
        );
    }
}
