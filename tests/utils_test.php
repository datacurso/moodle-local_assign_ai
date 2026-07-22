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
 * Unit tests for the utils helper class.
 *
 * @coversDefaultClass \local_assign_ai\utils
 * @group local_assign_ai
 */
final class utils_test extends \advanced_testcase {
    /**
     * MDL-UNIT-001: Texts sent to the AI service must keep their original characters (accents, enie).
     *
     * @covers ::normalize_payload
     */
    public function test_payload_text_preserves_original_characters(): void {
        $this->markTestSkipped(
            'Documented defect: the plugin strips accents via utils::remove_accents before sending to the AI '
            . 'service, preventing spelling evaluation. Pending fix (MDL-UNIT-001).'
        );
    }
}
