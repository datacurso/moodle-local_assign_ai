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
 * Tests for tenant resolution and tenant scoped configuration.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai;

use local_assign_ai\config\assignment_config;
use local_assign_ai\local\tenant_config;
use local_assign_ai\local\tenant_context;

/**
 * Unit tests for tenant_context, tenant_config and the tenant aware assignment configuration.
 *
 * @covers \local_assign_ai\local\tenant_context
 * @covers \local_assign_ai\local\tenant_config
 * @covers \local_assign_ai\config\assignment_config
 * @group local_assign_ai
 */
final class tenant_config_test extends \advanced_testcase {
    /** @var int Next reserved assign instance id for this file (range 8010+). */
    private static $nextassignid = 8010;

    /**
     * Creates an assignment with a process-unique id and no stored configuration row.
     *
     * assignment_config::get() keeps a static cache keyed by assignment id while the PHPUnit database
     * reset reuses ids across tests, so each assignment claims an id from this file's reserved range.
     *
     * @param int $courseid Course id.
     * @return int The assignment instance id.
     */
    private function create_assign_with_unique_id(int $courseid): int {
        global $DB;

        $DB->import_record('assign', (object) [
            'id' => self::$nextassignid,
            'course' => $courseid,
            'name' => 'filler',
            'intro' => '',
            'introformat' => FORMAT_HTML,
        ]);
        self::$nextassignid += 2;
        $DB->get_manager()->reset_sequence('assign');

        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $courseid]);
        $DB->delete_records('local_assign_ai_config', ['assignmentid' => $instance->id]);

        return (int) $instance->id;
    }

    /**
     * Skips the test when Moodle Workplace tenancy is not installed.
     */
    private function require_tenancy(): void {
        if (!class_exists('\tool_tenant\tenancy')) {
            $this->markTestSkipped('Moodle Workplace (tool_tenant) is not installed.');
        }
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
     * Without a tenant id the DAO reads the site wide plugin config.
     */
    public function test_tenant_config_falls_back_to_site_config(): void {
        $this->resetAfterTest();

        set_config('defaultprompt', 'Site prompt', 'local_assign_ai');

        $this->assertSame('Site prompt', tenant_config::get('defaultprompt', 0));
        $this->assertSame('Site prompt', tenant_config::get('defaultprompt', 77));
        $this->assertFalse(tenant_config::get('doesnotexist', 77));
        $this->assertSame('x', tenant_config::get('doesnotexist', 77, 'x'));
    }

    /**
     * Stored tenant values override the site value, and are isolated per tenant.
     */
    public function test_tenant_config_set_and_get_per_tenant(): void {
        $this->resetAfterTest();

        set_config('defaultprompt', 'Site prompt', 'local_assign_ai');
        tenant_config::set('defaultprompt', 5, 'Tenant five');
        tenant_config::set('defaultprompt', 6, 'Tenant six');

        $this->assertSame('Tenant five', tenant_config::get('defaultprompt', 5));
        $this->assertSame('Tenant six', tenant_config::get('defaultprompt', 6));
        $this->assertSame('Site prompt', tenant_config::get('defaultprompt', 7));

        tenant_config::set('defaultprompt', 5, 'Updated');
        $this->assertSame('Updated', tenant_config::get('defaultprompt', 5));
        $this->assertSame(2, $this->count_rows('defaultprompt'));
    }

    /**
     * Tenant 0 is the implicit site tenant and never writes to the tenant table.
     */
    public function test_tenant_config_set_for_tenant_zero_is_rejected(): void {
        $this->resetAfterTest();

        $this->expectException(\coding_exception::class);
        tenant_config::set('defaultprompt', 0, 'x');
    }

    /**
     * Only the supported setting names can be stored.
     */
    public function test_tenant_config_rejects_unknown_names(): void {
        $this->resetAfterTest();

        $this->expectException(\coding_exception::class);
        tenant_config::set('licensekey', 3, 'x');
    }

    /**
     * Deleting a tenant value restores the site fallback.
     */
    public function test_tenant_config_delete(): void {
        $this->resetAfterTest();

        set_config('defaultdelayminutes', 60, 'local_assign_ai');
        tenant_config::set('defaultdelayminutes', 3, 15);
        $this->assertEquals(15, tenant_config::get('defaultdelayminutes', 3));

        tenant_config::delete_all(3);
        $this->assertEquals(60, tenant_config::get('defaultdelayminutes', 3));
    }

    /**
     * Counts the stored rows for a setting name.
     *
     * @param string $name Setting name.
     * @return int
     */
    private function count_rows(string $name): int {
        global $DB;

        return $DB->count_records('local_assign_ai_tenant_config', ['name' => $name]);
    }

    /**
     * Site level behaviour (no tenancy) is unchanged: tenant 0 reads site config.
     */
    public function test_get_default_without_tenant_reads_site_config(): void {
        $this->resetAfterTest();

        set_config('defaultautograde', 1, 'local_assign_ai');

        $this->assertEquals(1, assignment_config::get_default('defaultautograde', 0));
        $this->assertEquals(1, assignment_config::get_default('defaultautograde', null));
    }

    /**
     * The tenant resolver returns 0 when tenancy is unavailable.
     */
    public function test_resolver_returns_zero_without_tenancy(): void {
        if (class_exists('\tool_tenant\tenancy')) {
            $this->markTestSkipped('Only meaningful on a site without Workplace.');
        }

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $this->assertFalse(tenant_context::is_tenancy_available());
        $this->assertSame(0, tenant_context::get_tenant_id_for_course((int) $course->id));
        $this->assertSame(0, tenant_context::get_tenant_id_for_user((int) $user->id));
        $this->assertSame(0, tenant_context::resolve((int) $course->id, (int) $user->id));
    }

    /**
     * A course inside a tenant category (or a sub category of it) resolves to that tenant.
     */
    public function test_resolver_walks_course_category_parents(): void {
        $this->require_tenancy();
        $this->resetAfterTest();

        $tenant = $this->create_tenant('Tenant A');
        $sub = $this->getDataGenerator()->create_category(['parent' => $tenant->categoryid]);
        $subsub = $this->getDataGenerator()->create_category(['parent' => $sub->id]);

        $direct = $this->getDataGenerator()->create_course(['category' => $tenant->categoryid]);
        $nested = $this->getDataGenerator()->create_course(['category' => $subsub->id]);

        $this->assertSame((int) $tenant->id, tenant_context::get_tenant_id_for_course((int) $direct->id));
        $this->assertSame((int) $tenant->id, tenant_context::get_tenant_id_for_course((int) $nested->id));
    }

    /**
     * A course outside any tenant category falls back to the student's tenant.
     */
    public function test_resolver_falls_back_to_user_tenant(): void {
        $this->require_tenancy();
        $this->resetAfterTest();

        $tenant = $this->create_tenant('Tenant B');
        $generator = $this->getDataGenerator()->get_plugin_generator('tool_tenant');
        $user = $generator->create_user(['tenantid' => $tenant->id]);
        $sharedcourse = $this->getDataGenerator()->create_course();

        $this->assertSame(0, tenant_context::get_tenant_id_for_course((int) $sharedcourse->id));
        $this->assertSame((int) $tenant->id, tenant_context::get_tenant_id_for_user((int) $user->id));
        $this->assertSame((int) $tenant->id, tenant_context::resolve((int) $sharedcourse->id, (int) $user->id));
    }

    /**
     * The course tenant wins over the student's tenant.
     */
    public function test_resolver_prefers_course_tenant(): void {
        $this->require_tenancy();
        $this->resetAfterTest();

        $tenanta = $this->create_tenant('Tenant C');
        $tenantb = $this->create_tenant('Tenant D');
        $user = $this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_user(['tenantid' => $tenantb->id]);
        $course = $this->getDataGenerator()->create_course(['category' => $tenanta->categoryid]);

        $this->assertSame((int) $tenanta->id, tenant_context::resolve((int) $course->id, (int) $user->id));
    }

    /**
     * Each tenant reads its own defaults, falling back to the site value when it has none.
     */
    public function test_effective_config_uses_the_course_tenant_defaults(): void {
        $this->require_tenancy();
        $this->resetAfterTest();
        $this->setAdminUser();

        $tenanta = $this->create_tenant('Tenant E');
        $tenantb = $this->create_tenant('Tenant F');

        set_config('defaultprompt', 'Site prompt', 'local_assign_ai');
        set_config('defaultdelayminutes', 60, 'local_assign_ai');
        tenant_config::set('defaultprompt', (int) $tenanta->id, 'Prompt A');
        tenant_config::set('defaultautograde', (int) $tenanta->id, 1);

        $coursea = $this->getDataGenerator()->create_course(['category' => $tenanta->categoryid]);
        $courseb = $this->getDataGenerator()->create_course(['category' => $tenantb->categoryid]);
        $assigna = $this->create_assign_with_unique_id((int) $coursea->id);
        $assignb = $this->create_assign_with_unique_id((int) $courseb->id);

        $effectivea = assignment_config::get_effective($assigna);
        $effectiveb = assignment_config::get_effective($assignb);

        $this->assertSame('Prompt A', $effectivea->prompt);
        $this->assertSame(1, $effectivea->autograde);
        $this->assertSame('Site prompt', $effectiveb->prompt);
        $this->assertSame(0, $effectiveb->autograde);
    }

    /**
     * The tenant master switch only pauses the AI for that tenant.
     */
    public function test_feature_switch_is_per_tenant(): void {
        $this->require_tenancy();
        $this->resetAfterTest();

        $tenanta = $this->create_tenant('Tenant G');
        $tenantb = $this->create_tenant('Tenant H');
        set_config('enableassignai', 1, 'local_assign_ai');
        set_config('defaultenableai', 1, 'local_assign_ai');
        tenant_config::set('enableassignai', (int) $tenanta->id, 0);
        tenant_config::set('defaultenableai', (int) $tenanta->id, 0);

        $this->assertFalse(assignment_config::is_feature_enabled((int) $tenanta->id));
        $this->assertTrue(assignment_config::is_feature_enabled((int) $tenantb->id));
        $this->assertFalse(assignment_config::is_global_ai_enabled((int) $tenanta->id));
        $this->assertTrue(assignment_config::is_global_ai_enabled((int) $tenantb->id));
    }

    /**
     * The provider client is built for the resolved tenant so cron and ad hoc tasks use its licence.
     */
    public function test_client_arguments_carry_the_resolved_tenant(): void {
        $this->assertSame([null, 42], \local_assign_ai\api\client::get_provider_client_arguments(42));
    }

    /**
     * Without a resolved tenant the provider keeps its default behaviour (tenant of the current user).
     */
    public function test_client_arguments_without_tenant_keep_provider_default(): void {
        $this->assertSame([], \local_assign_ai\api\client::get_provider_client_arguments(0));
    }

    /**
     * Tenant admins manage only their own tenant; site admins may manage any tenant.
     */
    public function test_can_manage_tenant_settings(): void {
        $this->require_tenancy();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator()->get_plugin_generator('tool_tenant');
        $tenanta = $this->create_tenant('Tenant I');
        $tenantb = $this->create_tenant('Tenant J');
        $tenantadmin = $generator->create_user(['tenantid' => $tenanta->id, 'tenantadmin' => 1]);
        $plainuser = $generator->create_user(['tenantid' => $tenanta->id]);
        $admin = get_admin();

        $this->assertTrue(tenant_context::can_manage_tenant_settings((int) $tenanta->id, (int) $tenantadmin->id));
        $this->assertFalse(tenant_context::can_manage_tenant_settings((int) $tenantb->id, (int) $tenantadmin->id));
        $this->assertFalse(tenant_context::can_manage_tenant_settings((int) $tenanta->id, (int) $plainuser->id));
        $this->assertTrue(tenant_context::can_manage_tenant_settings((int) $tenantb->id, (int) $admin->id));
    }

    /**
     * The capability is registered and whitelisted for the tenant administrator role.
     */
    public function test_capability_is_whitelisted_for_tenant_admins(): void {
        $this->assertNotNull(get_capability_info('local/assign_ai:managetenantsettings'));
        $caps = \local_assign_ai\tool_tenant::get_tenant_admin_capabilities();
        $this->assertSame(CAP_ALLOW, $caps['local/assign_ai:managetenantsettings']);
    }
}
