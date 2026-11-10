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

namespace local_assign_ai;

use local_assign_ai\local\provider_dependency;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/assign_ai/lib.php');

/**
 * Tests that Assign AI keeps the site working when the Datacurso AI provider is older than 1.7.0.
 *
 * The old provider is simulated through the guard's test seam: the plugin is enabled in the
 * installed provider, so every entry point that still reached the provider directly would
 * behave as enabled and fail these tests.
 *
 * @package    local_assign_ai
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_assign_ai
 */
final class provider_missing_guard_test extends \advanced_testcase {
    use \mod_assign_test_generator;

    /**
     * Skips the test when the Datacurso AI provider is not installed.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists(\aiprovider_datacurso\local\plugin_settings::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed.');
        }
    }

    /**
     * Restores the real availability after each test.
     *
     * @return void
     */
    protected function tearDown(): void {
        provider_dependency::override_availability_for_testing(null);
        unset($_GET['id'], $_GET['action'], $_GET['aitoken']);
        parent::tearDown();
    }

    /**
     * Enable the plugin in the provider, then simulate a provider older than 1.7.0.
     *
     * @return void
     */
    private function simulate_old_provider(): void {
        \aiprovider_datacurso\local\plugin_settings::set_enabled('local_assign_ai', true);
        provider_dependency::override_availability_for_testing(false);
    }

    /**
     * Create a course, an assignment, a teacher and a student.
     *
     * @return array [course, cm, teacher, student]
     */
    private function create_environment(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $instance->id);
        return [$course, $cm, $teacher, $student];
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
     * A web service stops with the controlled update message.
     *
     * @return void
     * @covers \local_assign_ai\external\get_progress::execute
     */
    public function test_web_service_stops_with_the_update_message(): void {
        $this->resetAfterTest();
        [, $cm, $teacher, $student] = $this->create_environment();
        /** @var \local_assign_ai_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_assign_ai');
        $pending = $generator->create_pending([
            'assignmentid' => $cm->id,
            'userid' => $student->id,
            'status' => assign_submission::STATUS_PENDING,
        ]);
        $this->simulate_old_provider();
        $this->setUser($teacher);

        try {
            external\get_progress::execute([$pending->id]);
            $this->fail('An old provider must stop the web service.');
        } catch (\moodle_exception $e) {
            $this->assertSame('providerupdaterequired', $e->errorcode);
        }
    }

    /**
     * A scheduled task skips its run and logs why.
     *
     * @return void
     * @covers \local_assign_ai\task\process_ai_queue::execute
     */
    public function test_task_skips_and_logs_the_update_message(): void {
        $this->resetAfterTest();
        $this->simulate_old_provider();

        $this->expectOutputString(get_string('task_skipped_providerupdaterequired', 'local_assign_ai') . "\n");
        (new task\process_ai_queue())->execute();
    }

    /**
     * The grader page hooks inject nothing and raise nothing.
     *
     * @return void
     * @covers \local_assign_ai\hook\output_hook::before_footer_html_generation
     * @covers \local_assign_ai\hook\before_standard_head_html_generation::before_standard_html_head
     */
    public function test_page_hooks_do_nothing(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->simulate_old_provider();
        $_GET['id'] = 5;
        $_GET['action'] = 'grader';
        $_GET['aitoken'] = 'abc123';
        $PAGE->set_url(new \moodle_url('/mod/assign/view.php', ['id' => 5, 'action' => 'grader']));

        hook\output_hook::before_footer_html_generation(
            new \core\hook\output\before_footer_html_generation($PAGE->get_renderer('core'))
        );
        hook\before_standard_head_html_generation::before_standard_html_head(
            new \core\hook\output\before_standard_head_html_generation($PAGE->get_renderer('core'))
        );

        $this->assertStringNotContainsString('local_assign_ai', $this->page_amd_code());
    }

    /**
     * The course module callback leaves the assignment configuration untouched.
     *
     * @return void
     * @covers ::local_assign_ai_coursemodule_edit_post_actions
     */
    public function test_course_module_callback_does_nothing(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm] = $this->create_environment();
        $DB->delete_records('local_assign_ai_config', ['assignmentid' => $cm->instance]);
        $this->simulate_old_provider();

        $data = (object) ['modulename' => 'assign', 'instance' => $cm->instance, 'local_assign_ai_enableai' => 1];
        $result = local_assign_ai_coursemodule_edit_post_actions($data, $course);

        $this->assertSame($data, $result);
        $this->assertFalse($DB->record_exists('local_assign_ai_config', ['assignmentid' => $cm->instance]));
    }

    /**
     * Autograde reports disabled, so the submission observer does not start an AI review.
     *
     * @return void
     * @covers \local_assign_ai\config\assignment_config::is_autograde_enabled
     * @covers \local_assign_ai\observer\submission::submission_created
     */
    public function test_submission_observer_does_nothing(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);
        set_config('defaultautograde', 1, 'local_assign_ai');
        $this->simulate_old_provider();

        $this->assertFalse(config\assignment_config::is_autograde_enabled($assign));
        $this->add_submission($student, $assign, 'My essay text');

        $this->assertSame(0, $DB->count_records('local_assign_ai_pending'));
        $this->assertSame(0, $DB->count_records('local_assign_ai_queue'));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(task\process_submission_ai::class));
    }

    /**
     * The plugin settings page tells the administrator to update the provider.
     *
     * @return void
     * @coversNothing
     */
    public function test_settings_page_warns_the_admin(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $page = admin_get_root(true, true)->locate('local_assign_ai_settings');
        $this->assertFalse(property_exists($page->settings, 'local_assign_aiproviderupdaterequired'));

        $this->simulate_old_provider();
        $page = admin_get_root(true, true)->locate('local_assign_ai_settings');
        $this->assertTrue(property_exists($page->settings, 'local_assign_aiproviderupdaterequired'));
        $this->assertStringContainsString(
            get_string('providerupdaterequired_admin', 'local_assign_ai'),
            $page->settings->local_assign_aiproviderupdaterequired->description
        );
    }

    /**
     * The review and history pages stop through the guard right after require_login.
     *
     * The pages are scripts, so the order of the calls is checked in their source.
     *
     * @return void
     * @coversNothing
     */
    public function test_pages_stop_through_the_guard(): void {
        global $CFG;

        foreach (['review.php', 'history.php'] as $page) {
            $source = file_get_contents($CFG->dirroot . '/local/assign_ai/' . $page);
            $login = strpos($source, 'require_login(');
            $guard = strpos($source, 'provider_dependency::require_enabled();');
            $this->assertNotFalse($guard, $page . ' must call provider_dependency::require_enabled().');
            $this->assertGreaterThan($login, $guard, $page . ' must check the provider after require_login.');
        }
    }
}
