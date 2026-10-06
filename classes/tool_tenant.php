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

namespace local_assign_ai;

/**
 * Callbacks used by tool_tenant (Moodle Workplace). Ignored on a plain Moodle LMS.
 *
 * @package     local_assign_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_tenant {
    /**
     * Capabilities of this plugin that are allowed for the "Tenant administrator" role.
     *
     * @return array
     */
    public static function get_tenant_admin_capabilities(): array {
        return [
            'local/assign_ai:managetenantsettings' => CAP_ALLOW,
        ];
    }
}
