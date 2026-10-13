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

namespace local_assign_ai\form;

use local_assign_ai\config\assignment_config;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form with the tenant level defaults of the plugin (Moodle Workplace).
 *
 * @package     local_assign_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tenant_settings_form extends \moodleform {
    /**
     * Form definition.
     *
     * Custom data: 'tenants' (array of tenant names keyed by id the user may edit) and 'tenantid'.
     */
    public function definition(): void {
        $mform = $this->_form;
        $tenants = $this->_customdata['tenants'];
        $tenantid = (int) $this->_customdata['tenantid'];

        $mform->addElement('hidden', 'tenantid', $tenantid);
        $mform->setType('tenantid', PARAM_INT);

        $mform->addElement('static', 'tenantname', get_string('tenant', 'local_assign_ai'), s($tenants[$tenantid] ?? ''));

        $checkboxes = ['enableassignai', 'defaultenableai', 'defaultautograde', 'defaultusedelay'];
        foreach ($checkboxes as $name) {
            $mform->addElement(
                'advcheckbox',
                $name,
                get_string($name, 'local_assign_ai'),
                get_string($name . '_desc', 'local_assign_ai')
            );
        }

        $mform->addElement('text', 'defaultdelayminutes', get_string('defaultdelayminutes', 'local_assign_ai'), ['size' => 6]);
        $mform->setType('defaultdelayminutes', PARAM_INT);
        $mform->addElement('static', 'defaultdelayminutes_desc', '', get_string('defaultdelayminutes_desc', 'local_assign_ai'));

        $mform->addElement(
            'textarea',
            'defaultprompt',
            get_string('defaultprompt', 'local_assign_ai'),
            ['rows' => 6, 'cols' => 60]
        );
        $mform->setType('defaultprompt', PARAM_TEXT);
        $mform->addElement('static', 'defaultprompt_desc', '', get_string('defaultprompt_desc', 'local_assign_ai'));

        $mform->hideIf('defaultautograde', 'defaultenableai', 'eq', 0);
        $mform->hideIf('defaultusedelay', 'defaultenableai', 'eq', 0);
        $mform->hideIf('defaultdelayminutes', 'defaultenableai', 'eq', 0);
        $mform->hideIf('defaultdelayminutes_desc', 'defaultenableai', 'eq', 0);
        $mform->hideIf('defaultprompt_desc', 'defaultenableai', 'eq', 0);
        $mform->hideIf('defaultdelayminutes_desc', 'defaultautograde', 'eq', 0);
        $mform->hideIf('defaultdelayminutes_desc', 'defaultusedelay', 'eq', 0);
        $mform->hideIf('defaultprompt', 'defaultenableai', 'eq', 0);
        $mform->hideIf('defaultusedelay', 'defaultautograde', 'eq', 0);
        $mform->hideIf('defaultdelayminutes', 'defaultautograde', 'eq', 0);
        $mform->hideIf('defaultdelayminutes', 'defaultusedelay', 'eq', 0);

        $this->add_action_buttons();
    }

    /**
     * Validation.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        // The delay is only visible (and posted) while AI, autograde and the delay are all switched on.
        $delayinuse = !empty($data['defaultenableai']) && !empty($data['defaultautograde']) && !empty($data['defaultusedelay']);
        if ($delayinuse && (int) ($data['defaultdelayminutes'] ?? 0) < 1) {
            $errors['defaultdelayminutes'] = get_string('err_numeric', 'form');
        }

        return $errors;
    }

    /**
     * Loads the values stored for the tenant, falling back to the site defaults.
     */
    public function load_tenant_values(): void {
        $tenantid = (int) $this->_customdata['tenantid'];
        $defaults = assignment_config::get_defaults($tenantid);

        $this->set_data([
            'tenantid' => $tenantid,
            'enableassignai' => assignment_config::is_feature_enabled($tenantid) ? 1 : 0,
            'defaultenableai' => $defaults->enableai,
            'defaultautograde' => $defaults->autograde,
            'defaultusedelay' => $defaults->usedelay,
            'defaultdelayminutes' => $defaults->delayminutes,
            'defaultprompt' => $defaults->prompt,
        ]);
    }
}
