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
 * Tests for the web service declarations in db/services.php.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai\external;

/**
 * Checks that every declared web service states the capability its execute() requires.
 *
 * @group local_assign_ai
 * @coversNothing
 */
final class services_declaration_test extends \basic_testcase {
    /**
     * Capability each external function requires through activity_access::require_access().
     */
    private const EXPECTED_CAPABILITIES = [
        'local_assign_ai_get_details' => 'local/assign_ai:viewdetails',
        'local_assign_ai_update_response' => 'local/assign_ai:changestatus',
        'local_assign_ai_change_status' => 'local/assign_ai:changestatus',
        'local_assign_ai_process_submission' => 'local/assign_ai:review',
        'local_assign_ai_cancel_review' => 'local/assign_ai:review',
        'local_assign_ai_approve_all_pending' => 'local/assign_ai:changestatus',
        'local_assign_ai_get_progress' => 'local/assign_ai:review',
        'local_assign_ai_get_token' => 'local/assign_ai:review',
    ];

    /**
     * Loads the function declarations of the plugin.
     *
     * @return array
     */
    private function declared_functions(): array {
        global $CFG;
        $functions = [];
        require($CFG->dirroot . '/local/assign_ai/db/services.php');
        return $functions;
    }

    /**
     * Returns the capability literal the execute() of a class passes to activity_access::require_access().
     *
     * @param string $classname Fully qualified external class name.
     * @return string[] Capabilities found in the class source.
     */
    private function capabilities_checked_by(string $classname): array {
        $source = file_get_contents((new \ReflectionClass($classname))->getFileName());
        preg_match_all("/activity_access::require_access\([^;]*?'([a-z_]+\/[a-z_]+:[a-z_]+)'/s", $source, $matches);
        return array_values(array_unique($matches[1]));
    }

    /**
     * LAA-SEC-006: the declared functions are exactly the expected ones.
     */
    public function test_every_function_is_covered(): void {
        $this->assertEqualsCanonicalizing(
            array_keys(self::EXPECTED_CAPABILITIES),
            array_keys($this->declared_functions())
        );
    }

    /**
     * LAA-SEC-006: every function declares the capability its execute() requires.
     */
    public function test_every_function_declares_its_required_capability(): void {
        foreach ($this->declared_functions() as $name => $definition) {
            $this->assertArrayHasKey('capabilities', $definition, "$name must declare 'capabilities'.");
            $this->assertSame(self::EXPECTED_CAPABILITIES[$name] ?? null, $definition['capabilities'], $name);
        }
    }

    /**
     * LAA-SEC-006: the declared capability is the one execute() checks through activity_access.
     */
    public function test_declared_capability_matches_the_one_checked_by_execute(): void {
        foreach ($this->declared_functions() as $name => $definition) {
            $checked = $this->capabilities_checked_by($definition['classname']);
            $this->assertSame([$definition['capabilities'] ?? null], $checked, $name);
        }
    }
}
