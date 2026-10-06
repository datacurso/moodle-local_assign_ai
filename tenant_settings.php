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
 * Tenant level defaults of the plugin (Moodle Workplace).
 *
 * Tenant administrators edit their own tenant; site administrators can pick any tenant.
 *
 * @package     local_assign_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_assign_ai\form\tenant_settings_form;
use local_assign_ai\local\tenant_config;
use local_assign_ai\local\tenant_context;

require_login(null, false);

if (!tenant_context::is_tenancy_available() || !class_exists('\tool_wp\admin_externalpage')) {
    throw new moodle_exception('tenantsettingsunavailable', 'local_assign_ai');
}

\tool_wp\admin_externalpage::setup_page('local_assign_ai_tenantsettings');
require_capability('local/assign_ai:managetenantsettings', context_system::instance());

$tenants = tenant_context::get_manageable_tenants();
if (!$tenants) {
    throw new moodle_exception('nopermissions', 'error', '', get_string('tenantsettings', 'local_assign_ai'));
}

$tenantid = optional_param('tenantid', 0, PARAM_INT);
if ($tenantid && !tenant_context::can_manage_tenant_settings($tenantid)) {
    throw new moodle_exception('nopermissions', 'error', '', get_string('tenantsettings', 'local_assign_ai'));
}
if (!$tenantid) {
    $own = tenant_context::get_current_tenant_id();
    $tenantid = isset($tenants[$own]) ? $own : (int) array_key_first($tenants);
}

$url = new moodle_url('/local/assign_ai/tenant_settings.php', ['tenantid' => $tenantid]);
$PAGE->set_url($url);

$form = new tenant_settings_form($url, ['tenants' => $tenants, 'tenantid' => $tenantid]);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/admin/search.php'));
}

if ($data = $form->get_data()) {
    // The tenant is re-validated on the server: a tampered hidden field cannot reach another tenant.
    $target = (int) $data->tenantid;
    if (!tenant_context::can_manage_tenant_settings($target)) {
        throw new moodle_exception('nopermissions', 'error', '', get_string('tenantsettings', 'local_assign_ai'));
    }

    foreach (tenant_config::SETTING_NAMES as $name) {
        $value = $data->$name ?? '';
        if ($name === 'defaultdelayminutes') {
            $value = max(1, (int) $value);
        }
        tenant_config::set($name, $target, $value);
    }

    redirect(
        new moodle_url('/local/assign_ai/tenant_settings.php', ['tenantid' => $target]),
        get_string('changessaved'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$form->load_tenant_values();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('tenantsettings', 'local_assign_ai'));
if (count($tenants) > 1) {
    echo $OUTPUT->single_select(
        new moodle_url('/local/assign_ai/tenant_settings.php'),
        'tenantid',
        $tenants,
        $tenantid,
        null,
        null,
        ['label' => get_string('tenant', 'local_assign_ai')]
    );
}
$form->display();
echo $OUTPUT->footer();
