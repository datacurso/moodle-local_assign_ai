<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_assign_ai\hook;

use core_course\hook\before_course_deleted;
use local_assign_ai\local\activity_data_cleanup;

/**
 * Removes the plugin data of a course that is being deleted.
 *
 * Course deletion removes the course modules without triggering course_module_deleted, so the
 * plugin cleans its rows here, before the modules are gone (LAA-AUD-008).
 *
 * @package     local_assign_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_deletion {
    /**
     * Deletes the pending reviews, configuration and queue rows of the course assignments.
     *
     * @param before_course_deleted $hook The hook.
     * @return void
     */
    public static function before_course_deleted(before_course_deleted $hook): void {
        try {
            activity_data_cleanup::delete_course((int) $hook->course->id);
        } catch (\Exception $e) {
            // Never block the course deletion; report the problem to developers.
            debugging('Exception in local_assign_ai before_course_deleted hook: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
