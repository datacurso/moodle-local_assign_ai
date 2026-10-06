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

namespace local_assign_ai\local;

/**
 * Resolves the Moodle Workplace tenant an assignment, course or user belongs to.
 *
 * On a site without tool_tenant (plain Moodle LMS) every method returns the implicit
 * tenant 0, so callers fall back to the site wide behaviour. The tool_tenant classes are
 * only referenced after is_tenancy_available() has been checked.
 *
 * @package     local_assign_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tenant_context {
    /** @var int Tenant id used when there is no tenancy. */
    public const NO_TENANT = 0;

    /**
     * Whether this site provides tenancy through tool_tenant.
     *
     * @return bool
     */
    public static function is_tenancy_available(): bool {
        return class_exists('\tool_tenant\tenancy');
    }

    /**
     * Returns the tenant that owns a course, found by walking its category and the parents of the category.
     *
     * @param int $courseid Course id.
     * @return int Tenant id, or 0 when the course is not inside a tenant category.
     */
    public static function get_tenant_id_for_course(int $courseid): int {
        global $DB;

        if (!self::is_tenancy_available() || $courseid <= 0) {
            return self::NO_TENANT;
        }

        $categoryid = $DB->get_field('course', 'category', ['id' => $courseid], IGNORE_MISSING);
        if (!$categoryid) {
            return self::NO_TENANT;
        }

        $path = $DB->get_field('course_categories', 'path', ['id' => $categoryid], IGNORE_MISSING);
        if (!$path) {
            return self::NO_TENANT;
        }

        // Deepest category first, so a nested tenant wins over its parent.
        $ids = array_reverse(array_filter(array_map('intval', explode('/', (string) $path))));
        foreach ($ids as $id) {
            $tenant = \tool_tenant\tenancy::find_tenant_by_category_id($id);
            if ($tenant) {
                return (int) $tenant->get('id');
            }
        }

        return self::NO_TENANT;
    }

    /**
     * Returns the tenant of a user.
     *
     * @param int $userid User id.
     * @return int Tenant id, or 0 when tenancy is not available.
     */
    public static function get_tenant_id_for_user(int $userid): int {
        if (!self::is_tenancy_available() || $userid <= 0) {
            return self::NO_TENANT;
        }

        return (int) \tool_tenant\tenancy::get_tenant_id($userid);
    }

    /**
     * Returns the tenant of the current user.
     *
     * @return int
     */
    public static function get_current_tenant_id(): int {
        global $USER;

        return self::get_tenant_id_for_user((int) ($USER->id ?? 0));
    }

    /**
     * Returns the tenant owning an assignment instance.
     *
     * @param int $assignmentid Assignment instance id.
     * @return int
     */
    public static function get_tenant_id_for_assignment(int $assignmentid): int {
        global $DB;

        if (!self::is_tenancy_available() || $assignmentid <= 0) {
            return self::NO_TENANT;
        }

        $courseid = (int) $DB->get_field('assign', 'course', ['id' => $assignmentid], IGNORE_MISSING);
        return self::resolve($courseid);
    }

    /**
     * Resolves the tenant for processing: the course tenant first, then the student's tenant.
     *
     * @param int $courseid Course id (0 when unknown).
     * @param int $userid User id, normally the student (0 when unknown).
     * @return int Tenant id, 0 when none could be resolved.
     */
    public static function resolve(int $courseid = 0, int $userid = 0): int {
        $tenantid = self::get_tenant_id_for_course($courseid);
        if ($tenantid) {
            return $tenantid;
        }

        return self::get_tenant_id_for_user($userid);
    }

    /**
     * Resolves the tenant for an interactive request: the course tenant, else the tenant of the current user.
     *
     * @param int $courseid Course id.
     * @return int
     */
    public static function resolve_for_current_user(int $courseid): int {
        global $USER;

        return self::resolve($courseid, (int) ($USER->id ?? 0));
    }

    /**
     * Returns the tenants whose settings the user may edit, keyed by tenant id.
     *
     * Site administrators may edit every tenant; other users only their own tenant,
     * and only when they hold local/assign_ai:managetenantsettings.
     *
     * @param int|null $userid User id, defaults to the current user.
     * @return string[] Tenant names keyed by id.
     */
    public static function get_manageable_tenants(?int $userid = null): array {
        global $USER;

        if (!self::is_tenancy_available()) {
            return [];
        }

        $userid = $userid ?? (int) $USER->id;
        $system = \context_system::instance();
        if (!has_capability('local/assign_ai:managetenantsettings', $system, $userid)) {
            return [];
        }

        $tenants = \tool_tenant\tenancy::get_tenants();
        $names = [];
        foreach ($tenants as $tenant) {
            $names[(int) $tenant->id] = format_string($tenant->name);
        }

        if (is_siteadmin($userid)) {
            return $names;
        }

        $own = self::get_tenant_id_for_user($userid);
        return isset($names[$own]) ? [$own => $names[$own]] : [];
    }

    /**
     * Whether the user may edit the settings of the given tenant.
     *
     * @param int $tenantid Tenant id.
     * @param int|null $userid User id, defaults to the current user.
     * @return bool
     */
    public static function can_manage_tenant_settings(int $tenantid, ?int $userid = null): bool {
        return array_key_exists($tenantid, self::get_manageable_tenants($userid));
    }
}
