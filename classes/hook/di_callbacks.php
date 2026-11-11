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

namespace local_assign_ai\hook;

use local_assign_ai\local\provider_pseudonymiser;
use local_assign_ai\local\userid_pseudonymiser;

/**
 * Dependency injection bindings of the plugin.
 *
 * @package     local_assign_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class di_callbacks {
    /**
     * Bind the plugin ports to their production adapters.
     *
     * @param \core\hook\di_configuration $hook The DI configuration hook.
     * @return void
     */
    public static function provide_di_configuration(\core\hook\di_configuration $hook): void {
        $hook->add_definition(
            userid_pseudonymiser::class,
            static fn(): userid_pseudonymiser => new provider_pseudonymiser()
        );
    }
}
