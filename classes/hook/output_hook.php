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

use local_assign_ai\local\provider_dependency;
use core\hook\output\before_footer_html_generation;


/**
 * Hook to inject the AI script before the footer.
 *
 * @package    local_assign_ai
 * @copyright  2025 Datacurso
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class output_hook {
    /**
     * Executes before the footer HTML is generated.
     *
     * Injects the AI script when viewing the assignment grader page
     * and an AI token is provided.
     *
     * @param before_footer_html_generation $hook Hook event object.
     * @return void
     */
    public static function before_footer_html_generation(before_footer_html_generation $hook): void {
        global $PAGE;

        if (!provider_dependency::is_enabled()) {
            return;
        }

        $cmid    = optional_param('id', 0, PARAM_INT);
        $action  = optional_param('action', '', PARAM_ALPHA);
        $aitoken = optional_param('aitoken', '', PARAM_ALPHANUM);

        if ($cmid && $action === 'grader' && !empty($aitoken)) {
            $PAGE->requires->js_call_amd('local_assign_ai/inject_ai', 'init', [$aitoken]);
        }
    }
}
