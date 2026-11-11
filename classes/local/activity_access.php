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
 * Single authorization point of the plugin web services.
 *
 * @package     local_assign_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai\local;

use core_external\external_api;

/**
 * Single authorization point of the plugin web services.
 *
 * Every web service resolves the assign activity, validates its context and checks the
 * capability through this class before running any query that depends on the requested
 * user or record. Pending records are then loaded scoped to the authorised activity, and a
 * record that does not exist fails exactly like a record of another activity, so callers
 * cannot learn whether a record exists outside what they may access.
 *
 * @package     local_assign_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class activity_access {
    /**
     * Authorises the current user on an assign activity.
     *
     * Resolves the assign course module, validates its context (login, enrolment and
     * visibility), requires the capability and, when given, checks that the course id is
     * the course of the activity.
     *
     * @param int $cmid Course module id of the assign activity.
     * @param string $capability Capability required in the module context.
     * @param int|null $courseid Course id sent by the client, checked against the activity course.
     * @return \stdClass Object with the properties cm (course module), context (module context) and course.
     * @throws \moodle_exception When the course id is not the course of the activity (invalidcourseid).
     */
    public static function require_access(int $cmid, string $capability, ?int $courseid = null): \stdClass {
        $cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        external_api::validate_context($context);
        require_capability($capability, $context);

        if ($courseid !== null && $courseid !== (int) $cm->course) {
            throw new \moodle_exception('invalidcourseid');
        }

        return (object) [
            'cm' => $cm,
            'context' => $context,
            'course' => get_course($cm->course),
        ];
    }

    /**
     * Requires the current user to be allowed to grade in the authorised activity.
     *
     * Approving an AI review writes the grade, so it needs mod/assign:grade like the
     * mod_assign grader does.
     *
     * @param \stdClass $access Result of require_access().
     * @return void
     * @throws \required_capability_exception When the user may not grade the activity.
     */
    public static function require_grade_capability(\stdClass $access): void {
        require_capability('mod/assign:grade', $access->context);
    }

    /**
     * Loads a pending record of the authorised activity.
     *
     * The query is always scoped to the activity and course returned by require_access(),
     * plus the optional filters. An absent record and a record of another activity, course,
     * user or status fail with the same exception.
     *
     * @param \stdClass $access Result of require_access().
     * @param int|null $pendingid Pending record id, or null to match any id.
     * @param int|null $userid Student user id, or null to match any user.
     * @param string|null $status Record status, or null to match any status.
     * @return \stdClass The pending record.
     * @throws \moodle_exception When no record matches (invalidpendingrecord).
     */
    public static function require_pending_record(
        \stdClass $access,
        ?int $pendingid = null,
        ?int $userid = null,
        ?string $status = null
    ): \stdClass {
        global $DB;

        $conditions = [
            'assignmentid' => (int) $access->cm->id,
            'courseid' => (int) $access->cm->course,
        ];
        if ($pendingid !== null) {
            $conditions['id'] = $pendingid;
        }
        if ($userid !== null) {
            $conditions['userid'] = $userid;
        }
        if ($status !== null) {
            $conditions['status'] = $status;
        }

        $record = $DB->get_record('local_assign_ai_pending', $conditions);
        if (!$record || !self::can_access_student($access, (int) $record->userid)) {
            // A record of a student outside the groups the user may access fails like an absent one.
            throw new \moodle_exception('invalidpendingrecord', 'local_assign_ai');
        }

        return $record;
    }

    /**
     * Builds the access object of an assignment already resolved by the caller.
     *
     * @param \assign $assign The assignment.
     * @return \stdClass Object with the properties cm, context and course, as require_access() returns.
     */
    public static function from_assign(\assign $assign): \stdClass {
        return (object) [
            'cm' => $assign->get_course_module(),
            'context' => $assign->get_context(),
            'course' => $assign->get_course(),
        ];
    }

    /**
     * Returns the groups whose students the current user may access, as mod_assign does.
     *
     * Only separate groups restrict: a user with moodle/site:accessallgroups, or any user in
     * visible groups or no groups mode, may access every student.
     *
     * @param \stdClass $access Result of require_access() or from_assign().
     * @return int[]|null Allowed group ids, or null when there is no restriction.
     */
    public static function get_allowed_group_ids(\stdClass $access): ?array {
        $groupmode = groups_get_activity_groupmode($access->cm, $access->course);
        if ((int) $groupmode !== SEPARATEGROUPS || has_capability('moodle/site:accessallgroups', $access->context)) {
            return null;
        }
        return array_map('intval', array_keys(groups_get_activity_allowed_groups($access->cm)));
    }

    /**
     * Whether the current user may access the AI review of a student, by group membership.
     *
     * @param \stdClass $access Result of require_access() or from_assign().
     * @param int $userid Student user id.
     * @return bool
     */
    public static function can_access_student(\stdClass $access, int $userid): bool {
        global $DB;

        $groupids = self::get_allowed_group_ids($access);
        if ($groupids === null) {
            return true;
        }
        if (empty($groupids)) {
            return false;
        }
        [$insql, $params] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED);
        $params['userid'] = $userid;
        return $DB->record_exists_select('groups_members', "userid = :userid AND groupid $insql", $params);
    }

    /**
     * Requires the current user to be allowed to access the AI review of a student.
     *
     * @param \stdClass $access Result of require_access().
     * @param int $userid Student user id.
     * @return void
     * @throws \moodle_exception When the student is outside the allowed groups (invalidpendingrecord).
     */
    public static function require_student_access(\stdClass $access, int $userid): void {
        if (!self::can_access_student($access, $userid)) {
            throw new \moodle_exception('invalidpendingrecord', 'local_assign_ai');
        }
    }

    /**
     * Returns an SQL condition that keeps only the students the current user may access.
     *
     * @param \stdClass $access Result of require_access() or from_assign().
     * @param string $useridfield Qualified student user id column, e.g. "p.userid".
     * @param string $prefix Prefix of the generated named parameters.
     * @return array [SQL condition, named parameters]
     */
    public static function get_student_filter_sql(\stdClass $access, string $useridfield, string $prefix = 'laagrp'): array {
        global $DB;

        $groupids = self::get_allowed_group_ids($access);
        if ($groupids === null) {
            return ['1 = 1', []];
        }
        if (empty($groupids)) {
            return ['1 = 0', []];
        }
        [$insql, $params] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, $prefix);
        return ["{$useridfield} IN (SELECT gm.userid FROM {groups_members} gm WHERE gm.groupid {$insql})", $params];
    }
}
