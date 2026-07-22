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
                'submissionid'     => 'privacy:metadata:local_assign_ai_pending:submissionid',
                'attemptnumber'    => 'privacy:metadata:local_assign_ai_pending:attemptnumber',
                'submissionmodified' => 'privacy:metadata:local_assign_ai_pending:submissionmodified',
                'edited'           => 'privacy:metadata:local_assign_ai_pending:edited',
                'title'            => 'privacy:metadata:local_assign_ai_pending:title',
                'message'          => 'privacy:metadata:local_assign_ai_pending:message',
                'grade'            => 'privacy:metadata:local_assign_ai_pending:grade',
                'rubric_response'  => 'privacy:metadata:local_assign_ai_pending:rubric_response',
                'assessment_guide_response' => 'privacy:metadata:local_assign_ai_pending:assessment_guide_response',
                'errormessage'     => 'privacy:metadata:local_assign_ai_pending:errormessage',
                'status'           => 'privacy:metadata:local_assign_ai_pending:status',
                'approval_token'   => 'privacy:metadata:local_assign_ai_pending:approval_token',
            ],
            'privacy:metadata:local_assign_ai_pending'
        );

        $collection->add_database_table(
            'local_assign_ai_config',
            [
                'assignmentid' => 'privacy:metadata:local_assign_ai_config:assignmentid',
                'graderid'     => 'privacy:metadata:local_assign_ai_config:graderid',
                'usermodified' => 'privacy:metadata:local_assign_ai_config:usermodified',
            ],
            'privacy:metadata:local_assign_ai_config'
        );

        $collection->add_database_table(
            'local_assign_ai_queue',
            [
                'payload' => 'privacy:metadata:local_assign_ai_queue:payload',
            ],
            'privacy:metadata:local_assign_ai_queue'
        );

        // The submission data is sent to an external AI provider for grading/feedback.
        $collection->add_external_location_link(
            'datacurso_ai',
            [
                'userid' => 'privacy:metadata:datacurso_ai:userid',
                'student_name' => 'privacy:metadata:datacurso_ai:student_name',
                'submission_text' => 'privacy:metadata:datacurso_ai:submission_text',
                'submission_files' => 'privacy:metadata:datacurso_ai:submission_files',
                'course_activity' => 'privacy:metadata:datacurso_ai:course_activity',
            ],
            'privacy:metadata:datacurso_ai'
        );

        return $collection;
    }

    /**
     * Get contexts containing user data for a specific user.
     *
     * All personal data is scoped to the assignment (course module) it belongs to,
     * so the relevant contexts are module contexts, not the user context.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        $assignmodule = (int) $DB->get_field('modules', 'id', ['name' => 'assign']);

        // Pending records: pending.assignmentid holds the cmid.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {local_assign_ai_pending} p
               JOIN {course_modules} cm ON cm.id = p.assignmentid
               JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :modlevel
              WHERE p.userid = :uid1 OR p.usermodified = :uid2",
            ['modlevel' => CONTEXT_MODULE, 'uid1' => $userid, 'uid2' => $userid]
        );

        // Config records: config.assignmentid holds the assign instance id.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {local_assign_ai_config} c
               JOIN {course_modules} cm ON cm.instance = c.assignmentid AND cm.module = :assignmod
               JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :modlevel
              WHERE c.graderid = :uid1 OR c.usermodified = :uid2",
            ['assignmod' => $assignmodule, 'modlevel' => CONTEXT_MODULE, 'uid1' => $userid, 'uid2' => $userid]
        );

        // Queue rows carry the userid/cmid inside the JSON payload.
        $cmids = self::queue_cmids_for_user($userid);
        if (!empty($cmids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);
            $contextlist->add_from_sql(
                "SELECT ctx.id
                   FROM {context} ctx
                  WHERE ctx.contextlevel = :modlevel AND ctx.instanceid $insql",
                ['modlevel' => CONTEXT_MODULE] + $inparams
            );
        }

        return $contextlist;
    }

    /**
     * Get users who have data within a given context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }

        $cmid = (int) $context->instanceid;
        $instanceid = self::instanceid_for_cmid($cmid);

        // Pending: student (userid) and the teacher who last edited (usermodified).
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_assign_ai_pending} WHERE assignmentid = :cmid',
            ['cmid' => $cmid]);
        $userlist->add_from_sql('usermodified',
            'SELECT usermodified FROM {local_assign_ai_pending} WHERE assignmentid = :cmid AND usermodified IS NOT NULL',
            ['cmid' => $cmid]);

        // Config: grader and last modifier.
        if ($instanceid) {
            $userlist->add_from_sql('graderid',
                'SELECT graderid FROM {local_assign_ai_config} WHERE assignmentid = :aid AND graderid IS NOT NULL',
                ['aid' => $instanceid]);
            $userlist->add_from_sql('usermodified',
                'SELECT usermodified FROM {local_assign_ai_config} WHERE assignmentid = :aid AND usermodified IS NOT NULL',
                ['aid' => $instanceid]);
        }

        // Queue: userid embedded in the JSON payload.
        foreach (self::queue_payloads() as $data) {
            if ((int) ($data->cmid ?? 0) === $cmid && !empty($data->userid)) {
                $userlist->add_user((int) $data->userid);
            }
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

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cmid = (int) $context->instanceid;
            $instanceid = self::instanceid_for_cmid($cmid);

            $pending = $DB->get_records_select(
                'local_assign_ai_pending',
                'assignmentid = :cmid AND (userid = :uid1 OR usermodified = :uid2)',
                ['cmid' => $cmid, 'uid1' => $user->id, 'uid2' => $user->id]
            );
            if (!empty($pending)) {
                writer::with_context($context)->export_data(
                    [get_string('privacy:metadata:local_assign_ai_pending', 'local_assign_ai')],
                    (object) ['entries' => array_values($pending)]
                );
            }

            if ($instanceid) {
                $config = $DB->get_records_select(
                    'local_assign_ai_config',
                    'assignmentid = :aid AND (graderid = :uid1 OR usermodified = :uid2)',
                    ['aid' => $instanceid, 'uid1' => $user->id, 'uid2' => $user->id]
                );
                if (!empty($config)) {
                    writer::with_context($context)->export_data(
                        [get_string('privacy:metadata:local_assign_ai_config', 'local_assign_ai')],
                        (object) ['entries' => array_values($config)]
                    );
                }
            }

            $queue = [];
            foreach (self::queue_payloads(true) as $row) {
                $data = json_decode($row->payload);
                if ($data && (int) ($data->cmid ?? 0) === $cmid && (int) ($data->userid ?? 0) === (int) $user->id) {
                    $queue[] = $row;
                }
            }
            if (!empty($queue)) {
                writer::with_context($context)->export_data(
                    [get_string('privacy:metadata:local_assign_ai_queue', 'local_assign_ai')],
                    (object) ['entries' => array_values($queue)]
                );
            }
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param context $context
     */
    public static function delete_data_for_all_users_in_context(context $context) {
        global $DB;

        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }

        $cmid = (int) $context->instanceid;
        $instanceid = self::instanceid_for_cmid($cmid);

        $DB->delete_records('local_assign_ai_pending', ['assignmentid' => $cmid]);
        if ($instanceid) {
            $DB->delete_records('local_assign_ai_config', ['assignmentid' => $instanceid]);
        }
        self::delete_queue_rows($cmid);
    }

    /**
     * Delete all data for the specified user across the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_module) {
                self::delete_users_in_module($context, [(int) $user->id]);
            }
        }
    }

    /**
     * Delete data for multiple users in a given context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if ($context instanceof \context_module) {
            self::delete_users_in_module($context, array_map('intval', $userlist->get_userids()));
        }
    }

    /**
     * Deletes/anonymises the given users' data within one module context.
     *
     * The student's own pending records are removed. When a user only appears as an
     * actor (usermodified/graderid), the reference is nulled rather than deleting the
     * underlying record, which belongs to the student or the assignment.
     *
     * @param \context_module $context The module context.
     * @param int[] $userids User ids to remove.
     * @return void
     */
    private static function delete_users_in_module(\context_module $context, array $userids): void {
        global $DB;

        if (empty($userids)) {
            return;
        }

        $cmid = (int) $context->instanceid;
        $instanceid = self::instanceid_for_cmid($cmid);
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        // Student-owned pending rows are deleted.
        $DB->delete_records_select('local_assign_ai_pending', "assignmentid = :cmid AND userid $insql",
            ['cmid' => $cmid] + $inparams);
        // Teacher references on remaining pending rows are anonymised.
        $DB->set_field_select('local_assign_ai_pending', 'usermodified', null,
            "assignmentid = :cmid AND usermodified $insql", ['cmid' => $cmid] + $inparams);

        if ($instanceid) {
            $DB->set_field_select('local_assign_ai_config', 'graderid', null,
                "assignmentid = :aid AND graderid $insql", ['aid' => $instanceid] + $inparams);
            $DB->set_field_select('local_assign_ai_config', 'usermodified', null,
                "assignmentid = :aid AND usermodified $insql", ['aid' => $instanceid] + $inparams);
        }

        self::delete_queue_rows($cmid, $userids);
    }

    /**
     * Resolves the assign instance id for a course-module id.
     *
     * @param int $cmid Course module id.
     * @return int|null Instance id, or null if the module no longer exists.
     */
    private static function instanceid_for_cmid(int $cmid): ?int {
        $cm = get_coursemodule_from_id('assign', $cmid, 0, false, IGNORE_MISSING);
        return $cm ? (int) $cm->instance : null;
    }

    /**
     * Returns the decoded payloads of all queue rows (or the raw rows).
     *
     * @param bool $raw When true, returns the raw DB rows instead of decoded payloads.
     * @return array
     */
    private static function queue_payloads(bool $raw = false): array {
        global $DB;
        $rows = $DB->get_records('local_assign_ai_queue');
        if ($raw) {
            return $rows;
        }
        $out = [];
        foreach ($rows as $row) {
            $data = json_decode($row->payload);
            if ($data) {
                $out[] = $data;
            }
        }
        return $out;
    }

    /**
     * Returns the cmids referenced by queue rows for a given user.
     *
     * @param int $userid User id.
     * @return int[]
     */
    private static function queue_cmids_for_user(int $userid): array {
        $cmids = [];
        foreach (self::queue_payloads() as $data) {
            if ((int) ($data->userid ?? 0) === $userid && !empty($data->cmid)) {
                $cmids[(int) $data->cmid] = (int) $data->cmid;
            }
        }
        return array_values($cmids);
    }

    /**
     * Deletes queue rows for a cmid, optionally restricted to given users.
     *
     * @param int $cmid Course module id.
     * @param int[]|null $userids When given, only rows for these users are removed.
     * @return void
     */
    private static function delete_queue_rows(int $cmid, ?array $userids = null): void {
        global $DB;
        $ids = [];
        foreach (self::queue_payloads(true) as $row) {
            $data = json_decode($row->payload);
            if (!$data || (int) ($data->cmid ?? 0) !== $cmid) {
                continue;
            }
            if ($userids !== null && !in_array((int) ($data->userid ?? 0), $userids, true)) {
                continue;
            }
            $ids[] = $row->id;
        }
        if (!empty($ids)) {
            $DB->delete_records_list('local_assign_ai_queue', 'id', $ids);
        }
    }
}
