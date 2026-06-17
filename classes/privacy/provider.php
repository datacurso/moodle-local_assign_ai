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

namespace local_assign_ai\privacy;

use context;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use stdClass;

/**
 * Privacy subsystem implementation for local_assign_ai.
 *
 * @package    local_assign_ai
 * @copyright  2025 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    core_userlist_provider {
    /**
     * Describe the types of personal data stored by this plugin.
     *
     * @param collection $collection The initialized collection to add items to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_assign_ai_pending',
            [
                'courseid'         => 'privacy:metadata:local_assign_ai_pending:courseid',
                'assignmentid'     => 'privacy:metadata:local_assign_ai_pending:assignmentid',
                'userid'           => 'privacy:metadata:local_assign_ai_pending:userid',
                'title'            => 'privacy:metadata:local_assign_ai_pending:title',
                'message'          => 'privacy:metadata:local_assign_ai_pending:message',
                'grade'            => 'privacy:metadata:local_assign_ai_pending:grade',
                'rubric_response'  => 'privacy:metadata:local_assign_ai_pending:rubric_response',
                'errormessage'     => 'privacy:metadata:local_assign_ai_pending:errormessage',
                'status'           => 'privacy:metadata:local_assign_ai_pending:status',
                'approval_token'   => 'privacy:metadata:local_assign_ai_pending:approval_token',
            ],
            'privacy:metadata:local_assign_ai_pending'
        );

        return $collection;
    }

    /**
     * Get contexts containing user data for a specific user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        global $DB;
        if ($DB->record_exists('local_assign_ai_pending', ['userid' => $userid])) {
            $contextlist->add_user_context($userid);
        }

        return $contextlist;
    }

    /**
     * Get users who have data within a given context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();

        if (!$context instanceof \context_user) {
            return;
        }

        $userid = $context->instanceid;
        if ($DB->record_exists('local_assign_ai_pending', ['userid' => $userid])) {
            $userlist->add_user($userid);
        }
    }

    /**
     * Export all user data for the specified user and contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $user = $contextlist->get_user();
        $context = \context_user::instance($user->id);

        $records = $DB->get_records('local_assign_ai_pending', ['userid' => $user->id]);

        if (empty($records)) {
            return;
        }

        writer::with_context($context)->export_data(
            [get_string('privacy:metadata:local_assign_ai_pending', 'local_assign_ai')],
            (object)['entries' => array_values($records)]
        );
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param context $context
     */
    public static function delete_data_for_all_users_in_context(context $context) {
        global $DB;

        if ($context->contextlevel == CONTEXT_USER) {
            $DB->delete_records('local_assign_ai_pending', ['userid' => $context->instanceid]);
        }
    }

    /**
     * Delete all data for the specified user.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_USER) {
                $DB->delete_records('local_assign_ai_pending', ['userid' => $context->instanceid]);
            }
        }
    }

    /**
     * Delete data for multiple users in a given context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        if ($context instanceof \context_user) {
            $DB->delete_records('local_assign_ai_pending', ['userid' => $context->instanceid]);
        }
    }
}
