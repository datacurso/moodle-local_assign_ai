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
 * Global configuration of the active Moodle Workplace tenant.
 *
 * This is the Workplace equivalent of the plugin settings page of Moodle LMS. The tenant edited is always the
 * active one: the tenant the site administrator switched to with the Workplace tenant switcher, or the own
 * tenant of a tenant administrator. The page never offers a way to pick another tenant.
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

\tool_wp\admin_externalpage::setup_page(tenant_context::get_admin_page_name());
require_capability('local/assign_ai:managetenantsettings', context_system::instance());

$tenantid = tenant_context::get_settings_tenant_id();
if (!$tenantid) {
    throw new moodle_exception('nopermissions', 'error', '', get_string('tenantsettings', 'local_assign_ai'));
}

$url = new moodle_url('/local/assign_ai/tenant_settings.php');
$PAGE->set_url($url);

$form = new tenant_settings_form($url, ['tenantid' => $tenantid]);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/admin/search.php'));
}

if ($data = $form->get_data()) {
    // The tenant is re-validated on the server: it must be the active one and manageable by the user, so a
    // tampered hidden field cannot reach another tenant.
    $target = (int) $data->tenantid;
    if (!tenant_context::is_valid_settings_target($target)) {
        throw new moodle_exception('nopermissions', 'error', '', get_string('tenantsettings', 'local_assign_ai'));
    }

    tenant_config::save_form_data($target, $data);

    redirect($url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$form->load_tenant_values();

$tenantname = tenant_context::get_manageable_tenants()[$tenantid];

echo $OUTPUT->header();
// The admin page already prints the page name as its heading, so no extra heading is rendered here.
echo $OUTPUT->notification(
    get_string('editingtenant', 'local_assign_ai', $tenantname),
    \core\output\notification::NOTIFY_INFO,
    false
);
$form->display();
echo $OUTPUT->footer();
