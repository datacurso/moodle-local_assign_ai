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

use local_assign_ai\assign_submission;
use local_assign_ai\external\approve_all_pending;
use local_assign_ai\external\process_submission;
use local_assign_ai\table\review_table;
use local_assign_ai\task\retry_failed_submissions;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');

/**
 * Only students still active in the activity are reviewed with AI.
 *
 * @package     local_assign_ai
 * @category    test
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \local_assign_ai\local\activity_access
 */
final class active_students_test extends \advanced_testcase {
    use \mod_assign_test_generator;

    /** @var int Number of requests the fake AI client received. */
    private int $requests = 0;

    /**
     * Skip the test when the Datacurso AI provider is missing.
     *
     * The web services and the AI pipeline stop through provider_dependency without the provider,
     * and the CI pipeline does not install declared dependencies.
     *
     * @return void
     */
    private function require_provider(): void {
        if (!class_exists(\aiprovider_datacurso\local\plugin_settings::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed.');
        }
    }

    /**
     * Replace the AI client with a spy that counts requests and answers a fixed grade.
     *
     * @return void
     */
    private function fake_ai_client(): void {
        $this->require_provider();
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the AI pipeline cannot run.');
        }

        $this->requests = 0;
        $spy = $this->getMockBuilder(\aiprovider_datacurso\httpclient\ai_services_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['request'])
            ->getMock();
        $spy->method('request')->willReturnCallback(function (): array {
            $this->requests++;
            return ['reply' => 'Good work', 'grade' => 80];
        });
        $factory = $this->createStub(\local_assign_ai\api\ai_client_factory::class);
        $factory->method('create')->willReturn($spy);
        \core\di::set(\local_assign_ai\api\ai_client_factory::class, $factory);
    }

    /**
     * Create an assignment with Assign AI (manual review) and one submitted student per given name,
     * each with an 'initial' Assign AI record.
     *
     * @param string[] $names Student keys.
     * @return array [course, assign, teacher, students keyed by name]
     */
    private function create_scenario(array $names): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignfeedback_comments_enabled' => 1,
        ]);
        $DB->set_field('local_assign_ai_config', 'autograde', 0, ['assignmentid' => $assign->get_instance()->id]);

        $students = [];
        foreach ($names as $name) {
            $student = $this->getDataGenerator()->create_and_enrol($course, 'student', ['firstname' => $name]);
            $this->add_submission($student, $assign, "Answer of {$name}");
            (new assign_submission((int) $student->id, $assign))->process_submission_ai();
            $students[$name] = $student;
        }
        return [$course, $assign, $teacher, $students];
    }

    /**
     * Unenrol a student from the course manual enrolment.
     *
     * @param \stdClass $course Course.
     * @param \stdClass $student Student.
     * @return void
     */
    private function unenrol(\stdClass $course, \stdClass $student): void {
        global $DB;
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->unenrol_user($instance, $student->id);
    }

    /**
     * Suspend the course enrolment of a student.
     *
     * @param \stdClass $course Course.
     * @param \stdClass $student Student.
     * @return void
     */
    private function suspend_enrolment(\stdClass $course, \stdClass $student): void {
        global $DB;
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->update_user_enrol($instance, $student->id, ENROL_USER_SUSPENDED);
    }

    /**
     * Return the Assign AI record of a student.
     *
     * @param \assign $assign Assignment.
     * @param \stdClass $student Student.
     * @return \stdClass
     */
    private function get_record(\assign $assign, \stdClass $student): \stdClass {
        global $DB;
        return $DB->get_record('local_assign_ai_pending', [
            'assignmentid' => $assign->get_course_module()->id,
            'userid' => $student->id,
        ], '*', MUST_EXIST);
    }

    /**
     * Only active students are reviewable: unenrolled, suspended enrolments and suspended accounts are not.
     *
     * @covers ::is_active_student
     */
    public function test_is_active_student(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $assign, , $students] = $this->create_scenario(['active', 'unenrolled', 'suspendedenrol', 'suspendeduser']);
        $this->unenrol($course, $students['unenrolled']);
        $this->suspend_enrolment($course, $students['suspendedenrol']);
        $DB->set_field('user', 'suspended', 1, ['id' => $students['suspendeduser']->id]);

        $access = activity_access::from_assign($assign);
        $this->assertTrue(activity_access::is_active_student($access, (int) $students['active']->id));
        $this->assertFalse(activity_access::is_active_student($access, (int) $students['unenrolled']->id));
        $this->assertFalse(activity_access::is_active_student($access, (int) $students['suspendedenrol']->id));
        $this->assertFalse(activity_access::is_active_student($access, (int) $students['suspendeduser']->id));
        $this->assertFalse(activity_access::is_active_student($access, -1));
    }

    /**
     * The review table and "Review all" only include active students; inactive ones are never sent to the AI.
     *
     * @covers ::get_reviewable_student_sql
     * @covers \local_assign_ai\table\review_table
     * @covers \local_assign_ai\external\process_submission::execute
     */
    public function test_review_all_skips_inactive_students(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->fake_ai_client();

        [$course, $assign, $teacher, $students] = $this->create_scenario(
            ['active', 'unenrolled', 'suspendedenrol', 'suspendeduser']
        );
        $this->unenrol($course, $students['unenrolled']);
        $this->suspend_enrolment($course, $students['suspendedenrol']);
        $DB->set_field('user', 'suspended', 1, ['id' => $students['suspendeduser']->id]);

        $this->setUser($teacher);
        $table = new review_table('active_students', $assign);
        $table->setup();
        $table->query_db(50, false);
        $listed = array_map(fn($row) => (int) $row->userid, array_values($table->rawdata));
        $this->assertSame([(int) $students['active']->id], $listed);

        $result = process_submission::execute((int) $assign->get_course_module()->id, 0, true, 0);
        $this->assertSame('queued', $result['status']);
        // The task traces its run; only the active student is processed.
        $this->expectOutputRegex('/Total submissions processed: 1/');
        $this->runAdhocTasks('\local_assign_ai\task\process_all_submissions');

        $this->assertSame(1, $this->requests);
        $this->assertSame(assign_submission::STATUS_PENDING, $this->get_record($assign, $students['active'])->status);
        foreach (['unenrolled', 'suspendedenrol', 'suspendeduser'] as $name) {
            $this->assertSame(
                assign_submission::STATUS_INITIAL,
                $this->get_record($assign, $students[$name])->status,
                $name
            );
        }
        $this->resetDebugging();
    }

    /**
     * "Review all" queues nothing when every student with an initial record is inactive.
     *
     * @covers \local_assign_ai\external\process_submission::execute
     */
    public function test_review_all_with_only_inactive_students_queues_nothing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->require_provider();

        [$course, $assign, $teacher, $students] = $this->create_scenario(['gone']);
        $this->unenrol($course, $students['gone']);

        $this->setUser($teacher);
        $result = process_submission::execute((int) $assign->get_course_module()->id, 0, true, 0);
        $this->assertSame('none', $result['status']);
        $this->assertSame(assign_submission::STATUS_INITIAL, $this->get_record($assign, $students['gone'])->status);
    }

    /**
     * Reviewing a single inactive student is refused before anything is queued.
     *
     * @covers ::require_reviewable_student
     * @covers \local_assign_ai\external\process_submission::execute
     */
    public function test_single_review_of_inactive_student_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->require_provider();

        [$course, $assign, $teacher, $students] = $this->create_scenario(['gone']);
        $record = $this->get_record($assign, $students['gone']);
        $this->suspend_enrolment($course, $students['gone']);

        $this->setUser($teacher);
        try {
            process_submission::execute(
                (int) $assign->get_course_module()->id,
                (int) $students['gone']->id,
                false,
                (int) $record->id
            );
            $this->fail('Expected moodle_exception was not thrown');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_student_inactive', $e->errorcode);
        }
        $this->assertSame(assign_submission::STATUS_INITIAL, $this->get_record($assign, $students['gone'])->status);
    }

    /**
     * A review queued before the student left the course fails without calling the AI, and is not retried.
     *
     * @covers \local_assign_ai\assign_submission::process_submission_ai_review
     */
    public function test_queued_review_of_student_who_left_is_not_sent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->fake_ai_client();

        [$course, $assign, $teacher, $students] = $this->create_scenario(['leaver']);
        $record = $this->get_record($assign, $students['leaver']);

        $this->setUser($teacher);
        $cmid = (int) $assign->get_course_module()->id;
        process_submission::execute($cmid, (int) $students['leaver']->id, false, (int) $record->id);
        $this->unenrol($course, $students['leaver']);

        $sink = $this->redirectMessages();
        $this->runAdhocTasks('\local_assign_ai\task\process_review_submission');
        $sink->close();

        $record = $this->get_record($assign, $students['leaver']);
        $this->assertSame(0, $this->requests);
        $this->assertSame(assign_submission::STATUS_FAILED, $record->status);
        $this->assertSame(get_string('error_student_inactive', 'local_assign_ai'), $record->errormessage);
        $this->assertSame(retry_failed_submissions::MAX_RETRIES, (int) $record->retries);
        $this->resetDebugging();
    }

    /**
     * A suspended account fails with the terminal teacher-facing reason instead of a database error.
     *
     * @covers \local_assign_ai\assign_submission::__construct
     */
    public function test_suspended_account_fails_with_inactive_reason(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [, $assign, , $students] = $this->create_scenario(['suspended']);
        $DB->set_field('user', 'suspended', 1, ['id' => $students['suspended']->id]);

        try {
            new assign_submission((int) $students['suspended']->id, $assign);
            $this->fail('Expected moodle_exception was not thrown');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_student_inactive', $e->errorcode);
        }
    }

    /**
     * Approving all pending reviews skips students who left the course.
     *
     * @covers \local_assign_ai\external\approve_all_pending::execute
     */
    public function test_approve_all_skips_inactive_students(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->require_provider();

        [$course, $assign, $teacher, $students] = $this->create_scenario(['active', 'gone']);
        foreach ($students as $student) {
            $DB->update_record('local_assign_ai_pending', (object) [
                'id' => $this->get_record($assign, $student)->id,
                'status' => assign_submission::STATUS_PENDING,
                'grade' => 70,
            ]);
        }
        $this->unenrol($course, $students['gone']);

        $this->setUser($teacher);
        $result = approve_all_pending::execute((int) $course->id, (int) $assign->get_course_module()->id);
        $this->resetDebugging();

        $this->assertSame(1, $result['approved']);
        $this->assertSame(assign_submission::STATUS_APPROVED, $this->get_record($assign, $students['active'])->status);
        $this->assertSame(assign_submission::STATUS_PENDING, $this->get_record($assign, $students['gone'])->status);
    }

    /**
     * The history still lists the reviews of students who left the course.
     *
     * @covers ::get_student_filter_sql
     */
    public function test_history_filter_keeps_inactive_students(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $assign, , $students] = $this->create_scenario(['gone']);
        $this->unenrol($course, $students['gone']);

        $access = activity_access::from_assign($assign);
        [$sql, $params] = activity_access::get_student_filter_sql($access, 'userid');
        $this->assertSame(1, $DB->count_records_select(
            'local_assign_ai_pending',
            "assignmentid = :cmid AND {$sql}",
            ['cmid' => $assign->get_course_module()->id] + $params
        ));
    }
}
