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
 * Tests for the course module deletion observer.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai\observer;

use local_assign_ai\assign_submission;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Ensures deleting one assignment only clears its own AI data, not the whole plugin queue.
 *
 * @coversDefaultClass \local_assign_ai\observer\module
 * @group local_assign_ai
 */
final class module_test extends \advanced_testcase {
    /**
     * Inserts a queue row for a cmid.
     *
     * @param int $userid User id.
     * @param int $cmid Course module id.
     * @return int
     */
    private function create_queue(int $userid, int $cmid): int {
        global $DB;
        return $DB->insert_record('local_assign_ai_queue', (object) [
            'type' => 'submission',
            'payload' => json_encode(['userid' => $userid, 'cmid' => $cmid, 'submissiontime' => time()]),
            'timecreated' => time(),
            'timetoprocess' => time(),
            'processed' => 0,
        ]);
    }

    /**
     * Inserts a pending row for a cmid.
     *
     * @param int $courseid Course id.
     * @param int $cmid Course module id.
     * @param int $userid User id.
     * @return int
     */
    private function create_pending(int $courseid, int $cmid, int $userid): int {
        global $DB;
        return $DB->insert_record('local_assign_ai_pending', (object) [
            'courseid' => $courseid,
            'assignmentid' => $cmid,
            'title' => 'T',
            'userid' => $userid,
            'submissionid' => 0,
            'attemptnumber' => 0,
            'submissionmodified' => 0,
            'edited' => 0,
            'message' => null,
            'grade' => null,
            'rubric_response' => null,
            'assessment_guide_response' => null,
            'errormessage' => null,
            'status' => assign_submission::STATUS_PENDING,
            'approval_token' => md5(uniqid('t', true)),
            'usermodified' => null,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Ensures a config row exists for an assign instance (auto-created or inserted).
     *
     * @param int $instanceid Assign instance id.
     * @return void
     */
    private function ensure_config(int $instanceid): void {
        global $DB;
        if ($DB->record_exists('local_assign_ai_config', ['assignmentid' => $instanceid])) {
            return;
        }
        $DB->insert_record('local_assign_ai_config', (object) [
            'assignmentid' => $instanceid,
            'enableai' => 1,
            'autograde' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Deleting activity A must not wipe activity B's queued work.
     *
     * @covers ::course_module_deleted
     */
    public function test_delete_module_only_clears_its_own_queue(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instancea = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $instanceb = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        [, $cma] = get_course_and_cm_from_instance($instancea->id, 'assign');
        [, $cmb] = get_course_and_cm_from_instance($instanceb->id, 'assign');

        $queuea = $this->create_queue($student->id, (int) $cma->id);
        $queueb = $this->create_queue($student->id, (int) $cmb->id);
        $this->create_pending($course->id, (int) $cma->id, $student->id);
        $pendingb = $this->create_pending($course->id, (int) $cmb->id, $student->id);
        // Creating the assign module auto-creates its plugin config row; ensure both exist.
        $this->ensure_config($instancea->id);
        $this->ensure_config($instanceb->id);

        // Invoke the observer with the module-deleted event for activity A. Calling the
        // callback directly (instead of course_delete_module) keeps the test focused on
        // this plugin's logic and free of core's deletion plumbing, which behaves
        // differently across databases.
        $event = \core\event\course_module_deleted::create([
            'courseid' => $course->id,
            'context' => \context_module::instance($cma->id),
            'objectid' => (int) $cma->id,
            'other' => [
                'modulename' => 'assign',
                'instanceid' => (int) $instancea->id,
            ],
        ]);
        module::course_module_deleted($event);

        // Activity A data is gone.
        $this->assertFalse($DB->record_exists('local_assign_ai_queue', ['id' => $queuea]));
        $this->assertFalse($DB->record_exists('local_assign_ai_pending', ['assignmentid' => $cma->id]));
        $this->assertFalse($DB->record_exists('local_assign_ai_config', ['assignmentid' => $instancea->id]));

        // Activity B data survives — this is the regression the fix protects.
        $this->assertTrue($DB->record_exists('local_assign_ai_queue', ['id' => $queueb]));
        $this->assertTrue($DB->record_exists('local_assign_ai_pending', ['id' => $pendingb]));
        $this->assertTrue($DB->record_exists('local_assign_ai_config', ['assignmentid' => $instanceb->id]));
    }
}
