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

if ($hassiteconfig) {
    $defaults = [
        'enableassignai' => 1,
        'defaultenableai' => 1,
        'defaultautograde' => 0,
        'defaultusedelay' => 0,
        'defaultdelayminutes' => 60,
        'defaultprompt' => get_string('promptdefaulttext', 'local_assign_ai'),
    ];

    foreach ($defaults as $name => $value) {
        $current = get_config('local_assign_ai', $name);
        if ($current === false || $current === '') {
            set_config($name, $value, 'local_assign_ai');
        }
    }

    $settings = new admin_settingpage('local_assign_ai_settings', new lang_string('pluginname', 'local_assign_ai'));

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_configcheckbox(
            'local_assign_ai/enableassignai',
            get_string('enableassignai', 'local_assign_ai'),
            get_string('enableassignai_desc', 'local_assign_ai'),
            1
        ));

        // Disabling this setting pauses all AI functionality at runtime through
        // assignment_config::get_effective(); stored per-assignment configuration is
        // preserved so re-enabling restores every assignment's original behaviour.
        $settings->add(new admin_setting_configcheckbox(
            'local_assign_ai/defaultenableai',
            get_string('defaultenableai', 'local_assign_ai'),
            get_string('defaultenableai_desc', 'local_assign_ai'),
            1
        ));

        $settings->add(new admin_setting_configcheckbox(
            'local_assign_ai/defaultautograde',
            get_string('defaultautograde', 'local_assign_ai'),
            get_string('defaultautograde_desc', 'local_assign_ai'),
            0
        ));

        $settings->add(new admin_setting_configcheckbox(
            'local_assign_ai/defaultusedelay',
            get_string('defaultusedelay', 'local_assign_ai'),
            get_string('defaultusedelay_desc', 'local_assign_ai'),
            0
        ));

        $settings->add(new admin_setting_configtext(
            'local_assign_ai/defaultdelayminutes',
            get_string('defaultdelayminutes', 'local_assign_ai'),
            get_string('defaultdelayminutes_desc', 'local_assign_ai'),
            60,
            PARAM_INT
        ));

        $settings->add(new admin_setting_configtextarea(
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

    $ADMIN->add('localplugins', $settings);
}
