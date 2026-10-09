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
 * Privacy provider tests for local_assign_ai.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\database_table;
use core_privacy\local\metadata\types\external_location;
use core_privacy\local\request\writer;

/**
 * Tests that the external AI transfer is declared in the Privacy API (MDL-INT-023).
 *
 * @coversDefaultClass \local_assign_ai\privacy\provider
 * @group local_assign_ai
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * The metadata must declare the external AI provider location with the sent data.
     *
     * @covers ::get_metadata
     */
    public function test_get_metadata_declares_external_location(): void {
        $collection = new collection('local_assign_ai');
        provider::get_metadata($collection);

        $external = null;
        foreach ($collection->get_collection() as $item) {
            if ($item instanceof external_location && $item->get_name() === 'datacurso_ai') {
                $external = $item;
                break;
            }
        }

        $this->assertNotNull($external, 'An external_location for the AI provider must be declared.');
        $fields = $external->get_privacy_fields();
        $this->assertArrayHasKey('userid', $fields);
        $this->assertArrayHasKey('submission_text', $fields);
        $this->assertArrayHasKey('submission_files', $fields);
    }

    /**
     * All personal-data tables must be declared, including the processing queue.
     *
     * @covers ::get_metadata
     */
    public function test_get_metadata_declares_all_tables(): void {
        $collection = new collection('local_assign_ai');
        provider::get_metadata($collection);

        $tables = [];
        foreach ($collection->get_collection() as $item) {
            if ($item instanceof database_table) {
                $tables[] = $item->get_name();
            }
        }

        $this->assertContains('local_assign_ai_pending', $tables);
        $this->assertContains('local_assign_ai_config', $tables);
        $this->assertContains('local_assign_ai_queue', $tables);
    }

    /**
     * Every string key referenced by the metadata collection must exist.
     *
     * @covers ::get_metadata
     */
    public function test_metadata_string_keys_exist(): void {
        $collection = new collection('local_assign_ai');
        provider::get_metadata($collection);

        foreach ($collection->get_collection() as $item) {
            $this->assertTrue(
                get_string_manager()->string_exists($item->get_summary(), 'local_assign_ai'),
                'Missing summary string: ' . $item->get_summary()
            );
            foreach ($item->get_privacy_fields() as $key) {
                $this->assertTrue(
                    get_string_manager()->string_exists($key, 'local_assign_ai'),
                    'Missing field string: ' . $key
                );
            }
        }
    }

    /**
     * LAA-PRIV-003: Every column stored in local_assign_ai_config is declared, including the
     * teacher-authored prompt, the language and the behaviour flags.
     *
     * @covers ::get_metadata
     */
    public function test_get_metadata_declares_every_config_field(): void {
        $collection = new collection('local_assign_ai');
        provider::get_metadata($collection);

        $table = null;
        foreach ($collection->get_collection() as $item) {
            if ($item instanceof database_table && $item->get_name() === 'local_assign_ai_config') {
                $table = $item;
                break;
            }
        }
        $this->assertNotNull($table, 'The local_assign_ai_config table must be declared.');

        $expected = [
            'assignmentid',
            'autograde',
            'delayminutes',
            'enableai',
            'graderid',
            'lang',
            'prompt',
            'timecreated',
            'timemodified',
            'usedelay',
            'usermodified',
        ];
        $declared = array_keys($table->get_privacy_fields());
        sort($declared);
        $this->assertSame($expected, $declared);
    }

    /**
     * LAA-PRIV-003: Exporting the grader's data includes the configuration values stored
     * against that user (prompt, language and flags), not only the identifiers.
     *
     * @covers ::export_user_data
     */
    public function test_export_includes_grader_configuration_values(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        [, $cm] = get_course_and_cm_from_instance($instance->id, 'assign');

        $values = [
            'enableai' => 1,
            'autograde' => 1,
            'graderid' => $teacher->id,
            'usermodified' => $teacher->id,
            'usedelay' => 1,
            'delayminutes' => 15,
            'prompt' => 'Grade strictly and cite the rubric.',
            'lang' => 'es',
        ];
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

        $context = \context_module::instance($cm->id);
        $this->export_context_data_for_user($teacher->id, $context, 'local_assign_ai');

        $exported = writer::with_context($context)->get_data([
            get_string('privacy:metadata:local_assign_ai_config', 'local_assign_ai'),
        ]);
        $this->assertNotEmpty($exported->entries ?? null, 'The grader configuration must be exported.');
        $entry = reset($exported->entries);
        $this->assertSame('Grade strictly and cite the rubric.', $entry->prompt);
        $this->assertSame('es', $entry->lang);
        $this->assertEquals(1, $entry->autograde);
        $this->assertEquals(1, $entry->usedelay);
        $this->assertEquals(15, $entry->delayminutes);
        $this->assertEquals($teacher->id, $entry->graderid);
    }
}
