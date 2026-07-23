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
 * Data generator for local_assign_ai.
 *
 * @package     local_assign_ai
 * @category    test
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Data generator class for local_assign_ai.
 *
 * Identifier conventions used by the plugin tables (they differ on purpose):
 *  - local_assign_ai_pending.assignmentid stores the COURSE MODULE id (cmid).
 *  - local_assign_ai_config.assignmentid stores the assign INSTANCE id ({assign}.id).
 *
 * @package     local_assign_ai
 * @category    test
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_assign_ai_generator extends component_generator_base {
    /** @var string[] Valid statuses for a pending record. */
    protected const VALID_STATUSES = [
        'initial',
        'queued',
        'processing',
        'pending',
        'approve',
        'rejected',
        'failed',
        'superseded',
    ];

    /**
     * Create a record in local_assign_ai_pending.
     *
     * Note: the "assignmentid" field of local_assign_ai_pending is the COURSE MODULE id (cmid)
     * of the assign activity, not the assign instance id.
     *
     * Accepted fields (human friendly references are resolved):
     *  - assignmentid (int): cmid of the assign activity. Alternatively "assign" (activity idnumber).
     *  - userid (int): student user id. Alternatively "user" (username).
     *  - courseid (int): defaults to the course of the course module. Alternatively "course" (shortname).
     *  - status (string): one of initial|queued|processing|pending|approve|rejected|failed|superseded.
     *    Defaults to "initial".
     *  - title (string): defaults to the assign activity name.
     *  - attemptnumber (int): defaults to 0.
     *  - grade, message, rubric_response, errormessage, submissionid: optional.
     *  - approval_token (string): defaults to a random string.
     *
     * @param array|stdClass $record Record fields as described above.
     * @return stdClass The inserted local_assign_ai_pending record.
     */
    public function create_pending($record): stdClass {
        global $DB;

        $record = (array) $record;

        $cmid = $this->resolve_assign_cmid($record);
        $cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);

        if (empty($record['userid'])) {
            if (empty($record['user'])) {
                throw new coding_exception('create_pending() requires a "userid" (or "user" username) value.');
            }
            $record['userid'] = $DB->get_field('user', 'id', ['username' => $record['user']], MUST_EXIST);
        }

        if (empty($record['courseid'])) {
            if (!empty($record['course'])) {
                $record['courseid'] = $DB->get_field('course', 'id', ['shortname' => $record['course']], MUST_EXIST);
            } else {
                $record['courseid'] = $cm->course;
            }
        }

        $status = $record['status'] ?? 'initial';
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new coding_exception('Invalid pending record status "' . $status . '".');
        }

        $now = time();
        $pending = (object) [
            'courseid' => (int) $record['courseid'],
            'assignmentid' => (int) $cmid,
            'title' => $record['title'] ?? $cm->name,
            'userid' => (int) $record['userid'],
            'submissionid' => isset($record['submissionid']) ? (int) $record['submissionid'] : null,
            'attemptnumber' => (int) ($record['attemptnumber'] ?? 0),
            'submissionmodified' => (int) ($record['submissionmodified'] ?? 0),
            'edited' => (int) ($record['edited'] ?? 0),
            'message' => $record['message'] ?? null,
            'grade' => isset($record['grade']) && $record['grade'] !== '' ? (int) $record['grade'] : null,
            'rubric_response' => $record['rubric_response'] ?? null,
            'assessment_guide_response' => $record['assessment_guide_response'] ?? null,
            'errormessage' => $record['errormessage'] ?? null,
            'retries' => (int) ($record['retries'] ?? 0),
            'status' => $status,
            'approval_token' => $record['approval_token'] ?? random_string(32),
            'usermodified' => isset($record['usermodified']) ? (int) $record['usermodified'] : null,
            'timecreated' => (int) ($record['timecreated'] ?? $now),
            'timemodified' => (int) ($record['timemodified'] ?? $now),
        ];

        $pending->id = $DB->insert_record('local_assign_ai_pending', $pending);

        return $DB->get_record('local_assign_ai_pending', ['id' => $pending->id], '*', MUST_EXIST);
    }

    /**
     * Create (or update) the local_assign_ai_config record of an assign instance.
     *
     * Note: the "assignmentid" field of local_assign_ai_config is the assign INSTANCE id
     * ({assign}.id), unlike local_assign_ai_pending which stores the cmid. To avoid mistakes
     * this generator also accepts "cmid" or "assign" (activity idnumber) and resolves the
     * instance id itself.
     *
     * Accepted fields:
     *  - assignmentid (int): assign INSTANCE id. Alternatively "cmid" (course module id)
     *    or "assign" (activity idnumber).
     *  - enableai (int): defaults to 1.
     *  - autograde (int): defaults to 0.
     *  - usedelay (int): defaults to 0.
     *  - delayminutes (int): defaults to 0.
     *  - graderid (int): optional. Alternatively "grader" (username).
     *  - prompt (string): optional.
     *  - lang (string): optional.
     *
     * @param array|stdClass $record Record fields as described above.
     * @return stdClass The inserted or updated local_assign_ai_config record.
     */
    public function create_config($record): stdClass {
        global $DB;

        $record = (array) $record;

        if (empty($record['assignmentid'])) {
            if (empty($record['cmid']) && !empty($record['assign'])) {
                $record['cmid'] = $this->resolve_assign_cmid($record);
            }
            if (empty($record['cmid'])) {
                throw new coding_exception(
                    'create_config() requires an "assignmentid" (assign instance id), "cmid" or "assign" (idnumber) value.'
                );
            }
            $cm = get_coursemodule_from_id('assign', $record['cmid'], 0, false, MUST_EXIST);
            $record['assignmentid'] = (int) $cm->instance;
        }

        if (empty($record['graderid']) && !empty($record['grader'])) {
            $record['graderid'] = $DB->get_field('user', 'id', ['username' => $record['grader']], MUST_EXIST);
        }

        $now = time();
        $config = (object) [
            'assignmentid' => (int) $record['assignmentid'],
            'enableai' => (int) ($record['enableai'] ?? 1),
            'autograde' => (int) ($record['autograde'] ?? 0),
            'graderid' => isset($record['graderid']) ? (int) $record['graderid'] : null,
            'usedelay' => (int) ($record['usedelay'] ?? 0),
            'delayminutes' => (int) ($record['delayminutes'] ?? 0),
            'prompt' => $record['prompt'] ?? null,
            'lang' => $record['lang'] ?? null,
            'usermodified' => isset($record['usermodified']) ? (int) $record['usermodified'] : null,
            'timemodified' => (int) ($record['timemodified'] ?? $now),
        ];

        if ($existing = $DB->get_record('local_assign_ai_config', ['assignmentid' => $config->assignmentid])) {
            $config->id = $existing->id;
            $config->timecreated = $existing->timecreated;
            $DB->update_record('local_assign_ai_config', $config);
        } else {
            $config->timecreated = (int) ($record['timecreated'] ?? $now);
            $config->id = $DB->insert_record('local_assign_ai_config', $config);
        }

        return $DB->get_record('local_assign_ai_config', ['id' => $config->id], '*', MUST_EXIST);
    }

    /**
     * Resolve the course module id (cmid) of an assign activity from the given record.
     *
     * Accepts "assignmentid"/"cmid" (already a cmid) or "assign" (the activity idnumber).
     *
     * @param array $record Generator record.
     * @return int The course module id.
     */
    protected function resolve_assign_cmid(array $record): int {
        global $DB;

        if (!empty($record['assignmentid'])) {
            return (int) $record['assignmentid'];
        }
        if (!empty($record['cmid'])) {
            return (int) $record['cmid'];
        }
        if (empty($record['assign'])) {
            throw new coding_exception('An "assignmentid" (cmid) or "assign" (activity idnumber) value is required.');
        }

        $cmid = $DB->get_field('course_modules', 'id', ['idnumber' => $record['assign']]);
        if (!$cmid) {
            throw new coding_exception('No activity found with idnumber "' . $record['assign'] . '".');
        }

        return (int) $cmid;
    }
}
