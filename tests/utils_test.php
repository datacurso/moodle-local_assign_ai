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
 * Tests for the utils helper class.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai;

/**
 * Unit tests confirming that the accent-stripping behaviour has been removed (MDL-UNIT-001).
 *
 * @covers \local_assign_ai\api\client
 * @group local_assign_ai
 */
final class utils_test extends \advanced_testcase {
    /**
     * MDL-UNIT-001: The utils helper class (which housed remove_accents / normalize_payload)
     * must no longer exist — its removal is the fix, not a refactor.
     */
    public function test_utils_class_no_longer_exists(): void {
        $this->assertFalse(
            class_exists('local_assign_ai\\utils', false),
            'The utils class should have been deleted as part of MDL-UNIT-001.'
        );
    }

    /**
     * MDL-UNIT-001: client::send_to_ai() must not reference normalize_payload or remove_accents.
     * Verified by inspecting the source of client.php — the fix is structural, not runtime.
     */
    public function test_client_source_does_not_strip_accents(): void {
        global $CFG;

        $source = file_get_contents(
            $CFG->dirroot . '/local/assign_ai/classes/api/client.php'
        );

        $this->assertStringNotContainsString(
            'normalize_payload',
            $source,
            'client.php must not call normalize_payload after MDL-UNIT-001.'
        );
        $this->assertStringNotContainsString(
            'remove_accents',
            $source,
            'client.php must not call remove_accents after MDL-UNIT-001.'
        );
    }
}
