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

/**
 * Tests for the single point that checks the Datacurso AI provider dependency.
 *
 * @package    local_assign_ai
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_assign_ai
 * @covers     \local_assign_ai\local\provider_dependency
 */
final class provider_dependency_test extends \advanced_testcase {
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
        parent::tearDown();
    }

    /**
     * With the installed provider the guard reports it available and follows its state.
     *
     * @return void
     */
    public function test_real_provider_is_available_and_followed(): void {
        $this->resetAfterTest();

        $this->assertTrue(provider_dependency::is_available());

        \aiprovider_datacurso\local\plugin_settings::set_enabled('local_assign_ai', true);
        $this->assertTrue(provider_dependency::is_enabled());
        provider_dependency::require_enabled();

        \aiprovider_datacurso\local\plugin_settings::set_enabled('local_assign_ai', false);
        $this->assertFalse(provider_dependency::is_enabled());
        $this->setAdminUser();
        try {
            provider_dependency::require_enabled();
            $this->fail('A disabled plugin must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('plugindisabled_admin', $e->errorcode);
            $this->assertSame('aiprovider_datacurso', $e->module);
        }
    }

    /**
     * An old provider makes the plugin disabled, even when its saved state is enabled.
     *
     * @return void
     */
    public function test_old_provider_is_not_available_and_not_enabled(): void {
        $this->resetAfterTest();
        \aiprovider_datacurso\local\plugin_settings::set_enabled('local_assign_ai', true);

        provider_dependency::override_availability_for_testing(false);

        $this->assertFalse(provider_dependency::is_available());
        $this->assertFalse(provider_dependency::is_enabled());
    }

    /**
     * A site administrator is told to update the provider.
     *
     * @return void
     */
    public function test_old_provider_tells_the_admin_to_update_it(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        provider_dependency::override_availability_for_testing(false);

        try {
            provider_dependency::require_enabled();
            $this->fail('An old provider must stop the request.');
        } catch (\moodle_exception $e) {
            $this->assertSame('providerupdaterequired_admin', $e->errorcode);
            $this->assertSame('local_assign_ai', $e->module);
            $this->assertStringContainsString('2.2.2', $e->getMessage());
            $this->assertStringNotContainsString('1.7.0', $e->getMessage());
        }
    }

    /**
     * Any other user gets a generic message without technical details.
     *
     * @return void
     */
    public function test_old_provider_gives_other_users_a_generic_message(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        provider_dependency::override_availability_for_testing(false);

        try {
            provider_dependency::require_enabled();
            $this->fail('An old provider must stop the request.');
        } catch (\moodle_exception $e) {
            $this->assertSame('providerupdaterequired', $e->errorcode);
            $this->assertSame('local_assign_ai', $e->module);
            $this->assertStringNotContainsString('2.2.2', $e->getMessage());
        }
    }

    /**
     * Tasks log why they skip their run.
     *
     * @return void
     */
    public function test_task_skip_message_names_the_reason(): void {
        $this->resetAfterTest();
        \aiprovider_datacurso\local\plugin_settings::set_enabled('local_assign_ai', false);
        $this->assertSame(
            get_string('task_skipped_plugindisabled', 'local_assign_ai'),
            provider_dependency::task_skipped_message()
        );

        provider_dependency::override_availability_for_testing(false);
        $this->assertSame(
            get_string('task_skipped_providerupdaterequired', 'local_assign_ai'),
            provider_dependency::task_skipped_message()
        );
    }

    /**
     * The guard reports the provider available only when it has every API the plugin calls, so a
     * provider older than 2.2.2 (no outbound_privacy on the Moodle 5.x line) pauses the plugin
     * instead of failing when a submission is sent to the AI service.
     *
     * @return void
     */
    public function test_guard_requires_the_outbound_privacy_helper(): void {
        $required = (new \ReflectionClass(provider_dependency::class))->getConstant('REQUIRED_API');

        $this->assertIsArray($required);
        $this->assertSame(
            ['is_enabled', 'require_enabled'],
            $required['\\aiprovider_datacurso\\local\\plugin_settings'] ?? null
        );
        $this->assertSame(
            ['pseudonymise_userid_value'],
            $required['\\aiprovider_datacurso\\local\\outbound_privacy'] ?? null
        );
    }

    /**
     * Only the guard calls the provider plugin_settings class.
     *
     * @return void
     */
    public function test_only_the_guard_calls_plugin_settings(): void {
        global $CFG;

        $root = $CFG->dirroot . '/local/assign_ai';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $offenders = [];
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($file->getExtension() !== 'php' || preg_match('#^(tests|private|vendor)/#', $relative)) {
                continue;
            }
            if ($relative === 'classes/local/provider_dependency.php') {
                continue;
            }
            if (str_contains(file_get_contents($file->getPathname()), 'plugin_settings')) {
                $offenders[] = $relative;
            }
        }
        $this->assertSame([], $offenders, 'Only provider_dependency may use the provider plugin_settings class.');
    }
}
