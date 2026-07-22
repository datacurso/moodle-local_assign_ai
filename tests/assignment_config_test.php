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
 * Tests for the assignment configuration resolution.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai;

use local_assign_ai\config\assignment_config;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Unit tests for the assignment configuration resolution.
 *
 * @coversDefaultClass \local_assign_ai\config\assignment_config
 * @group local_assign_ai
 */
final class assignment_config_test extends \advanced_testcase {
    /** @var int Next reserved assign instance id for this file (range 7010+, see create_assign()). */
    private static $nextassignid = 7010;

    /**
     * Creates a course with an assignment and returns the instance record plus the assign object.
     *
     * assignment_config::get() keeps a per-process static cache keyed by assignment id while the PHPUnit
     * database reset reuses ids across tests, so each test claims a process-unique id from this file's
     * reserved range (7010+) to guarantee it operates on an assignment id no other test has cached.
     *
     * @return array The assign instance record and the assign object.
     */
    private function create_assign(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $DB->import_record('assign', (object) [
            'id' => self::$nextassignid++,
            'course' => $course->id,
            'name' => 'filler',
            'intro' => '',
            'introformat' => FORMAT_HTML,
        ]);
        $DB->get_manager()->reset_sequence('assign');
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $instance->id);
        $context = \context_module::instance($cm->id);

        return [
            $instance,
            new \assign($context, $cm, $course),
        ];
    }

    /**
     * Removes the configuration row auto-created for an assignment.
     *
     * Creating an assign module through the generator runs add_moduleinfo(), which triggers
     * local_assign_ai_coursemodule_edit_post_actions() and stores a configuration row built from the
     * site defaults. Tests covering the "no per-assignment row" scenarios must remove it first.
     *
     * @param int $assignmentid The assignment instance id.
     * @return void
     */
    private function delete_config_row(int $assignmentid): void {
        global $DB;

        $DB->delete_records('local_assign_ai_config', ['assignmentid' => $assignmentid]);
    }

    /**
     * Replaces the per-assignment configuration row with the given values.
     *
     * @param int $assignmentid The assignment instance id.
     * @param array $values Column overrides for the configuration row.
     * @return void
     */
    private function insert_config_row(int $assignmentid, array $values): void {
        global $DB;

        $this->delete_config_row($assignmentid);

        $now = time();
        $record = (object) array_merge(
            [
                'assignmentid' => $assignmentid,
                'enableai' => 1,
                'autograde' => 0,
                'usedelay' => 0,
                'delayminutes' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ],
            $values
        );
        $DB->insert_record('local_assign_ai_config', $record);
    }

    /**
     * MDL-UNIT-004: A per-assignment configuration row overrides the site defaults.
     *
     * @covers ::get_effective
     * @covers ::is_autograde_enabled
     */
    public function test_assignment_config_row_overrides_site_defaults(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$instance, $assign] = $this->create_assign();

        set_config('defaultenableai', 1, 'local_assign_ai');
        set_config('defaultautograde', 0, 'local_assign_ai');
        set_config('defaultusedelay', 0, 'local_assign_ai');
        set_config('defaultdelayminutes', 60, 'local_assign_ai');
        set_config('defaultprompt', 'Site default prompt', 'local_assign_ai');

        $this->insert_config_row((int) $instance->id, [
            'enableai' => 1,
            'autograde' => 1,
            'usedelay' => 1,
            'delayminutes' => 15,
            'prompt' => 'Assignment specific prompt',
            'lang' => 'en',
        ]);

        $config = assignment_config::get_effective((int) $instance->id);

        $this->assertSame(1, $config->enableai);
        $this->assertSame(1, $config->autograde);
        $this->assertSame(1, $config->usedelay);
        $this->assertSame(15, $config->delayminutes);
        $this->assertSame('Assignment specific prompt', $config->prompt);
        $this->assertSame('en', $config->lang);
        $this->assertTrue(assignment_config::is_autograde_enabled($assign));
    }

    /**
     * MDL-UNIT-004: Without a per-assignment row the site default settings are used.
     *
     * @covers ::get_effective
     * @covers ::is_autograde_enabled
     */
    public function test_site_defaults_apply_without_assignment_row(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$instance, $assign] = $this->create_assign();
        $this->delete_config_row((int) $instance->id);

        set_config('defaultenableai', 1, 'local_assign_ai');
        set_config('defaultautograde', 1, 'local_assign_ai');
        set_config('defaultusedelay', 1, 'local_assign_ai');
        set_config('defaultdelayminutes', 45, 'local_assign_ai');
        set_config('defaultprompt', 'Site default prompt', 'local_assign_ai');

        $config = assignment_config::get_effective((int) $instance->id);

        $this->assertSame(1, $config->enableai);
        $this->assertSame(1, $config->autograde);
        $this->assertSame(1, $config->usedelay);
        $this->assertSame(45, $config->delayminutes);
        $this->assertSame('Site default prompt', $config->prompt);
        $this->assertTrue(assignment_config::is_autograde_enabled($assign));
    }

    /**
     * MDL-UNIT-004: Without a per-assignment row and without site defaults the plugin defaults apply.
     *
     * @covers ::get_effective
     * @covers ::is_autograde_enabled
     */
    public function test_plugin_defaults_apply_without_row_or_site_config(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$instance, $assign] = $this->create_assign();
        $this->delete_config_row((int) $instance->id);

        unset_config('defaultenableai', 'local_assign_ai');
        unset_config('defaultautograde', 'local_assign_ai');
        unset_config('defaultusedelay', 'local_assign_ai');
        unset_config('defaultdelayminutes', 'local_assign_ai');
        unset_config('defaultprompt', 'local_assign_ai');

        $config = assignment_config::get_effective((int) $instance->id);

        $this->assertSame(1, $config->enableai);
        $this->assertSame(0, $config->autograde);
        $this->assertSame(0, $config->usedelay);
        $this->assertSame(60, $config->delayminutes);
        $this->assertSame(get_string('promptdefaulttext', 'local_assign_ai'), $config->prompt);
        $this->assertFalse(assignment_config::is_autograde_enabled($assign));
    }

    /**
     * MDL-INT-001: The master switch disables the feature regardless of per-assignment rows.
     *
     * @covers ::is_feature_enabled
     * @covers ::is_autograde_enabled
     */
    public function test_master_switch_disables_feature_regardless_of_assignment_rows(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$instance, $assign] = $this->create_assign();

        $this->insert_config_row((int) $instance->id, [
            'enableai' => 1,
            'autograde' => 1,
        ]);

        set_config('enableassignai', 0, 'local_assign_ai');

        $this->assertFalse(assignment_config::is_feature_enabled());
        $this->assertFalse(assignment_config::is_autograde_enabled($assign));
    }

    /**
     * MDL-INT-001: The mass update disables AI for every assignment, with or without a config row.
     *
     * @covers ::disable_all_assignments_ai
     */
    public function test_disable_all_assignments_ai_disables_every_assignment(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $withrow = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $withoutrow = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $anotherwithoutrow = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $this->insert_config_row((int) $withrow->id, [
            'enableai' => 1,
            'autograde' => 1,
            'usedelay' => 1,
            'delayminutes' => 30,
        ]);
        $this->delete_config_row((int) $withoutrow->id);
        $this->delete_config_row((int) $anotherwithoutrow->id);

        assignment_config::disable_all_assignments_ai();

        $assignmentids = [
            (int) $withrow->id,
            (int) $withoutrow->id,
            (int) $anotherwithoutrow->id,
        ];
        foreach ($assignmentids as $assignmentid) {
            $row = $DB->get_record(
                'local_assign_ai_config',
                ['assignmentid' => $assignmentid],
                '*',
                MUST_EXIST
            );
            $this->assertEquals(0, $row->enableai);
            $this->assertEquals(0, $row->autograde);
            $this->assertEquals(0, $row->usedelay);
        }
    }

    /**
     * MDL-INT-001: The global AI availability reflects the defaultenableai setting.
     *
     * @covers ::is_global_ai_enabled
     */
    public function test_is_global_ai_enabled_reflects_default_setting(): void {
        $this->resetAfterTest();

        set_config('defaultenableai', 1, 'local_assign_ai');
        $this->assertTrue(assignment_config::is_global_ai_enabled());

        set_config('defaultenableai', 0, 'local_assign_ai');
        $this->assertFalse(assignment_config::is_global_ai_enabled());

        unset_config('defaultenableai', 'local_assign_ai');
        $this->assertTrue(assignment_config::is_global_ai_enabled());
    }
}
