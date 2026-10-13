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
 * Contract tests for the production userid pseudonymiser adapter.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai\local;

/**
 * Integration contract between the plugin's pseudonymiser port and the AI provider's helper.
 *
 * This is the only test that touches the provider class; every other test binds a fake
 * through the DI container so the unit suite runs regardless of the provider version.
 *
 * @coversDefaultClass \local_assign_ai\local\provider_pseudonymiser
 * @group local_assign_ai
 */
final class provider_pseudonymiser_test extends \advanced_testcase {
    /**
     * LAA-PRIV-001: With nothing bound in the container, the port resolves to the production
     * adapter, so a real request never uses a fake.
     *
     * @covers \local_assign_ai\hook\di_callbacks::provide_di_configuration
     */
    public function test_container_resolves_the_provider_adapter_by_default(): void {
        \core\di::reset_container();

        $this->assertInstanceOf(provider_pseudonymiser::class, \core\di::get(userid_pseudonymiser::class));
    }

    /**
     * LAA-PRIV-001: The production adapter returns exactly the provider's shared token.
     *
     * @covers ::pseudonymise
     */
    public function test_production_adapter_delegates_to_the_provider(): void {
        if (!class_exists(\aiprovider_datacurso\local\outbound_privacy::class)) {
            $this->markTestSkipped('aiprovider_datacurso with outbound_privacy is not installed.');
        }

        $adapter = new provider_pseudonymiser();

        $this->assertSame(\aiprovider_datacurso\local\outbound_privacy::pseudonymise_userid(12), $adapter->pseudonymise(12));
        $this->assertSame(\aiprovider_datacurso\local\outbound_privacy::pseudonymise_userid(12), $adapter->pseudonymise('12'));
    }
}
