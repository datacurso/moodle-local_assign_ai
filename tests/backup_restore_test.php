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
 * Tests for the course backup and restore of the plugin data.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Unit tests for the backup and restore of the plugin tables (MDL-INT-021).
 *
 * @covers \backup_local_assign_ai_plugin
 * @covers \restore_local_assign_ai_plugin
 * @group local_assign_ai
 */
final class backup_restore_test extends \advanced_testcase {
    use \mod_assign_test_generator;

    /**
     * Load the backup and restore engine libraries.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        parent::setUpBeforeClass();
    }

    /**
     * Bump the assign instance id sequence past the given id.
     *
     * assignment_config keeps a per-process static cache keyed by assignment id while the PHPUnit
     * database reset reuses ids across tests, so each test claims a process-unique id range to
     * guarantee it operates on an assignment id no other test has cached.
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
     * Insert a pending AI record for the given student, as stored by the plugin pipeline.
     *
     * @param \assign $assign The assignment instance.
     * @param \stdClass $student The student the AI feedback belongs to.
     * @param array $overrides Column overrides (grade, message, submissionid, ...).
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
     * Back a course up and restore it into a brand new course.
     *
     * @param \stdClass $course The course to back up.
     * @param bool $userdata Whether user data is included in the backup and the restore.
     * @return int The id of the newly restored course.
     */
    private function backup_and_restore(\stdClass $course, bool $userdata): int {
        global $CFG, $USER;

        // Turn off file logging, otherwise it can't delete the file (Windows).
        $CFG->backup_file_logger_level = \backup::LOG_NONE;

        // MODE_IMPORT keeps the backup as a directory, no zipping needed.
        $bc = new \backup_controller(\backup::TYPE_1COURSE, $course->id,
            \backup::FORMAT_MOODLE, \backup::INTERACTIVE_NO, \backup::MODE_IMPORT, $USER->id);
        $bc->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $bc->get_plan()->get_setting('users')->set_value($userdata);
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $newcourseid = \restore_dbops::create_new_course(
            $course->fullname, $course->shortname . '_r', $course->category
        );
        $rc = new \restore_controller($backupid, $newcourseid,
            \backup::INTERACTIVE_NO, \backup::MODE_GENERAL, $USER->id, \backup::TARGET_NEW_COURSE);
        $rc->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $rc->get_plan()->get_setting('users')->set_value($userdata);

        $this->assertTrue($rc->execute_precheck());

        // Swallow any output echoed by third-party restore plugins installed on the site.
        ob_start();
        try {
            $rc->execute_plan();
        } finally {
            ob_end_clean();
        }
        $rc->destroy();

        return $newcourseid;
    }

    /**
     * Return the single assign course module of the given course.
     *
     * @param int $courseid The course id.
     * @return \cm_info The assign course module.
     */
    private function get_single_assign_cm(int $courseid): \cm_info {
        $cms = get_fast_modinfo($courseid)->get_instances_of('assign');
        $this->assertCount(1, $cms);

        return reset($cms);
    }

    /**
     * MDL-INT-021: A course backup restored into a new course recreates the AI configuration for
     * the restored assignment and the AI feedback records pointing at the new course and course
     * module, preserving attempt number, status and message while regenerating the approval token.
     */
    public function test_course_restore_recreates_config_and_pending_records(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(5010, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);
        $DB->set_field('local_assign_ai_config', 'autograde', 1,
            ['assignmentid' => $assign->get_instance()->id]);
        $DB->set_field('local_assign_ai_config', 'prompt', 'Custom grading prompt',
            ['assignmentid' => $assign->get_instance()->id]);

        // A submitted attempt whose id the restored record must be remapped to.
        $this->add_submission($student, $assign, 'My essay text');
        $submission = $assign->get_user_submission($student->id, false);

        // The submission helper leaves the student logged in; the backup runs as admin.
        $this->setAdminUser();

        $original = $this->create_pending_record($assign, $student, [
            'submissionid' => (int) $submission->id,
            'attemptnumber' => 0,
            'message' => 'Original AI feedback',
            'grade' => 7,
            'status' => assign_submission::STATUS_PENDING,
        ]);

        // User data must travel with the backup so the submission attempt can be remapped.
        $newcourseid = $this->backup_and_restore($course, true);
        $newcm = $this->get_single_assign_cm($newcourseid);

        // The AI configuration is recreated for the restored assignment instance.
        $newconfig = $DB->get_record('local_assign_ai_config',
            ['assignmentid' => $newcm->instance], '*', MUST_EXIST);
        $this->assertEquals(1, $newconfig->autograde);
        $this->assertSame('Custom grading prompt', $newconfig->prompt);

        // The AI feedback record is recreated pointing at the new course and course module.
        $restored = $DB->get_record('local_assign_ai_pending', ['courseid' => $newcourseid], '*', MUST_EXIST);
        $this->assertEquals($newcm->id, $restored->assignmentid);
        $this->assertEquals($student->id, $restored->userid);
        $this->assertEquals($original->attemptnumber, $restored->attemptnumber);
        $this->assertSame($original->status, $restored->status);
        $this->assertSame($original->message, $restored->message);
        $this->assertEquals($original->grade, $restored->grade);

        // The submission attempt id is remapped to the restored attempt row.
        $newsubmissionid = $DB->get_field('assign_submission', 'id',
            ['assignment' => $newcm->instance, 'userid' => $student->id], MUST_EXIST);
        $this->assertEquals($newsubmissionid, $restored->submissionid);

        // The approval token is regenerated so it never collides with the original one.
        $this->assertNotSame($original->approval_token, $restored->approval_token);

        // The original record is left untouched.
        $untouched = $DB->get_record('local_assign_ai_pending', ['id' => $original->id], '*', MUST_EXIST);
        $this->assertSame($original->approval_token, $untouched->approval_token);
    }

    /**
     * MDL-INT-021: Duplicating the assignment within the same course preserves the configured
     * grader, who is still a valid participant of the course.
     */
    public function test_same_course_duplication_preserves_grader(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->bump_assign_sequence(5030, $course->id);
        $assign = $this->create_instance($course, ['assignsubmission_onlinetext_enabled' => 1]);
        $DB->set_field('local_assign_ai_config', 'autograde', 1,
            ['assignmentid' => $assign->get_instance()->id]);
        $DB->set_field('local_assign_ai_config', 'graderid', $teacher->id,
            ['assignmentid' => $assign->get_instance()->id]);

        $cm = get_fast_modinfo($course)->get_cm($assign->get_course_module()->id);
        $newcm = duplicate_module($course, $cm);

        $newconfig = $DB->get_record('local_assign_ai_config',
            ['assignmentid' => $newcm->instance], '*', MUST_EXIST);
        $this->assertEquals(1, $newconfig->autograde);
        $this->assertEquals($teacher->id, $newconfig->graderid);
    }

    /**
     * MDL-INT-021: Restoring the assignment into a DIFFERENT course clears the configured grader
     * (participants are not part of the copy), while the rest of the configuration is kept.
     */
    public function test_cross_course_restore_clears_grader(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->bump_assign_sequence(5050, $course->id);
        $assign = $this->create_instance($course, ['assignsubmission_onlinetext_enabled' => 1]);
        $DB->set_field('local_assign_ai_config', 'autograde', 1,
            ['assignmentid' => $assign->get_instance()->id]);
        $DB->set_field('local_assign_ai_config', 'graderid', $teacher->id,
            ['assignmentid' => $assign->get_instance()->id]);

        $newcourseid = $this->backup_and_restore($course, false);
        $newcm = $this->get_single_assign_cm($newcourseid);

        $newconfig = $DB->get_record('local_assign_ai_config',
            ['assignmentid' => $newcm->instance], '*', MUST_EXIST);
        $this->assertEquals(1, $newconfig->autograde);
        $this->assertNull($newconfig->graderid);
    }

    /**
     * MDL-INT-021: The assessment guide response stored with an AI record should survive a
     * course backup and restore like the rubric response does.
     */
    public function test_assessment_guide_response_survives_restore(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(5070, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);

        // A JSON payload that mimics a real assessment guide AI response.
        $guideresponse = json_encode([
            'criteria' => [
                ['name' => 'Coherencia', 'score' => 8, 'feedback' => 'Buen argumento'],
                ['name' => 'Ortografía', 'score' => 9, 'feedback' => 'Sin errores'],
            ],
        ]);

        $original = $this->create_pending_record($assign, $student, [
            'message' => 'Guide AI feedback',
            'grade' => 8,
            'status' => assign_submission::STATUS_PENDING,
            'assessment_guide_response' => $guideresponse,
        ]);

        $newcourseid = $this->backup_and_restore($course, true);
        $newcm = $this->get_single_assign_cm($newcourseid);

        // The assessment guide response must survive the backup/restore cycle intact.
        $restored = $DB->get_record('local_assign_ai_pending', ['courseid' => $newcourseid], '*', MUST_EXIST);
        $this->assertEquals($newcm->id, $restored->assignmentid);
        $this->assertSame($original->assessment_guide_response, $restored->assessment_guide_response);
    }
}
