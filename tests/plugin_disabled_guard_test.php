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

namespace local_assign_ai;

use aiprovider_datacurso\local\plugin_settings;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');

/**
 * Tests that Assign AI stops its pages, web services, tasks, hooks and observers when the
 * Datacurso AI provider disables the plugin.
 *
 * @package    local_assign_ai
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_assign_ai
 */
final class plugin_disabled_guard_test extends \advanced_testcase {
    use \mod_assign_test_generator;

    /**
     * Create a course, an assignment, a teacher and a student.
     *
     * @param int $categoryid Course category id, 0 for the default category.
     * @return array [course, cm, teacher, student]
     */
    private function create_environment(int $categoryid = 0): array {
        $this->require_provider();

        $course = $this->getDataGenerator()->create_course($categoryid ? ['category' => $categoryid] : []);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $instance->id);

        return [$course, $cm, $teacher, $student];
    }

    /**
     * Create an AI record for the student.
     *
     * @param \stdClass|\cm_info $cm Assignment course module.
     * @param \stdClass $student Student.
     * @param string $status Record status.
     * @param array $overrides Extra fields.
     * @return \stdClass
     */
    private function create_pending(\stdClass|\cm_info $cm, \stdClass $student, string $status, array $overrides = []): \stdClass {
        /** @var \local_assign_ai_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_assign_ai');
        return $generator->create_pending(array_merge([
            'assignmentid' => $cm->id,
            'userid' => $student->id,
            'status' => $status,
        ], $overrides));
    }

    /**
     * Web service calls of the plugin, each built for the given environment.
     *
     * @return array
     */
    public static function web_service_provider(): array {
        return [
            'get_details' => ['get_details'],
            'update_response' => ['update_response'],
            'change_status' => ['change_status'],
            'process_submission' => ['process_submission'],
            'cancel_review' => ['cancel_review'],
            'approve_all_pending' => ['approve_all_pending'],
            'get_progress' => ['get_progress'],
            'get_token' => ['get_token'],
        ];
    }

    /**
     * Call one web service of the plugin with valid arguments.
     *
     * @param string $name Web service class name.
     * @param \stdClass $course Course.
     * @param \stdClass $cm Course module.
     * @param \stdClass $student Student.
     * @param \stdClass $pending AI record.
     * @return mixed
     */
    private function call_web_service(
        string $name,
        \stdClass $course,
        \stdClass $cm,
        \stdClass $student,
        \stdClass $pending
    ) {
        $class = '\\local_assign_ai\\external\\' . $name;
        switch ($name) {
            case 'get_details':
                return $class::execute($course->id, $cm->id, $student->id);
            case 'update_response':
                return $class::execute($course->id, $cm->id, $student->id, 'Edited feedback');
            case 'change_status':
                return $class::execute($course->id, $cm->id, $student->id, 'approve');
            case 'process_submission':
                return $class::execute($cm->id, $student->id);
            case 'cancel_review':
                return $class::execute($cm->id, $pending->id);
            case 'approve_all_pending':
                return $class::execute($course->id, $cm->id);
            case 'get_progress':
                return $class::execute([$pending->id]);
            case 'get_token':
                return $class::execute($student->id, $cm->id);
        }
        throw new \coding_exception('Unknown web service ' . $name);
    }

    /**
     * A disabled plugin rejects every web service before touching data.
     *
     * @dataProvider web_service_provider
     * @param string $name Web service class name.
     * @return void
     * @covers \aiprovider_datacurso\local\plugin_settings::require_enabled
     */
    public function test_web_service_is_rejected_when_disabled(string $name): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm, $teacher, $student] = $this->create_environment();
        $pending = $this->create_pending($cm, $student, assign_submission::STATUS_PENDING, ['grade' => 70]);
        plugin_settings::set_enabled('local_assign_ai', false);
        $this->setUser($teacher);

        try {
            $this->call_web_service($name, $course, $cm, $student, $pending);
            $this->fail('A disabled plugin must reject the web service ' . $name);
        } catch (\moodle_exception $e) {
            $this->assertSame('plugindisabled', $e->errorcode);
        }

        $after = $DB->get_record('local_assign_ai_pending', ['id' => $pending->id], '*', MUST_EXIST);
        $this->assertEquals($pending, $after, 'The AI record must not change.');
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(task\process_review_submission::class));
    }

    /**
     * An enabled plugin still answers its web services.
     *
     * @return void
     * @covers \local_assign_ai\external\get_progress::execute
     */
    public function test_web_service_works_when_enabled(): void {
        $this->resetAfterTest();
        [$course, $cm, $teacher, $student] = $this->create_environment();
        $pending = $this->create_pending($cm, $student, assign_submission::STATUS_PENDING, ['grade' => 70]);
        plugin_settings::set_enabled('local_assign_ai', true);
        $this->setUser($teacher);

        $result = external\get_progress::execute([$pending->id]);

        $this->assertCount(1, $result);
        $this->assertEquals($pending->id, $result[0]['id']);
    }

    /**
     * The review and history pages stop right after require_login when the plugin is disabled.
     *
     * The pages are scripts, so the order of the calls is checked in their source.
     *
     * @return void
     * @coversNothing
     */
    public function test_pages_require_the_plugin_enabled(): void {
        global $CFG;

        foreach (['review.php', 'history.php'] as $page) {
            $source = file_get_contents($CFG->dirroot . '/local/assign_ai/' . $page);
            $login = strpos($source, 'require_login(');
            $guard = strpos($source, 'provider_dependency::require_enabled(');
            $this->assertNotFalse($guard, $page . ' must call require_enabled.');
            $this->assertGreaterThan($login, $guard, $page . ' must call require_enabled after require_login.');
            $capability = strpos($source, 'has_capability(');
            $this->assertLessThan($capability, $guard, $page . ' must call require_enabled before any other check.');
        }
    }

    /**
     * A disabled plugin does not requeue failed reviews.
     *
     * @return void
     * @covers \local_assign_ai\task\retry_failed_submissions::execute
     */
    public function test_retry_failed_submissions_skips_when_disabled(): void {
        global $DB;

        $this->resetAfterTest();
        [, $cm, , $student] = $this->create_environment();
        $pending = $this->create_pending($cm, $student, assign_submission::STATUS_FAILED);
        plugin_settings::set_enabled('local_assign_ai', false);

        // Each record is checked against the tenant of its student, so the run itself goes on.
        $this->expectOutputRegex('/auto-retried 0 failed AI review\(s\)/');
        (new task\retry_failed_submissions())->execute();

        $after = $DB->get_record('local_assign_ai_pending', ['id' => $pending->id], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_FAILED, $after->status);
        $this->assertEquals(0, $after->retries);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(task\process_review_submission::class));
    }

    /**
     * A disabled plugin deletes the delayed queue row instead of processing it.
     *
     * @return void
     * @covers \local_assign_ai\task\process_ai_queue::execute
     */
    public function test_process_ai_queue_skips_when_disabled(): void {
        global $DB;

        $this->resetAfterTest();
        [, $cm, , $student] = $this->create_environment();
        $itemid = $DB->insert_record('local_assign_ai_queue', (object) [
            'type' => 'submission',
            'payload' => json_encode(['userid' => $student->id, 'cmid' => $cm->id]),
            'timecreated' => time() - 120,
            'timetoprocess' => time() - 60,
            'processed' => 0,
        ]);
        plugin_settings::set_enabled('local_assign_ai', false);

        // The row of a student whose tenant disabled the plugin is dropped without being sent to the AI.
        (new task\process_ai_queue())->execute();

        $this->assertFalse($DB->record_exists('local_assign_ai_queue', ['id' => $itemid]));
        $this->assertSame(0, $DB->count_records('local_assign_ai_pending'));
    }

    /**
     * A disabled plugin does not process a submission queued as an ad-hoc task.
     *
     * @return void
     * @covers \local_assign_ai\task\process_submission_ai::execute
     */
    public function test_process_submission_ai_skips_when_disabled(): void {
        global $DB;

        $this->resetAfterTest();
        [, $cm, , $student] = $this->create_environment();
        plugin_settings::set_enabled('local_assign_ai', false);

        $task = new task\process_submission_ai();
        $task->set_custom_data(['userid' => $student->id, 'cmid' => $cm->id]);

        $this->expectOutputString(get_string('task_skipped_plugindisabled', 'local_assign_ai') . "\n");
        $task->execute();

        $this->assertSame(0, $DB->count_records('local_assign_ai_pending'));
    }

    /**
     * A disabled plugin does not run a review queued by a teacher.
     *
     * @return void
     * @covers \local_assign_ai\task\process_review_submission::execute
     */
    public function test_process_review_submission_skips_when_disabled(): void {
        global $DB;

        $this->resetAfterTest();
        [, $cm, , $student] = $this->create_environment();
        $pending = $this->create_pending($cm, $student, assign_submission::STATUS_QUEUED);
        plugin_settings::set_enabled('local_assign_ai', false);

        $task = new task\process_review_submission();
        $task->set_custom_data(['cmid' => $cm->id, 'userid' => $student->id, 'pendingid' => $pending->id]);

        $this->expectOutputString(get_string('task_skipped_plugindisabled', 'local_assign_ai') . "\n");
        $task->execute();

        $after = $DB->get_record('local_assign_ai_pending', ['id' => $pending->id], '*', MUST_EXIST);
        $this->assertEquals($pending, $after);
    }

    /**
     * A disabled plugin does not send any review of "Review all": the queued record of a student whose tenant
     * disabled the plugin is marked as failed instead of being sent to the AI.
     *
     * @return void
     * @covers \local_assign_ai\task\process_all_submissions::execute
     */
    public function test_process_all_submissions_skips_when_disabled(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm, , $student] = $this->create_environment();
        $pending = $this->create_pending($cm, $student, assign_submission::STATUS_QUEUED);
        plugin_settings::set_enabled('local_assign_ai', false);

        $task = new task\process_all_submissions();
        $task->set_custom_data(['cmid' => $cm->id, 'courseid' => $course->id]);

        $this->expectOutputRegex('/' . preg_quote(get_string('task_skipped_plugindisabled', 'local_assign_ai'), '/') . '/');
        $task->execute();

        $after = $DB->get_record('local_assign_ai_pending', ['id' => $pending->id], '*', MUST_EXIST);
        $this->assertSame(assign_submission::STATUS_FAILED, $after->status);
        $this->assertSame(get_string('reviewaidisabled', 'local_assign_ai'), $after->errormessage);
    }

    /**
     * Moodle Workplace: a user of tenant A, which disabled the plugin, is blocked by every web service even in a
     * course of tenant B, which enabled it. The provider switch follows the tenant of the user, never the course.
     *
     * @dataProvider web_service_provider
     * @param string $name Web service class name.
     * @return void
     * @covers \aiprovider_datacurso\local\plugin_settings::require_enabled
     */
    public function test_user_of_disabled_tenant_is_blocked_in_course_of_enabled_tenant(string $name): void {
        global $DB;

        $this->resetAfterTest();
        [$tenanta, $tenantb] = $this->create_two_tenants();
        [$course, $cm, , $student] = $this->create_environment((int) $tenantb->categoryid);
        $teachera = $this->create_tenant_teacher($course, (int) $tenanta->id);
        $pending = $this->create_pending($cm, $student, assign_submission::STATUS_PENDING, ['grade' => 70]);
        plugin_settings::set_enabled('local_assign_ai', false, (int) $tenanta->id);
        plugin_settings::set_enabled('local_assign_ai', true, (int) $tenantb->id);
        $this->setUser($teachera);

        try {
            $this->call_web_service($name, $course, $cm, $student, $pending);
            $this->fail('A user of a disabled tenant must be rejected by the web service ' . $name);
        } catch (\moodle_exception $e) {
            $this->assertSame('plugindisabled', $e->errorcode);
        }

        $after = $DB->get_record('local_assign_ai_pending', ['id' => $pending->id], '*', MUST_EXIST);
        $this->assertEquals($pending, $after, 'The AI record must not change.');
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(task\process_review_submission::class));
    }

    /**
     * Moodle Workplace: a user of an enabled tenant keeps working in a course of a tenant that disabled the plugin.
     *
     * @return void
     * @covers \local_assign_ai\external\get_progress::execute
     */
    public function test_user_of_enabled_tenant_works_in_course_of_disabled_tenant(): void {
        $this->resetAfterTest();
        [$tenanta, $tenantb] = $this->create_two_tenants();
        [$course, $cm, , $student] = $this->create_environment((int) $tenanta->categoryid);
        $teacherb = $this->create_tenant_teacher($course, (int) $tenantb->id);
        $pending = $this->create_pending($cm, $student, assign_submission::STATUS_PENDING, ['grade' => 70]);
        plugin_settings::set_enabled('local_assign_ai', false, (int) $tenanta->id);
        plugin_settings::set_enabled('local_assign_ai', true, (int) $tenantb->id);
        $this->setUser($teacherb);

        $result = external\get_progress::execute([$pending->id]);

        $this->assertCount(1, $result);
        $this->assertEquals($pending->id, $result[0]['id']);
    }

    /**
     * AMD calls registered on the current page.
     *
     * @return string
     */
    private function page_amd_code(): string {
        global $PAGE;

        $property = new \ReflectionProperty($PAGE->requires, 'amdjscode');
        return implode("\n", $property->getValue($PAGE->requires));
    }

    /**
     * Provider of plugin states.
     *
     * @return array
     */
    public static function state_provider(): array {
        return [
            'enabled' => [true],
            'disabled' => [false],
        ];
    }

    /**
     * The grader footer hook injects the AI script only when the plugin is enabled.
     *
     * @dataProvider state_provider
     * @param bool $enabled Plugin state.
     * @return void
     * @covers \local_assign_ai\hook\output_hook::before_footer_html_generation
     */
    public function test_footer_hook_follows_the_plugin_state(bool $enabled): void {
        global $PAGE;

        $this->require_provider();

        $this->resetAfterTest();
        plugin_settings::set_enabled('local_assign_ai', $enabled);
        $_GET['id'] = 5;
        $_GET['action'] = 'grader';
        $_GET['aitoken'] = 'abc123';

        try {
            $hook = new \core\hook\output\before_footer_html_generation($PAGE->get_renderer('core'));
            hook\output_hook::before_footer_html_generation($hook);
        } finally {
            unset($_GET['id'], $_GET['action'], $_GET['aitoken']);
        }

        if ($enabled) {
            $this->assertStringContainsString('local_assign_ai/inject_ai', $this->page_amd_code());
        } else {
            $this->assertStringNotContainsString('local_assign_ai', $this->page_amd_code());
        }
    }

    /**
     * The grader head hook injects the observer script only when the plugin is enabled.
     *
     * @dataProvider state_provider
     * @param bool $enabled Plugin state.
     * @return void
     * @covers \local_assign_ai\hook\before_standard_head_html_generation::before_standard_html_head
     */
    public function test_head_hook_follows_the_plugin_state(bool $enabled): void {
        global $PAGE;

        $this->require_provider();

        $this->resetAfterTest();
        plugin_settings::set_enabled('local_assign_ai', $enabled);
        $PAGE->set_url(new \moodle_url('/mod/assign/view.php', ['id' => 5, 'action' => 'grader']));

        $hook = new \core\hook\output\before_standard_head_html_generation($PAGE->get_renderer('core'));
        hook\before_standard_head_html_generation::before_standard_html_head($hook);

        if ($enabled) {
            $this->assertStringContainsString('local_assign_ai/observer', $this->page_amd_code());
        } else {
            $this->assertStringNotContainsString('local_assign_ai', $this->page_amd_code());
        }
    }

    /**
     * A disabled plugin does not sync an approved AI record after manual grading.
     *
     * @return void
     * @covers \local_assign_ai\observer\submission::submission_graded
     */
    public function test_submission_graded_does_nothing_when_disabled(): void {
        global $DB;

        $this->require_provider();

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->redirectMessages();
        $this->redirectEmails();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignfeedback_comments_enabled' => 1,
        ]);
        $this->add_submission($student, $assign, 'My essay text');
        $record = $this->create_pending($assign->get_course_module(), $student, assign_submission::STATUS_APPROVED, [
            'grade' => 55,
            'message' => 'Original AI feedback',
        ]);
        plugin_settings::set_enabled('local_assign_ai', false);

        $this->mark_submission($teacher, $assign, $student, 77.0, [
            'assignfeedbackcomments_editor' => [
                'text' => 'Teacher feedback',
                'format' => FORMAT_HTML,
            ],
        ]);

        $after = $DB->get_record('local_assign_ai_pending', ['id' => $record->id], '*', MUST_EXIST);
        $this->assertEquals(55, $after->grade);
        $this->assertSame('Original AI feedback', $after->message);
    }

    /**
     * Create two Workplace tenants, skipping the test without tool_tenant.
     *
     * @return \stdClass[] [tenant A, tenant B]
     */
    private function create_two_tenants(): array {
        $this->require_provider();
        if (!class_exists('\\tool_tenant\\tenancy')) {
            $this->markTestSkipped('Moodle Workplace (tool_tenant) is not installed.');
        }

        $tenants = [];
        foreach (['Guard tenant A', 'Guard tenant B'] as $name) {
            $category = $this->getDataGenerator()->create_category(['name' => $name . ' category']);
            $tenants[] = $this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_tenant([
                'name' => $name,
                'categoryid' => $category->id,
            ]);
        }
        return $tenants;
    }

    /**
     * Create a user of a tenant and enrol them as editing teacher in a course.
     *
     * @param \stdClass $course Course.
     * @param int $tenantid Tenant of the user.
     * @return \stdClass
     */
    private function create_tenant_teacher(\stdClass $course, int $tenantid): \stdClass {
        $teacher = $this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_user(['tenantid' => $tenantid]);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        return $teacher;
    }

    /**
     * Skips the current test when the Datacurso AI provider is not installed.
     *
     * The CI pipeline does not install declared dependencies; without the Datacurso AI provider
     * the assignment creation callbacks and AI entry points reached by these tests cannot run.
     */
    private function require_provider(): void {
        if (!class_exists(\aiprovider_datacurso\local\plugin_settings::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed.');
        }
    }
}
