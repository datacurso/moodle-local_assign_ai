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

/**
 * Tests for the plugin upgrade steps.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai;

/**
 * Tests for the error digest upgrade step.
 *
 * @group local_assign_ai
 */
final class upgrade_test extends \advanced_testcase {
    /**
     * The upgrade adds errornotifiedtime to the pending table, and is idempotent.
     */
    public function test_upgrade_adds_errornotifiedtime(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/assign_ai/db/upgrade.php');

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_assign_ai_pending');
        $field = new \xmldb_field('errornotifiedtime', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timemodified');
        $this->assertTrue($dbman->field_exists($table, $field));

        $dbman->drop_field($table, $field);
        $this->assertFalse($dbman->field_exists($table, $field));

        // The savepoint refuses to run at the installed version, so pretend the old one is installed.
        set_config('version', 2026110324, 'local_assign_ai');
        $this->assertTrue(xmldb_local_assign_ai_upgrade(2026110324));
        $this->assertTrue($dbman->field_exists($table, $field));

        // Running it again must not fail (field_exists guard).
        set_config('version', 2026110324, 'local_assign_ai');
        $this->assertTrue(xmldb_local_assign_ai_upgrade(2026110324));
    }
}
