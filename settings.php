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
 * Plugin administration pages are defined here.
 *
 * @package     local_assign_ai
 * @category    admin
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_assign_ai\local\tenant_config;
use local_assign_ai\local\tenant_context;

// The same standard settings page serves Moodle LMS and Moodle Workplace (URL
// /admin/settings.php?section=local_assign_ai_settings). On a plain LMS the values are the site wide plugin
// configuration. On Workplace every tenant is its own platform: the tenant aware setting types read and write the
// values of the active tenant (the tenant selected with the Workplace tenant switcher, or the own tenant of a tenant
// administrator), no site defaults are seeded and nothing at site level can change all the tenants at once.
$tenantaware = tenant_context::is_tenancy_available();

if (!$tenantaware && $hassiteconfig) {
    foreach (tenant_config::SETTING_NAMES as $name) {
        $current = get_config('local_assign_ai', $name);
        if ($current === false || $current === '') {
            set_config($name, tenant_config::get_plugin_default($name), 'local_assign_ai');
        }
    }
}

// Site administrators find the page under Site administration > Plugins > Local plugins. Tenant administrators do not
// hold moodle/site:config and cannot see Local plugins, so they get it under the core 'ai' category. Every user sees
// exactly one entry; access is granted through local/assign_ai:managetenantsettings.
$parentcategory = null;
if ($hassiteconfig) {
    $parentcategory = 'localplugins';
} else if ($tenantaware && $ADMIN->locate('ai')) {
    $parentcategory = 'ai';
}

if ($parentcategory !== null) {
    $checkbox = $tenantaware ? '\local_assign_ai\admin\tenant_configcheckbox' : 'admin_setting_configcheckbox';
    $text = $tenantaware ? '\local_assign_ai\admin\tenant_configtext' : 'admin_setting_configtext';
    $textarea = $tenantaware ? '\local_assign_ai\admin\tenant_configtextarea' : 'admin_setting_configtextarea';

    $settings = new admin_settingpage(
        'local_assign_ai_settings',
        new lang_string('pluginname', 'local_assign_ai'),
        $tenantaware ? 'local/assign_ai:managetenantsettings' : 'moodle/site:config'
    );

    if ($ADMIN->fulltree) {
        if ($tenantaware) {
            $settings->add(new \local_assign_ai\admin\tenant_notice('local_assign_ai/tenantnotice'));
        }

        $settings->add(new $checkbox(
            'local_assign_ai/enableassignai',
            get_string('enableassignai', 'local_assign_ai'),
            get_string('enableassignai_desc', 'local_assign_ai'),
            1
        ));

        // Disabling this setting pauses all AI functionality at runtime through
        // assignment_config::get_effective(); stored per-assignment configuration is
        // preserved so re-enabling restores every assignment's original behaviour.
        $settings->add(new $checkbox(
            'local_assign_ai/defaultenableai',
            get_string('defaultenableai', 'local_assign_ai'),
            get_string('defaultenableai_desc', 'local_assign_ai'),
            1
        ));

        $settings->add(new $checkbox(
            'local_assign_ai/defaultautograde',
            get_string('defaultautograde', 'local_assign_ai'),
            get_string('defaultautograde_desc', 'local_assign_ai'),
            0
        ));

        $settings->add(new $checkbox(
            'local_assign_ai/defaultusedelay',
            get_string('defaultusedelay', 'local_assign_ai'),
            get_string('defaultusedelay_desc', 'local_assign_ai'),
            0
        ));

        $settings->add(new $text(
            'local_assign_ai/defaultdelayminutes',
            get_string('defaultdelayminutes', 'local_assign_ai'),
            get_string('defaultdelayminutes_desc', 'local_assign_ai'),
            60,
            PARAM_INT
        ));

        $settings->add(new $textarea(
            'local_assign_ai/defaultprompt',
            get_string('defaultprompt', 'local_assign_ai'),
            get_string('defaultprompt_desc', 'local_assign_ai'),
            get_string('promptdefaulttext', 'local_assign_ai'),
            PARAM_TEXT,
            3,
            3
        ));

        $settings->hide_if('local_assign_ai/defaultautograde', 'local_assign_ai/defaultenableai', 'eq', 0);
        $settings->hide_if('local_assign_ai/defaultusedelay', 'local_assign_ai/defaultenableai', 'eq', 0);
        $settings->hide_if('local_assign_ai/defaultdelayminutes', 'local_assign_ai/defaultenableai', 'eq', 0);
        $settings->hide_if('local_assign_ai/defaultprompt', 'local_assign_ai/defaultenableai', 'eq', 0);

        $settings->hide_if('local_assign_ai/defaultusedelay', 'local_assign_ai/defaultautograde', 'eq', 0);
        $settings->hide_if('local_assign_ai/defaultdelayminutes', 'local_assign_ai/defaultautograde', 'eq', 0);
        $settings->hide_if('local_assign_ai/defaultdelayminutes', 'local_assign_ai/defaultusedelay', 'eq', 0);
    }

    $ADMIN->add($parentcategory, $settings);
}
