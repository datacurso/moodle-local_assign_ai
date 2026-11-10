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
use local_assign_ai\config\assignment_config;

/**
 * Tests that the state of Assign AI is kept by the Datacurso AI provider, per tenant.
 *
 * The plugin has no switch of its own any more, neither at site nor at tenant level: the provider stores whether
 * the plugin is enabled for each tenant. The former values are copied into the provider and deleted on upgrade.
 *
 * @package    local_assign_ai
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_assign_ai\config\assignment_config
 */
final class provider_plugin_state_test extends \advanced_testcase {
    /**
     * Load the admin and upgrade libraries the tests use.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        global $CFG;

        parent::setUpBeforeClass();
        require_once($CFG->libdir . '/adminlib.php');
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/assign_ai/db/upgrade.php');
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
    }

    /**
     * Create an assignment whose effective configuration enables AI and auto-grading.
     *
     * @param int $categoryid Course category id, 0 for the default category.
     * @return \assign
     */
    private function create_autograded_assign(int $categoryid = 0): \assign {
        global $DB;

        $this->require_provider();

        set_config('defaultenableai', 1, 'local_assign_ai');
        set_config('defaultautograde', 1, 'local_assign_ai');

        $course = $this->getDataGenerator()->create_course($categoryid ? ['category' => $categoryid] : []);
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $instance->id, $course->id, false, MUST_EXIST);

        // Under Workplace tenancy the site defaults are not read, so the assignment row is switched on directly.
        $values = ['enableai' => 1, 'autograde' => 1];
        $config = $DB->get_record('local_assign_ai_config', ['assignmentid' => $instance->id]);
        if ($config) {
            $DB->update_record('local_assign_ai_config', (object) (['id' => $config->id] + $values));
        } else {
            $DB->insert_record('local_assign_ai_config', (object) ($values + [
                'assignmentid' => $instance->id,
                'timecreated' => time(),
                'timemodified' => time(),
            ]));
        }

        return new \assign(\context_module::instance($cm->id), $cm, $course);
    }

    /**
     * Stores a value of the former enableassignai switch for a tenant, as the previous release did.
     *
     * @param int $tenantid Tenant id.
     * @param string $value Stored value.
     * @return void
     */
    private function store_former_tenant_switch(int $tenantid, string $value): void {
        global $DB;

        $DB->insert_record('local_assign_ai_tenant_config', (object) [
            'tenantid' => $tenantid,
            'name' => 'enableassignai',
            'value' => $value,
            'timemodified' => time(),
        ]);
    }

    /**
     * Runs the upgrade step that moves the switch into the provider.
     *
     * @return void
     */
    private function run_upgrade(): void {
        set_config('version', 2026110901, 'local_assign_ai');
        xmldb_local_assign_ai_upgrade(2026110901);
    }

    /**
     * S6: the feature follows the state saved in the provider.
     *
     * @return void
     */
    public function test_feature_follows_the_provider_state(): void {
        $this->resetAfterTest();
        $assign = $this->create_autograded_assign();

        unset_config(plugin_settings::CONFIG_PREFIX . 'local_assign_ai', 'aiprovider_datacurso');
        $this->assertTrue(assignment_config::is_autograde_enabled($assign), 'Without a saved state the plugin is enabled.');

        plugin_settings::set_enabled('local_assign_ai', false);
        $this->assertFalse(assignment_config::is_autograde_enabled($assign));

        plugin_settings::set_enabled('local_assign_ai', true);
        $this->assertTrue(assignment_config::is_autograde_enabled($assign));
    }

    /**
     * S6: the former site setting of the plugin is no longer read.
     *
     * @return void
     */
    public function test_former_setting_is_ignored(): void {
        $this->resetAfterTest();
        $assign = $this->create_autograded_assign();

        plugin_settings::set_enabled('local_assign_ai', true);
        set_config('enableassignai', 0, 'local_assign_ai');
        $this->assertTrue(assignment_config::is_autograde_enabled($assign));

        plugin_settings::set_enabled('local_assign_ai', false);
        set_config('enableassignai', 1, 'local_assign_ai');
        $this->assertFalse(assignment_config::is_autograde_enabled($assign));
    }

    /**
     * S7: the settings page neither defines nor reseeds the former switch, with or without tenancy.
     *
     * @return void
     */
    public function test_settings_page_no_longer_defines_the_setting(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        unset_config('enableassignai', 'local_assign_ai');

        $root = admin_get_root(true, true);
        $page = $root->locate('local_assign_ai_settings');
        $this->assertInstanceOf(\admin_settingpage::class, $page);

        $names = [];
        foreach ((array) $page->settings as $setting) {
            $names[] = $setting->plugin . '/' . $setting->name;
        }
        $this->assertContains('local_assign_ai/defaultenableai', $names);
        $this->assertNotContains('local_assign_ai/enableassignai', $names);
        $this->assertFalse(get_config('local_assign_ai', 'enableassignai'), 'The settings page must not reseed it.');
    }

    /**
     * Moodle Workplace: the provider switch follows the tenant of the user, never the tenant owning the course.
     *
     * A user of tenant A, which disabled Assign AI, is blocked even in a course of tenant B; a user of tenant B
     * keeps working even in a course of tenant A.
     *
     * @return void
     */
    public function test_tenant_a_disabled_while_tenant_b_enabled(): void {
        $this->require_tenancy();
        $this->resetAfterTest();

        $tenanta = $this->create_tenant('Tenant A');
        $tenantb = $this->create_tenant('Tenant B');
        $assigna = $this->create_autograded_assign((int) $tenanta->categoryid);
        $assignb = $this->create_autograded_assign((int) $tenantb->categoryid);
        $tenantgenerator = $this->getDataGenerator()->get_plugin_generator('tool_tenant');
        $usera = (int) $tenantgenerator->create_user(['tenantid' => (int) $tenanta->id])->id;
        $userb = (int) $tenantgenerator->create_user(['tenantid' => (int) $tenantb->id])->id;

        plugin_settings::set_enabled('local_assign_ai', false, (int) $tenanta->id);
        plugin_settings::set_enabled('local_assign_ai', true, (int) $tenantb->id);

        foreach ([$assigna, $assignb] as $assign) {
            $assignid = (int) $assign->get_instance()->id;

            $this->assertFalse(assignment_config::is_autograde_enabled($assign, $usera));
            $this->assertFalse(assignment_config::is_ai_enabled_for_assignment($assignid, $usera));

            $this->assertTrue(assignment_config::is_autograde_enabled($assign, $userb));
            $this->assertTrue(assignment_config::is_ai_enabled_for_assignment($assignid, $userb));
        }

        // The tenant passed to is_ai_available() only selects the AI default; the provider follows the user.
        $this->assertFalse(assignment_config::is_ai_available((int) $tenantb->id, $usera));
        $this->assertTrue(assignment_config::is_ai_available((int) $tenanta->id, $userb));

        // Interactive checks without a user id follow the tenant of the current user.
        $this->setUser($usera);
        $this->assertFalse(assignment_config::is_ai_enabled_for_assignment((int) $assignb->get_instance()->id));
        $this->setUser($userb);
        $this->assertTrue(assignment_config::is_ai_enabled_for_assignment((int) $assigna->get_instance()->id));
    }

    /**
     * Moodle Workplace: the upgrade copies the switch of every tenant into the provider.
     *
     * @return void
     */
    public function test_upgrade_copies_the_tenant_switches_to_the_provider(): void {
        $this->require_tenancy();
        $this->resetAfterTest();

        $tenanta = (int) $this->create_tenant('Upgrade tenant A')->id;
        $tenantb = (int) $this->create_tenant('Upgrade tenant B')->id;
        $this->store_former_tenant_switch($tenanta, '0');
        $this->store_former_tenant_switch($tenantb, '1');

        $this->run_upgrade();

        $this->assertFalse(plugin_settings::is_enabled('local_assign_ai', $tenanta));
        $this->assertTrue(plugin_settings::is_enabled('local_assign_ai', $tenantb));
    }

    /**
     * The upgrade copies the former site value into the site default of the provider.
     *
     * @return void
     */
    public function test_upgrade_copies_the_site_switch_to_the_provider(): void {
        $this->require_provider();
        $this->resetAfterTest();

        unset_config(plugin_settings::CONFIG_PREFIX . 'local_assign_ai', 'aiprovider_datacurso');
        set_config('enableassignai', '0', 'local_assign_ai');

        $this->run_upgrade();

        // Without a current user the provider reads its site default.
        $this->assertFalse(plugin_settings::is_enabled('local_assign_ai'));
    }

    /**
     * Moodle Workplace: a value the provider already holds for a tenant is never overwritten.
     *
     * @return void
     */
    public function test_upgrade_does_not_overwrite_the_provider(): void {
        $this->require_tenancy();
        $this->resetAfterTest();

        $tenantid = (int) $this->create_tenant('Upgrade tenant kept')->id;
        plugin_settings::set_enabled('local_assign_ai', true, $tenantid);
        $this->store_former_tenant_switch($tenantid, '0');

        $this->run_upgrade();

        $this->assertTrue(plugin_settings::is_enabled('local_assign_ai', $tenantid));
    }

    /**
     * The upgrade deletes the former site value and every former tenant value.
     *
     * @return void
     */
    public function test_upgrade_removes_the_former_values(): void {
        global $DB;

        $this->require_provider();
        $this->resetAfterTest();

        set_config('enableassignai', '1', 'local_assign_ai');
        if (class_exists('\\tool_tenant\\tenancy')) {
            $this->store_former_tenant_switch((int) $this->create_tenant('Upgrade tenant removed')->id, '0');
        }

        $this->run_upgrade();

        $this->assertFalse(get_config('local_assign_ai', 'enableassignai'));
        $this->assertFalse($DB->record_exists('local_assign_ai_tenant_config', ['name' => 'enableassignai']));
    }

    /**
     * Creates a tenant through the Workplace generator.
     *
     * @param string $name Tenant name.
     * @return \stdClass The tenant record.
     */
    private function create_tenant(string $name): \stdClass {
        $category = $this->getDataGenerator()->create_category(['name' => $name . ' category']);
        return $this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_tenant([
            'name' => $name,
            'categoryid' => $category->id,
        ]);
    }

    /**
     * Skips the current test when Moodle Workplace tenancy or the Datacurso AI provider is not installed.
     */
    private function require_tenancy(): void {
        if (!class_exists('\\tool_tenant\\tenancy')) {
            $this->markTestSkipped('Moodle Workplace (tool_tenant) is not installed.');
        }
        $this->require_provider();
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
