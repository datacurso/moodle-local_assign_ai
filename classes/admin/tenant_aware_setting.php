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

namespace local_assign_ai\admin;

use local_assign_ai\local\tenant_config;
use local_assign_ai\local\tenant_context;

/**
 * Makes a standard admin setting read and write the values of the active Moodle Workplace tenant.
 *
 * The setting keeps its standard look and behaviour; only its storage changes. Under Workplace the value is read
 * from, and written to, {local_assign_ai_tenant_config} for the active tenant (the tenant the site administrator
 * switched to, or the own tenant of a tenant administrator). The site wide plugin configuration is never read and
 * tenant 0 is never written. On a plain Moodle LMS (no tool_tenant) the trait does nothing and the setting stores
 * its value in the site wide plugin configuration as usual.
 *
 * @package     local_assign_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait tenant_aware_setting {
    /**
     * Returns the tenant being edited.
     *
     * @return int Tenant id, 0 when there is no active tenant.
     */
    protected function get_active_tenant_id(): int {
        return tenant_context::get_current_tenant_id();
    }

    /**
     * Reads the value of the active tenant, or the plugin default when it has none.
     *
     * @param string $name Setting name.
     * @return mixed The value, or null when the setting is not defined anywhere.
     */
    public function config_read($name) {
        if (!tenant_context::is_tenancy_available()) {
            return parent::config_read($name);
        }

        $value = tenant_config::get($name, $this->get_active_tenant_id());
        return $value === false ? null : $value;
    }

    /**
     * Refuses the save when there is no active tenant or the user cannot manage it.
     *
     * Core applies every default setting on install through write_setting(); that call is ignored because a
     * tenant starts with the plugin defaults and nothing must be seeded for a tenant.
     *
     * @param mixed $data Submitted value.
     * @return string Empty string when saved (or ignored), the error message otherwise.
     */
    public function write_setting($data) {
        if (!tenant_context::is_tenancy_available()) {
            return parent::write_setting($data);
        }
        if ($this->is_applying_defaults()) {
            return '';
        }

        $tenantid = $this->get_active_tenant_id();
        if ($tenantid <= 0) {
            return get_string('notenantselected', 'local_assign_ai');
        }
        if (!tenant_context::can_manage_tenant_settings($tenantid)) {
            return get_string('nopermissions', 'error', get_string('pluginname', 'local_assign_ai'));
        }

        return parent::write_setting($data);
    }

    /**
     * Stores the value for the active tenant and logs the change.
     *
     * @param string $name Setting name.
     * @param mixed $value Value, converted to string unless null.
     * @return bool
     */
    public function config_write($name, $value) {
        if (!tenant_context::is_tenancy_available()) {
            return parent::config_write($name, $value);
        }
        if ($this->nosave) {
            return true;
        }

        // The site (tenant 0) has no values of its own under Workplace.
        $tenantid = $this->get_active_tenant_id();
        if ($tenantid <= 0) {
            return false;
        }

        $oldvalue = tenant_config::get($name, $tenantid);
        $oldvalue = ($oldvalue === false) ? null : (string) $oldvalue;
        $value = is_null($value) ? null : (string) $value;
        if ($oldvalue === $value) {
            return true;
        }

        tenant_config::set($name, $tenantid, $value ?? '');
        $this->add_to_config_log($name, $oldvalue, $value);

        return true;
    }

    /**
     * Logs the change naming the tenant, so the config log tells which tenant was modified.
     *
     * @param string $name Setting name.
     * @param string|null $oldvalue Previous value.
     * @param string|null $value New value.
     */
    protected function add_to_config_log($name, $oldvalue, $value) {
        if (!tenant_context::is_tenancy_available()) {
            parent::add_to_config_log($name, $oldvalue, $value);
            return;
        }

        add_to_config_log('tenant' . $this->get_active_tenant_id() . '/' . $name, $oldvalue, $value, $this->plugin);
    }

    /**
     * Whether core is applying the default settings (install), which must never reach a tenant.
     *
     * @return bool
     */
    private function is_applying_defaults(): bool {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $frame) {
            if (($frame['function'] ?? '') === 'admin_apply_default_settings') {
                return true;
            }
        }

        return false;
    }
}
