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
 * Tests for the failure retry policy and grader notification.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai;

use local_assign_ai\task\retry_failed_submissions;

/**
 * Unit tests for failure handling: retry policy and exhausted-retries notification.
 *
 * @coversDefaultClass \local_assign_ai\assign_submission
 * @group local_assign_ai
 */
final class failure_notification_test extends \advanced_testcase {
    /**
     * Creates course, assign, student, teacher, plugin config and a pending record.
     *
     * @param int $retries Value for the record retries counter.
     * @return array [record id, teacher, assign instance id, cmid]
     */
    private function setup_failure_scenario(int $retries): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        [, $cm] = get_course_and_cm_from_instance($instance->id, 'assign');

        // Creating the assign module auto-creates the plugin config row; set the grader on it.
        $config = $DB->get_record('local_assign_ai_config', ['assignmentid' => $instance->id]);
        if ($config) {
            $config->enableai = 1;
            $config->graderid = $teacher->id;
            $DB->update_record('local_assign_ai_config', $config);
        } else {
            $DB->insert_record('local_assign_ai_config', (object) [
                'assignmentid' => $instance->id,
                'enableai' => 1,
                'autograde' => 1,
                'graderid' => $teacher->id,
                'usermodified' => get_admin()->id,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        $recordid = $DB->insert_record('local_assign_ai_pending', (object) [
            'courseid' => $course->id,
            'assignmentid' => $cm->id,
            'title' => $instance->name,
            'userid' => $student->id,
            'submissionid' => 0,
            'attemptnumber' => 0,
            'submissionmodified' => 0,
            'edited' => 0,
            'message' => null,
            'grade' => null,
            'rubric_response' => null,
            'assessment_guide_response' => null,
            'errormessage' => null,
            'retries' => $retries,
            'status' => assign_submission::STATUS_PROCESSING,
            'approval_token' => md5(uniqid('test_', true)),
            'usermodified' => get_admin()->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        return [$recordid, $teacher, $instance->id, $cm->id];
    }

    /**
     * When retries are exhausted, the configured grader receives a notification.
     *
     * @covers ::register_failure
     */
    public function test_grader_notified_when_retries_exhausted(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$recordid, $teacher] = $this->setup_failure_scenario(retry_failed_submissions::MAX_RETRIES);

        $sink = $this->redirectMessages();
        // A curated plugin exception: its localized detail is preserved in the notification.
        $error = new \moodle_exception('error_rubricmismatch', 'local_assign_ai', '', 'Redacción');
        assign_submission::register_failure($error, $recordid);
        $this->resetDebugging();

        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertEquals($teacher->id, $messages[0]->useridto);
        $this->assertSame('gradingfailed', $messages[0]->eventtype);
        $this->assertStringContainsString('Redacción', $messages[0]->fullmessage);
    }

    /**
     * While retries remain, no notification is sent.
     *
     * @covers ::register_failure
     */
    public function test_grader_not_notified_while_retries_remain(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$recordid] = $this->setup_failure_scenario(0);

        $sink = $this->redirectMessages();
        assign_submission::register_failure(new \Exception('boom'), $recordid);
        $this->resetDebugging();

        $this->assertCount(0, $sink->get_messages());
    }

    /**
     * A failed record enters the automatic retry policy.
     *
     * @covers \local_assign_ai\task\retry_failed_submissions::execute
     */
    public function test_failed_record_is_requeued_by_retry_task(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectOutputRegex('/.*/s');

        [$recordid] = $this->setup_failure_scenario(0);
        assign_submission::register_failure(new \Exception('boom'), $recordid);
        $this->resetDebugging();

        (new retry_failed_submissions())->execute();

        $record = $DB->get_record('local_assign_ai_pending', ['id' => $recordid], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_QUEUED, $record->status);
        $this->assertEquals(1, (int) $record->retries);
    }
}
