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
 * Removes the plugin data of deleted users.
 *
 * @package     local_assign_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai\local;

use core_privacy\local\request\approved_contextlist;
use local_assign_ai\privacy\provider;

/**
 * Removes the plugin data of deleted users.
 *
 * The data held in an existing assignment is removed through the privacy provider, so a deleted
 * user loses exactly what a privacy deletion request removes: their own AI reviews and queue entries
 * are deleted, and their references as teacher (usermodified, graderid) are anonymised. Rows of
 * assignments that no longer exist have no context for the provider and are cleaned afterwards.
 *
 * @package     local_assign_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class user_data_cleanup {
    /**
     * Deletes or anonymises the plugin data of one user.
     *
     * @param int $userid User id.
     * @return void
     */
    public static function delete_user(int $userid): void {
        $contextids = provider::get_contexts_for_userid($userid)->get_contextids();
        if (!empty($contextids)) {
            $user = (object) ['id' => $userid];
            provider::delete_data_for_user(new approved_contextlist($user, 'local_assign_ai', $contextids));
        }
        self::delete_rows_without_context($userid);
    }

    /**
     * Applies the provider rules to the rows the provider cannot reach (assignment already deleted).
     *
     * @param int $userid User id.
     * @return void
     */
    private static function delete_rows_without_context(int $userid): void {
        global $DB;

        $DB->delete_records('local_assign_ai_pending', ['userid' => $userid]);
        $DB->set_field('local_assign_ai_pending', 'usermodified', null, ['usermodified' => $userid]);
        $DB->set_field('local_assign_ai_config', 'graderid', null, ['graderid' => $userid]);
        $DB->set_field('local_assign_ai_config', 'usermodified', null, ['usermodified' => $userid]);

        // The user id lives inside the JSON payload, so it is decoded and matched in PHP (exact, database-agnostic).
        $todelete = [];
        foreach ($DB->get_records('local_assign_ai_queue', null, '', 'id, payload') as $row) {
            $data = json_decode($row->payload);
            if ($data && (int) ($data->userid ?? 0) === $userid) {
                $todelete[] = $row->id;
            }
        }
        if (!empty($todelete)) {
            $DB->delete_records_list('local_assign_ai_queue', 'id', $todelete);
        }
    }
}
