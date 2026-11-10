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

/**
 * Removes the plugin data of deleted assignments.
 *
 * @package     local_assign_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai\local;

/**
 * Removes the plugin data (pending reviews, configuration and delayed queue) of deleted assignments.
 *
 * Identifier conventions: local_assign_ai_pending.assignmentid is the course module id,
 * local_assign_ai_config.assignmentid is the assign instance id, and the queue keeps the
 * course module id inside its JSON payload.
 *
 * @package     local_assign_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class activity_data_cleanup {
    /**
     * Deletes the plugin data of one assignment.
     *
     * @param int $cmid Course module id of the assignment.
     * @param int $assignid Assign instance id.
     * @return void
     */
    public static function delete_activity(int $cmid, int $assignid): void {
        global $DB;

        $DB->delete_records('local_assign_ai_config', ['assignmentid' => $assignid]);
        $DB->delete_records('local_assign_ai_pending', ['assignmentid' => $cmid]);
        self::delete_queue_rows([$cmid]);
    }

    /**
     * Deletes the plugin data of every assignment of a course.
     *
     * Must run before the course modules are removed, because the configuration is keyed by the
     * assign instance and the queue by the course module id. Pending rows are also removed by
     * course id, so rows of activities already gone do not survive the course.
     *
     * @param int $courseid Course id.
     * @return void
     */
    public static function delete_course(int $courseid): void {
        global $DB;

        $cms = $DB->get_records_sql(
            'SELECT cm.id, cm.instance
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
              WHERE cm.course = :courseid',
            ['modname' => 'assign', 'courseid' => $courseid]
        );
        $assignids = $DB->get_fieldset_select('assign', 'id', 'course = :courseid', ['courseid' => $courseid]);

        if (!empty($assignids)) {
            $DB->delete_records_list('local_assign_ai_config', 'assignmentid', $assignids);
        }
        if (!empty($cms)) {
            $DB->delete_records_list('local_assign_ai_pending', 'assignmentid', array_keys($cms));
        }
        $DB->delete_records('local_assign_ai_pending', ['courseid' => $courseid]);
        self::delete_queue_rows(array_map('intval', array_keys($cms)));
    }

    /**
     * Deletes the queue rows of the given course modules.
     *
     * The course module id lives inside the JSON payload, so it is decoded and matched in PHP:
     * database-agnostic (no LIKE) and exact (avoids 5-vs-50 false positives). The queue only
     * holds delayed submissions awaiting processing, so it stays small.
     *
     * @param int[] $cmids Course module ids.
     * @return void
     */
    private static function delete_queue_rows(array $cmids): void {
        global $DB;

        if (empty($cmids)) {
            return;
        }
        $todelete = [];
        foreach ($DB->get_records('local_assign_ai_queue', null, '', 'id, payload') as $row) {
            $data = json_decode($row->payload);
            if ($data && in_array((int) ($data->cmid ?? 0), $cmids, true)) {
                $todelete[] = $row->id;
            }
        }
        if (!empty($todelete)) {
            $DB->delete_records_list('local_assign_ai_queue', 'id', $todelete);
        }
    }
}
