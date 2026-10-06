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
 * Tenant scoped storage for the plugin default settings.
 *
 * Values live in {local_assign_ai_tenant_config}; a tenant without a stored value (and tenant 0,
 * the implicit site tenant) falls back to the site wide plugin configuration.
 *
 * @package     local_assign_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tenant_config {
    /** @var string Database table. */
    private const TABLE = 'local_assign_ai_tenant_config';

    /** @var string[] Settings that can be overridden per tenant. */
    public const SETTING_NAMES = [
        'enableassignai',
        'defaultenableai',
        'defaultautograde',
        'defaultusedelay',
        'defaultdelayminutes',
        'defaultprompt',
    ];

    /**
     * Returns a setting for a tenant, falling back to the site wide value.
     *
     * @param string $name Setting name.
     * @param int $tenantid Tenant id (0 reads the site value).
     * @param mixed $default Returned when neither the tenant nor the site define the setting.
     * @return mixed The stored value as string, or the site value, or $default (false by default).
     */
    public static function get(string $name, int $tenantid, $default = false) {
        global $DB;

        if ($tenantid > 0) {
            $value = $DB->get_field(self::TABLE, 'value', ['tenantid' => $tenantid, 'name' => $name]);
            if ($value !== false) {
                return $value === null ? '' : $value;
            }
        }

        $site = get_config('local_assign_ai', $name);
        return $site === false ? $default : $site;
    }

    /**
     * Stores a setting for a tenant.
     *
     * @param string $name Setting name, one of self::SETTING_NAMES.
     * @param int $tenantid Tenant id (must be a real tenant, > 0).
     * @param mixed $value Value to store.
     * @return void
     */
    public static function set(string $name, int $tenantid, $value): void {
        global $DB;

        if ($tenantid <= 0) {
            throw new \coding_exception('Tenant settings can only be stored for a tenant id greater than zero.');
        }
        if (!in_array($name, self::SETTING_NAMES, true)) {
            throw new \coding_exception('Unsupported tenant setting: ' . $name);
        }

        $conditions = ['tenantid' => $tenantid, 'name' => $name];
        $record = (object) array_merge($conditions, ['value' => (string) $value, 'timemodified' => time()]);

        $id = $DB->get_field(self::TABLE, 'id', $conditions);
        if ($id) {
            $record->id = $id;
            $DB->update_record(self::TABLE, $record);
        } else {
            $DB->insert_record(self::TABLE, $record);
        }
    }

    /**
     * Returns every value stored for a tenant (without site fallback).
     *
     * @param int $tenantid Tenant id.
     * @return string[] Values keyed by setting name.
     */
    public static function get_all(int $tenantid): array {
        global $DB;

        return $DB->get_records_menu(self::TABLE, ['tenantid' => $tenantid], '', 'name, value');
    }

    /**
     * Deletes every value stored for a tenant, so it falls back to the site values again.
     *
     * @param int $tenantid Tenant id.
     * @return void
     */
    public static function delete_all(int $tenantid): void {
        global $DB;

        $DB->delete_records(self::TABLE, ['tenantid' => $tenantid]);
    }
}
