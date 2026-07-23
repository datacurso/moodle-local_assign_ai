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
 * Tests for the backup and restore of AI pending records.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai\backup;

use local_assign_ai\assign_submission;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Full-cycle backup/restore test for local_assign_ai pending records.
 *
 * @coversDefaultClass \backup_local_assign_ai_plugin
 * @group local_assign_ai
 */
final class backup_restore_test extends \advanced_testcase {
    /**
     * Pending records keep rubric AND assessment guide data across backup/restore.
     *
     * @covers ::define_course_plugin_structure
     */
    public function test_pending_record_survives_backup_and_restore(): void {
        global $CFG, $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $CFG->keeptempdirectoriesonbackup = true;
        // Other local plugins may print progress messages during restore.
        $this->expectOutputRegex('/.*/s');

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        [, $cm] = get_course_and_cm_from_instance($instance->id, 'assign');

        $rubricjson = json_encode([
            ['criterion' => 'Clarity', 'levels' => [['points' => 5, 'comment' => 'Well done']]],
        ]);
        $guidejson = json_encode([
            'Criterion A' => ['grade' => 10, 'reply' => ['Good', 'Complete']],
        ]);

        $DB->insert_record('local_assign_ai_pending', (object) [
            'courseid' => $course->id,
            'assignmentid' => $cm->id,
            'title' => $instance->name,
            'userid' => $student->id,
            'submissionid' => 0,
            'attemptnumber' => 0,
            'submissionmodified' => 0,
            'edited' => 0,
            'message' => '<p>AI feedback</p>',
            'grade' => 80,
            'rubric_response' => $rubricjson,
            'assessment_guide_response' => $guidejson,
            'errormessage' => null,
            'status' => assign_submission::STATUS_PENDING,
            'approval_token' => md5(uniqid('test_', true)),
            'usermodified' => $USER->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        // Full course backup.
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        // Restore into a brand new course.
        $newcourseid = \restore_dbops::create_new_course(
            'Restored course',
            'RESTORED' . $course->id,
            $course->category
        );
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $restored = $DB->get_record(
            'local_assign_ai_pending',
            ['courseid' => $newcourseid],
            '*',
            MUST_EXIST
        );

        $this->assertSame($guidejson, $restored->assessment_guide_response);
        $this->assertSame($rubricjson, $restored->rubric_response);
        $this->assertSame('<p>AI feedback</p>', $restored->message);
        $this->assertEquals(80, (int) $restored->grade);
        $this->assertSame(assign_submission::STATUS_PENDING, $restored->status);
        // The restored token must be regenerated with the strong 64-char generator.
        $this->assertSame(64, strlen($restored->approval_token));
    }
}
