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

use local_assign_ai\local\tenant_context;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Notice at the top of the settings page that names the tenant being edited, or warns when there is none.
 *
 * It is not a setting: nothing is stored and nothing is read.
 *
 * @package     local_assign_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tenant_notice extends \admin_setting {
    /**
     * Creates the notice.
     *
     * @param string $name Unique setting name, 'plugin/name'.
     */
    public function __construct(string $name) {
        $this->nosave = true;
        parent::__construct($name, '', '', '');
    }

    /**
     * Returns the tenant being edited.
     *
     * @return int Tenant id, 0 when there is no active tenant.
     */
    protected function get_active_tenant_id(): int {
        return tenant_context::get_current_tenant_id();
    }

    /**
     * Always returns true, there is no stored value.
     *
     * @return bool
     */
    public function get_setting() {
        return true;
    }

    /**
     * Always returns true, there is no default.
     *
     * @return bool
     */
    public function get_defaultsetting() {
        return true;
    }

    /**
     * Never writes anything.
     *
     * @param mixed $data Ignored.
     * @return string Always an empty string.
     */
    public function write_setting($data) {
        return '';
    }

    /**
     * Renders the notice.
     *
     * @param mixed $data Ignored.
     * @param string $query Ignored.
     * @return string
     */
    public function output_html($data, $query = '') {
        global $OUTPUT;

        $tenantid = $this->get_active_tenant_id();
        if ($tenantid > 0) {
            $message = get_string('editingtenant', 'local_assign_ai', s(tenant_context::get_tenant_name($tenantid)));
            $type = \core\output\notification::NOTIFY_INFO;
        } else {
            $message = get_string('notenantselected', 'local_assign_ai');
            $type = \core\output\notification::NOTIFY_WARNING;
        }

        return \html_writer::div($OUTPUT->notification($message, $type, false), 'mb-3');
    }
}
