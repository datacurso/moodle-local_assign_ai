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
 * Helpers used by the upgrade steps of local_assign_ai.
 *
 * @package     local_assign_ai
 * @category    upgrade
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Moves the former "Enable Assign AI" switch (enableassignai) into the Datacurso AI provider.
 *
 * The site value and the value of every Moodle Workplace tenant are copied into the provider first, then the old
 * values are deleted. A value the provider already holds is never overwritten. When the provider cannot tell
 * whether it holds a value, only switches that are off are copied, because the provider default is on.
 *
 * @return void
 */
function local_assign_ai_upgrade_move_switch_to_provider(): void {
    global $DB;

    $component = 'local_assign_ai';
    $providerready = class_exists(\aiprovider_datacurso\local\plugin_settings::class);

    if ($providerready) {
        $site = get_config($component, 'enableassignai');
        if ($site !== false && $site !== '' && !local_assign_ai_upgrade_provider_has_switch(null, (bool) $site)) {
            \aiprovider_datacurso\local\plugin_settings::set_enabled($component, (bool) $site, null);
        }

        $tenantvalues = $DB->get_records_select_menu(
            'local_assign_ai_tenant_config',
            "tenantid > 0 AND name = :name",
            ['name' => 'enableassignai'],
            'tenantid ASC',
            'tenantid, value'
        );
        foreach ($tenantvalues as $tenantid => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (!local_assign_ai_upgrade_provider_has_switch((int) $tenantid, (bool) $value)) {
                \aiprovider_datacurso\local\plugin_settings::set_enabled($component, (bool) $value, (int) $tenantid);
            }
        }
    }

    unset_config('enableassignai', $component);
    $DB->delete_records('local_assign_ai_tenant_config', ['name' => 'enableassignai']);
}

/**
 * Whether the provider must keep its own value instead of receiving the migrated one.
 *
 * @param int|null $tenantid Tenant id, null for the site default.
 * @param bool $value Value about to be migrated.
 * @return bool True when the migrated value must be skipped.
 */
function local_assign_ai_upgrade_provider_has_switch(?int $tenantid, bool $value): bool {
    $name = 'plugin_enabled_local_assign_ai';

    if ($tenantid === null) {
        return get_config('aiprovider_datacurso', $name) !== false;
    }

    // Only the tenant's own row counts: get() falls back to the site value, which was just migrated.
    if (method_exists(\aiprovider_datacurso\local\tenant_config::class, 'get_stored')) {
        return \aiprovider_datacurso\local\tenant_config::get_stored('aiprovider_datacurso', $tenantid, $name) !== null;
    }

    // The provider default is on: only a switch that is off needs to be copied.
    return $value;
}
