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
 * Hook definitions for the local_assign_ai plugin.
 *
 * This file declares the list of hook callbacks that are registered by this plugin.
 *
 * @package    local_assign_ai
 * @copyright  2025 Datacurso
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core\hook\di_configuration::class,
        'callback' => 'local_assign_ai\hook\di_callbacks::provide_di_configuration',
    ],
    [
        'hook' => \core\hook\output\before_footer_html_generation::class,
        'callback' => 'local_assign_ai\hook\output_hook::before_footer_html_generation',
        'priority' => 900,
    ],
    [
        'hook' => \core\hook\output\before_standard_head_html_generation::class,
        'callback' => 'local_assign_ai\hook\before_standard_head_html_generation::before_standard_html_head',
    ],
];
