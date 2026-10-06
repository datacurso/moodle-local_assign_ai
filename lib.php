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
 * Library functions for local_assign_ai.
 *
 * @package     local_assign_ai
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Extends assignment navigation to display the "AI Review" button.
 *
 * @param settings_navigation $nav     The settings navigation object.
 * @param context             $context The current context.
 * @package local_assign_ai
 */
function local_assign_ai_extend_settings_navigation(settings_navigation $nav, context $context) {
    global $PAGE, $DB;

    // Verify that we are in a module (activity) context.
    if ($context->contextlevel != CONTEXT_MODULE) {
        return;
    }

    // Verify that it is an assignment.
    if ($PAGE->cm->modname !== 'assign') {
        return;
    }

    // Hide assign AI entries entirely when the site (or tenant) master switch is disabled.
    $tenantid = \local_assign_ai\local\tenant_context::resolve_for_current_user((int)$PAGE->cm->course);
    if (!\local_assign_ai\config\assignment_config::is_feature_enabled($tenantid)) {
        return;
    }

    // Hide assign AI entries when the assignment-level switch is disabled.
    $assignid = (int)($PAGE->cm->instance ?? 0);
    if ($assignid <= 0) {
        return;
    }

    $effectiveconfig = \local_assign_ai\config\assignment_config::get_effective($assignid);
    if (empty($effectiveconfig->enableai)) {
        return;
    }

    // Verificar permisos antes de agregar enlaces de navegación.
    if (!has_capability('local/assign_ai:review', $context)) {
        return;
    }

    // Find the module settings node.
    $modulesettings = $nav->find('modulesettings', navigation_node::TYPE_SETTING);

    if ($modulesettings) {
        $url = new moodle_url('/local/assign_ai/review.php', ['id' => $PAGE->cm->id]);

        $modulesettings->add(
            get_string('reviewwithai', 'local_assign_ai'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            'assign_ai_config',
            new pix_icon('i/settings', '')
        );

        $historyurl = new moodle_url('/local/assign_ai/history.php', ['id' => $PAGE->cm->id]);
        $modulesettings->add(
            get_string('reviewhistory', 'local_assign_ai'),
            $historyurl,
            navigation_node::TYPE_SETTING,
            null,
            'assign_ai_history',
            new pix_icon('i/report', '')
        );
    }
}

/**
 * Adds AI configuration elements to the module form.
 *
 * @param moodleform_mod $formwrapper
 * @param MoodleQuickForm $mform
 * @return void
 */
function local_assign_ai_coursemodule_standard_elements($formwrapper, $mform) {
    global $USER, $DB, $PAGE;

    if ($formwrapper->get_current()->modulename !== 'assign') {
        return;
    }

    $courseid = $formwrapper->get_course()->id ?? $formwrapper->get_current()->course ?? null;
    if (!$courseid) {
        return;
    }

    $context = context_course::instance($courseid);
    if (!has_capability('moodle/course:manageactivities', $context, $USER)) {
        return;
    }

    $tenantid = \local_assign_ai\local\tenant_context::resolve_for_current_user((int)$courseid);

    if (!\local_assign_ai\config\assignment_config::is_feature_enabled($tenantid)) {
        return;
    }

    $globalenabled = \local_assign_ai\config\assignment_config::is_global_ai_enabled($tenantid);

    $assignid = $formwrapper->get_current()->instance ?? 0;
    $config = \local_assign_ai\config\assignment_config::get_effective((int)$assignid);

    $enableai = (int)($config->enableai ?? 1);
    if (!$globalenabled) {
        $enableai = 0;
    }
    $autograde = (int)($config->autograde ?? 0);
    $graderdefault = $config->graderid ?? null;
    $usedelay = (int)($config->usedelay ?? 0);
    $delayminutes = max(1, (int)($config->delayminutes ?? 60));
    $prompt = trim((string)($config->prompt ?? ''));
    if ($prompt === '') {
        $prompt = get_string('promptdefaulttext', 'local_assign_ai');
    }

    $languages = get_string_manager()->get_list_of_languages(null, 'iso6391');
    $langoptions = [];
    foreach ($languages as $code => $name) {
        $langoptions[$code] = "$name ($code)";
    }

    $defaultlang = trim((string)get_config('core', 'lang'));
    if ($defaultlang === '') {
        $defaultlang = current_language();
    }

    $selectedlang = trim((string)($config->lang ?? ''));
    if ($selectedlang === '') {
        $selectedlang = $defaultlang;
    }

    $mform->addElement('header', 'local_assign_ai_header', get_string('aiconfigheader', 'local_assign_ai'));
    $mform->addElement(
        'select',
        'local_assign_ai_enableai',
        get_string('enableai', 'local_assign_ai'),
        [0 => get_string('no'), 1 => get_string('yes')]
    );
    $mform->addHelpButton('local_assign_ai_enableai', 'enableai', 'local_assign_ai');
    $mform->setDefault('local_assign_ai_enableai', $enableai);

    if (!$globalenabled) {
        $PAGE->requires->js_call_amd('local_assign_ai/assignment_enable_guard', 'init', [
            '#id_local_assign_ai_enableai',
            get_string('enableai_global_disabled_notice', 'local_assign_ai'),
            get_string('dismissnotification', 'core'),
        ]);
    }

    $mform->addElement(
        'select',
        'local_assign_ai_autograde',
        get_string('autograde', 'local_assign_ai'),
        [0 => get_string('no'), 1 => get_string('yes')]
    );
    $mform->addHelpButton('local_assign_ai_autograde', 'autograde', 'local_assign_ai');
    $mform->setDefault('local_assign_ai_autograde', $autograde);

    $mform->hideIf('local_assign_ai_autograde', 'local_assign_ai_enableai', 'neq', 1);

    // Use delay.
    $mform->addElement(
        'select',
        'local_assign_ai_usedelay',
        get_string('usedelay', 'local_assign_ai'),
        [0 => get_string('no'), 1 => get_string('yes')]
    );
    $mform->addHelpButton('local_assign_ai_usedelay', 'usedelay', 'local_assign_ai');
    $mform->setDefault('local_assign_ai_usedelay', $usedelay);

    $mform->hideIf('local_assign_ai_usedelay', 'local_assign_ai_enableai', 'neq', 1);

    // Delay minutes.
    $mform->addElement(
        'text',
        'local_assign_ai_delayminutes',
        get_string('delayminutes', 'local_assign_ai')
    );
    $mform->setType('local_assign_ai_delayminutes', PARAM_INT);
    $mform->addRule('local_assign_ai_delayminutes', null, 'numeric', null, 'client');
    $mform->addHelpButton('local_assign_ai_delayminutes', 'delayminutes', 'local_assign_ai');
    $mform->setDefault('local_assign_ai_delayminutes', $delayminutes);

    $mform->hideIf('local_assign_ai_delayminutes', 'local_assign_ai_enableai', 'neq', 1);

    $mform->hideIf('local_assign_ai_usedelay', 'local_assign_ai_autograde', 'neq', 1);
    $mform->hideIf('local_assign_ai_delayminutes', 'local_assign_ai_autograde', 'neq', 1);
    $mform->hideIf('local_assign_ai_grader', 'local_assign_ai_autograde', 'neq', 1);
    $mform->hideIf('local_assign_ai_grader', 'local_assign_ai_enableai', 'neq', 1);

    $mform->hideIf('local_assign_ai_delayminutes', 'local_assign_ai_usedelay', 'neq', 1);

    $eligibleusers = get_enrolled_users($context, 'mod/assign:grade');
    $options = [];
    foreach ($eligibleusers as $user) {
        $options[$user->id] = fullname($user);
    }

    if ($graderdefault && !isset($options[$graderdefault])) {
        $graderuser = $DB->get_record(
            'user',
            ['id' => $graderdefault],
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename'
        );
        if ($graderuser) {
            $options[$graderuser->id] = fullname($graderuser);
        }
    }

    if (!empty($options)) {
        \core_collator::asort($options);
    }

    $mform->addElement(
        'autocomplete',
        'local_assign_ai_grader',
        get_string('autogradegrader', 'local_assign_ai'),
        $options,
        [
            'multiple' => false,
            'tags' => false,
            'maxitems' => 1,
            'noselectionstring' => get_string('none'),
        ]
    );
    $mform->setType('local_assign_ai_grader', PARAM_INT);
    $mform->addHelpButton('local_assign_ai_grader', 'autogradegrader', 'local_assign_ai');
    if ($graderdefault) {
        $mform->setDefault('local_assign_ai_grader', (int)$graderdefault);
    }
    $mform->hideIf('local_assign_ai_grader', 'local_assign_ai_autograde', 'neq', 1);

    $mform->addElement(
        'textarea',
        'local_assign_ai_prompt',
        get_string('aiprompt', 'local_assign_ai'),
        ['rows' => 3, 'cols' => 60]
    );
    $mform->setType('local_assign_ai_prompt', PARAM_TEXT);
    $mform->addHelpButton('local_assign_ai_prompt', 'aiprompt', 'local_assign_ai');
    $mform->setDefault('local_assign_ai_prompt', $prompt);
    $mform->hideIf('local_assign_ai_prompt', 'local_assign_ai_enableai', 'neq', 1);

    $mform->addElement(
        'autocomplete',
        'local_assign_ai_lang',
        get_string('ai_response_language', 'local_assign_ai'),
        $langoptions,
        [
            'multiple' => false,
            'noselectionstring' => get_string('choosedots'),
        ]
    );
    $mform->setType('local_assign_ai_lang', PARAM_ALPHANUMEXT);
    $mform->addHelpButton('local_assign_ai_lang', 'ai_response_language', 'local_assign_ai');
    $mform->setDefault('local_assign_ai_lang', $selectedlang);
    $mform->hideIf('local_assign_ai_lang', 'local_assign_ai_enableai', 'neq', 1);
}

/**
 * Persists AI configuration when the assignment form is submitted.
 *
 * @param stdClass $data
 * @param stdClass $course
 * @return stdClass
 */
function local_assign_ai_coursemodule_edit_post_actions($data, $course) {
    global $DB, $USER;

    if ($data->modulename !== 'assign' || empty($data->instance)) {
        return $data;
    }

    $tenantid = \local_assign_ai\local\tenant_context::resolve_for_current_user((int)$course->id);

    if (!\local_assign_ai\config\assignment_config::is_feature_enabled($tenantid)) {
        return $data;
    }

    $record = $DB->get_record('local_assign_ai_config', ['assignmentid' => $data->instance]);
    $graderid = $record->graderid ?? null;
    if (property_exists($data, 'local_assign_ai_grader')) {
        $value = $data->local_assign_ai_grader;
        if (is_array($value)) {
            $value = reset($value);
        }
        if (!empty($value)) {
            $graderid = (int)$value;
        } else {
            $graderid = null;
        }
    }

    $defaults = \local_assign_ai\config\assignment_config::get_defaults($tenantid);
    $defaultenableai = $defaults->enableai;
    $defaultautograde = $defaults->autograde;
    $defaultusedelay = $defaults->usedelay;
    $defaultdelayminutes = $defaults->delayminutes;
    $defaultprompt = $defaults->prompt;
    $defaultlang = $defaults->lang;

    $enableai = property_exists($data, 'local_assign_ai_enableai')
        ? (empty($data->local_assign_ai_enableai) ? 0 : 1)
        : $defaultenableai;

    if (!\local_assign_ai\config\assignment_config::is_global_ai_enabled($tenantid)) {
        $enableai = 0;
    }

    $autograde = property_exists($data, 'local_assign_ai_autograde')
        ? (empty($data->local_assign_ai_autograde) ? 0 : 1)
        : $defaultautograde;
    $usedelay = property_exists($data, 'local_assign_ai_usedelay')
        ? (empty($data->local_assign_ai_usedelay) ? 0 : 1)
        : $defaultusedelay;
    $delayminutes = property_exists($data, 'local_assign_ai_delayminutes')
        ? max(1, (int)$data->local_assign_ai_delayminutes)
        : $defaultdelayminutes;

    $prompt = property_exists($data, 'local_assign_ai_prompt')
        ? trim((string)$data->local_assign_ai_prompt)
        : (string)($record->prompt ?? $defaultprompt);
    if ($prompt === '') {
        $prompt = $defaultprompt;
    }

    $lang = property_exists($data, 'local_assign_ai_lang')
        ? clean_param((string)$data->local_assign_ai_lang, PARAM_ALPHANUMEXT)
        : (string)($record->lang ?? $defaultlang);
    if ($lang === '') {
        $lang = $defaultlang;
    }

    if (!$enableai) {
        $autograde = 0;
        $usedelay = 0;
        $delayminutes = 0;
        $graderid = null;
    }

    if (!$autograde) {
        $usedelay = 0;
        $delayminutes = 0;
        $graderid = null;
    }

    $config = (object)[
        'assignmentid' => $data->instance,
        'enableai' => $enableai,
        'autograde' => $autograde,
        'usedelay' => $usedelay,
        'delayminutes' => $delayminutes,
        'graderid' => $graderid,
        'prompt' => $prompt,
        'lang' => $lang,
        'timemodified' => time(),
        'usermodified' => $USER->id ?? null,
    ];

    if ($record) {
        $config->id = $record->id;
        $DB->update_record('local_assign_ai_config', $config);
    } else {
        $config->timecreated = time();
        $DB->insert_record('local_assign_ai_config', $config);
    }

    return $data;
}
