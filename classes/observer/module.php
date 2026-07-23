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

namespace local_assign_ai\observer;

defined('MOODLE_INTERNAL') || die();

use core\event\course_module_deleted;

require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Observer for module events.
 *
 * @package    local_assign_ai
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class module {
    /**
     * Deletes AI pending records when the teacher deletes the assignment activity.
     *
     * @param course_module_deleted $event
     * @return void
     */
    public static function course_module_deleted(course_module_deleted $event) {
        global $DB;

        try {
            if ($event->other['modulename'] !== 'assign') {
                return;
            }

            $cmid = $event->contextinstanceid;

            if (!$cmid) {
                return;
            }

            if (empty($event->other['instanceid'])) {
                return;
            }

            $assignid = (int) $event->other['instanceid'];

            $DB->delete_records('local_assign_ai_config', [
                'assignmentid' => $assignid,
            ]);

            $DB->delete_records('local_assign_ai_pending', [
                'assignmentid' => $cmid,
            ]);

            // Only remove queue rows for THIS activity, not the whole plugin queue.
            // The cmid lives inside the JSON payload, so pre-filter with LIKE and then
            // verify the exact integer match in PHP (avoids 5-vs-50 LIKE false positives).
            $candidates = $DB->get_records_select(
                'local_assign_ai_queue',
                $DB->sql_like('payload', '?'),
                ['%"cmid":' . $cmid . '%']
            );
            $todelete = [];
            foreach ($candidates as $row) {
                $data = json_decode($row->payload);
                if ($data && (int) ($data->cmid ?? 0) === (int) $cmid) {
                    $todelete[] = $row->id;
                }
            }
            if (!empty($todelete)) {
                $DB->delete_records_list('local_assign_ai_queue', 'id', $todelete);
            }
        } catch (\Exception $e) {
            debugging('Exception in course_module_deleted observer: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
