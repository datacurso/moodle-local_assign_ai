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
 * Privacy provider behaviour tests for local_assign_ai (module-context model).
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai;

use context_module;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use local_assign_ai\privacy\provider;

/**
 * Behavioural tests for the privacy provider under the module-context model (MDL-INT-023).
 *
 * @coversDefaultClass \local_assign_ai\privacy\provider
 * @group local_assign_ai
 */
final class privacy_provider_test extends provider_testcase {
    /** @var \stdClass */
    private $course;
    /** @var \stdClass */
    private $student;
    /** @var \stdClass */
    private $teacher;
    /** @var \cm_info|\stdClass */
    private $cm;
    /** @var \stdClass assign instance */
    private $instance;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->instance = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id]);
        [, $this->cm] = get_course_and_cm_from_instance($this->instance->id, 'assign');
    }

    /**
     * Inserts a pending record for the student in this assignment.
     *
     * @return int
     */
    private function create_pending(): int {
        global $DB;
        return $DB->insert_record('local_assign_ai_pending', (object) [
            'courseid' => $this->course->id,
            'assignmentid' => $this->cm->id,
            'title' => $this->instance->name,
            'userid' => $this->student->id,
            'submissionid' => 0,
            'attemptnumber' => 0,
            'submissionmodified' => 0,
            'edited' => 0,
            'message' => 'AI feedback',
            'grade' => 80,
            'rubric_response' => null,
            'assessment_guide_response' => null,
            'errormessage' => null,
            'status' => assign_submission::STATUS_APPROVED,
            'approval_token' => md5(uniqid('t', true)),
            'usermodified' => $this->teacher->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Inserts a config row with the teacher as grader for this assignment.
     *
     * @return void
     */
    private function create_config(): void {
        global $DB;
        $config = $DB->get_record('local_assign_ai_config', ['assignmentid' => $this->instance->id]);
        if ($config) {
            $config->graderid = $this->teacher->id;
            $config->usermodified = $this->teacher->id;
            $DB->update_record('local_assign_ai_config', $config);
        } else {
            $DB->insert_record('local_assign_ai_config', (object) [
                'assignmentid' => $this->instance->id,
                'enableai' => 1,
                'autograde' => 0,
                'graderid' => $this->teacher->id,
                'usermodified' => $this->teacher->id,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }
    }

    /**
     * Inserts a queue row for the student in this assignment.
     *
     * @return int
     */
    private function create_queue(): int {
        global $DB;
        return $DB->insert_record('local_assign_ai_queue', (object) [
            'type' => 'submission',
            'payload' => json_encode([
                'userid' => $this->student->id,
                'cmid' => (int) $this->cm->id,
                'submissiontime' => time(),
            ]),
            'timecreated' => time(),
            'timetoprocess' => time(),
            'processed' => 0,
        ]);
    }

    /**
     * The student's pending data resolves to the assignment module context.
     *
     * @covers ::get_contexts_for_userid
     */
    public function test_get_contexts_for_student(): void {
        $this->create_pending();
        $modulecontext = context_module::instance($this->cm->id);

        $contextlist = provider::get_contexts_for_userid($this->student->id);

        $this->assertEqualsCanonicalizing(
            [$modulecontext->id],
            $contextlist->get_contextids()
        );
    }

    /**
     * The grader recorded in config resolves to the assignment module context.
     *
     * @covers ::get_contexts_for_userid
     */
    public function test_get_contexts_for_grader(): void {
        $this->create_config();
        $modulecontext = context_module::instance($this->cm->id);

        $contextlist = provider::get_contexts_for_userid($this->teacher->id);

        $this->assertContains((int) $modulecontext->id, array_map('intval', $contextlist->get_contextids()));
    }

    /**
     * The module context lists both the student (pending) and the grader (config).
     *
     * @covers ::get_users_in_context
     */
    public function test_get_users_in_context(): void {
        $this->create_pending();
        $this->create_config();
        $modulecontext = context_module::instance($this->cm->id);

        $userlist = new userlist($modulecontext, 'local_assign_ai');
        provider::get_users_in_context($userlist);

        $ids = $userlist->get_userids();
        $this->assertContains((int) $this->student->id, array_map('intval', $ids));
        $this->assertContains((int) $this->teacher->id, array_map('intval', $ids));
    }

    /**
     * Exporting the student's data in the module context yields the pending record.
     *
     * @covers ::export_user_data
     */
    public function test_export_user_data(): void {
        $this->create_pending();
        $modulecontext = context_module::instance($this->cm->id);

        $this->export_context_data_for_user($this->student->id, $modulecontext, 'local_assign_ai');

        $writer = writer::with_context($modulecontext);
        $this->assertTrue($writer->has_any_data());
    }

    /**
     * Deleting all data in a module context clears pending, config and queue for that cmid only.
     *
     * @covers ::delete_data_for_all_users_in_context
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $this->create_pending();
        $this->create_config();
        $this->create_queue();

        // A second, unrelated assignment that must remain untouched.
        $other = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id]);
        [, $othercm] = get_course_and_cm_from_instance($other->id, 'assign');
        $DB->insert_record('local_assign_ai_pending', (object) [
            'courseid' => $this->course->id,
            'assignmentid' => $othercm->id,
            'title' => $other->name,
            'userid' => $this->student->id,
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
            'approval_token' => md5(uniqid('o', true)),
            'usermodified' => null,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        provider::delete_data_for_all_users_in_context(context_module::instance($this->cm->id));

        $this->assertEquals(0, $DB->count_records('local_assign_ai_pending', ['assignmentid' => $this->cm->id]));
        $this->assertEquals(0, $DB->count_records('local_assign_ai_config', ['assignmentid' => $this->instance->id]));
        $this->assertEquals(0, $DB->count_records('local_assign_ai_queue'));
        // The other activity's record survives.
        $this->assertEquals(1, $DB->count_records('local_assign_ai_pending', ['assignmentid' => $othercm->id]));
    }

    /**
     * Deleting a student removes their pending rows; a grader is anonymised in config.
     *
     * @covers ::delete_data_for_user
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        $pendingid = $this->create_pending();
        $this->create_config();
        $modulecontext = context_module::instance($this->cm->id);

        // Delete the student.
        $studentlist = new approved_contextlist($this->student, 'local_assign_ai', [$modulecontext->id]);
        provider::delete_data_for_user($studentlist);
        $this->assertFalse($DB->record_exists('local_assign_ai_pending', ['id' => $pendingid]));

        // Delete the teacher: config grader reference is nulled, config row kept.
        $teacherlist = new approved_contextlist($this->teacher, 'local_assign_ai', [$modulecontext->id]);
        provider::delete_data_for_user($teacherlist);
        $config = $DB->get_record('local_assign_ai_config', ['assignmentid' => $this->instance->id]);
        $this->assertNotEmpty($config);
        $this->assertNull($config->graderid);
    }

    /**
     * Deleting a set of users in a module context clears their student data.
     *
     * @covers ::delete_data_for_users
     */
    public function test_delete_data_for_users(): void {
        global $DB;
        $pendingid = $this->create_pending();
        $modulecontext = context_module::instance($this->cm->id);

        $approved = new approved_userlist($modulecontext, 'local_assign_ai', [$this->student->id]);
        provider::delete_data_for_users($approved);

        $this->assertFalse($DB->record_exists('local_assign_ai_pending', ['id' => $pendingid]));
    }
}
