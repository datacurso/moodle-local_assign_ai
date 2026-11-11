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
            $this->assertStringContainsString('1.7.0', $e->getMessage());
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
            $this->assertStringNotContainsString('1.7.0', $e->getMessage());
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
     * Moodle Workplace: the guard checks the tenant it is given, not only the tenant of the current user.
     *
     * Tasks and observers run as an administrator or as another user, so they pass the tenant of the
     * student they act for; the switch of one tenant never decides for another.
     *
     * @return void
     */
    public function test_is_enabled_follows_the_given_tenant(): void {
        $this->require_tenancy();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator()->get_plugin_generator('tool_tenant');
        $tenanta = (int) $generator->create_tenant(['name' => 'Guard tenant A'])->id;
        $tenantb = (int) $generator->create_tenant(['name' => 'Guard tenant B'])->id;
        \aiprovider_datacurso\local\plugin_settings::set_enabled('local_assign_ai', false, $tenanta);
        \aiprovider_datacurso\local\plugin_settings::set_enabled('local_assign_ai', true, $tenantb);
        $this->setAdminUser();

        $this->assertFalse(provider_dependency::is_enabled($tenanta));
        $this->assertTrue(provider_dependency::is_enabled($tenantb));

        provider_dependency::override_availability_for_testing(false);
        $this->assertFalse(provider_dependency::is_enabled($tenantb), 'An old provider disables every tenant.');
    }

    /**
     * Moodle Workplace: require_enabled() stops a request only for the tenant that disabled the plugin.
     *
     * @return void
     */
    public function test_require_enabled_follows_the_given_tenant(): void {
        $this->require_tenancy();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator()->get_plugin_generator('tool_tenant');
        $tenanta = (int) $generator->create_tenant(['name' => 'Guard tenant A'])->id;
        $tenantb = (int) $generator->create_tenant(['name' => 'Guard tenant B'])->id;
        \aiprovider_datacurso\local\plugin_settings::set_enabled('local_assign_ai', false, $tenanta);
        \aiprovider_datacurso\local\plugin_settings::set_enabled('local_assign_ai', true, $tenantb);
        $this->setAdminUser();

        provider_dependency::require_enabled($tenantb);
        try {
            provider_dependency::require_enabled($tenanta);
            $this->fail('A tenant that disabled the plugin must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('plugindisabled_admin', $e->errorcode);
        }
    }

    /**
     * Only the guard calls the provider class introduced in 1.7.0.
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
            // The Workplace upgrade step copies the former switch into the provider and checks the class itself.
            if (in_array($relative, ['classes/local/provider_dependency.php', 'db/upgradelib.php'], true)) {
                continue;
            }
            if (str_contains(file_get_contents($file->getPathname()), 'plugin_settings')) {
                $offenders[] = $relative;
            }
        }
        $this->assertSame([], $offenders, 'Only provider_dependency may use the provider plugin_settings class.');
    }

    /**
     * Skips the test when Moodle Workplace (tool_tenant) is not installed.
     *
     * @return void
     */
    private function require_tenancy(): void {
        if (!class_exists('\\tool_tenant\\tenancy')) {
            $this->markTestSkipped('Moodle Workplace (tool_tenant) is not installed.');
        }
    }
}
