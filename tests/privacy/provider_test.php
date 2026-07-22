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

/**
 * Tests that the external AI transfer is declared in the Privacy API.
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
}
