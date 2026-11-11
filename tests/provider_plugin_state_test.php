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
 * Tests that the state of Assign AI is kept by the Datacurso AI provider.
 *
 * The plugin no longer has its own "Enable Assign AI" setting: the provider stores whether the plugin
 * is enabled, and the former setting is removed on upgrade.
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
     * assignment_config caches each assignment configuration for the whole PHP process, so the
     * assignment gets an id no other test of the suite uses; otherwise a configuration cached by an
     * earlier test for the same id leaks into this one.
     *
     * @param int $assignid Unused assign id to create the assignment with.
     * @return \assign
     */
    private function create_autograded_assign(int $assignid): \assign {
        global $DB;

        $this->require_provider();

        set_config('defaultenableai', 1, 'local_assign_ai');
        set_config('defaultautograde', 1, 'local_assign_ai');

        $course = $this->getDataGenerator()->create_course();
        $DB->import_record('assign', (object) [
            'id' => $assignid - 1,
            'course' => $course->id,
            'name' => 'filler',
            'intro' => '',
            'introformat' => FORMAT_HTML,
        ]);
        $DB->get_manager()->reset_sequence('assign');
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $instance->id, $course->id, false, MUST_EXIST);

        return new \assign(\context_module::instance($cm->id), $cm, $course);
    }

    /**
     * S6: the feature follows the state saved in the provider.
     *
     * @return void
     */
    public function test_feature_follows_the_provider_state(): void {
        $this->resetAfterTest();
        $assign = $this->create_autograded_assign(7010);

        unset_config(plugin_settings::CONFIG_PREFIX . 'local_assign_ai', 'aiprovider_datacurso');
        $this->assertTrue(assignment_config::is_autograde_enabled($assign), 'Without a saved state the plugin is enabled.');

        plugin_settings::set_enabled('local_assign_ai', false);
        $this->assertFalse(assignment_config::is_autograde_enabled($assign));

        plugin_settings::set_enabled('local_assign_ai', true);
        $this->assertTrue(assignment_config::is_autograde_enabled($assign));
    }

    /**
     * S6: the former setting of the plugin is no longer read.
     *
     * @return void
     */
    public function test_former_setting_is_ignored(): void {
        $this->resetAfterTest();
        $assign = $this->create_autograded_assign(7020);

        plugin_settings::set_enabled('local_assign_ai', true);
        set_config('enableassignai', 0, 'local_assign_ai');
        $this->assertTrue(assignment_config::is_autograde_enabled($assign));

        plugin_settings::set_enabled('local_assign_ai', false);
        set_config('enableassignai', 1, 'local_assign_ai');
        $this->assertFalse(assignment_config::is_autograde_enabled($assign));
    }

    /**
     * S7: the settings page no longer defines nor reseeds the former setting.
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
     * S7: the upgrade removes the former setting.
     *
     * @return void
     */
    public function test_upgrade_removes_the_former_setting(): void {
        $this->resetAfterTest();

        set_config('enableassignai', '1', 'local_assign_ai');
        set_config('version', 2026110500, 'local_assign_ai');

        xmldb_local_assign_ai_upgrade(2026110500);

        $this->assertFalse(get_config('local_assign_ai', 'enableassignai'));
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
