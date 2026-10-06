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

namespace local_assign_ai\output;

/**
 * Builds the tenant selector shown to site administrators on the tenant settings page.
 *
 * @package     local_assign_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tenant_selector {
    /**
     * Creates a select that navigates to the settings of the chosen tenant as soon as it changes.
     *
     * @param string[] $tenants Tenant names keyed by id.
     * @param int $tenantid Tenant currently shown.
     * @return \url_select
     */
    public static function create(array $tenants, int $tenantid): \url_select {
        $options = [];
        foreach ($tenants as $id => $name) {
            $url = new \moodle_url('/local/assign_ai/tenant_settings.php', ['tenantid' => $id]);
            $options[$url->out(false)] = $name;
        }

        $current = new \moodle_url('/local/assign_ai/tenant_settings.php', ['tenantid' => $tenantid]);
        $select = new \url_select($options, $current->out(false), null, 'local_assign_ai_tenantselector');
        $select->set_label(get_string('tenant', 'local_assign_ai'), ['class' => 'me-2 mb-0']);

        return $select;
    }
}
