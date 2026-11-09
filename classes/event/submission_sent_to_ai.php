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

namespace local_assign_ai\event;

/**
 * Event logged every time a submission is sent to the AI service.
 *
 * It records who sent which student's submission, in which activity, to which endpoint and
 * whether the transfer completed, so each transfer of personal data to the service can be traced.
 * It never stores the content that was sent or received (prompt, text, files, name, reply, grade).
 *
 * The object is the assign submission that was sent (objecttable assign_submission).
 *
 * @property-read array $other {
 *      Extra information about the transfer.
 *
 *      - string endpoint: AI service endpoint that received the submission.
 *      - string result: 'success' when the service answered, 'failure' otherwise.
 *      - string errorcode: (only on failure) error code, or short class name of the error.
 * }
 *
 * @package     local_assign_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submission_sent_to_ai extends \core\event\base {
    /** Result of a transfer the service answered. */
    public const RESULT_SUCCESS = 'success';

    /** Result of a transfer that did not complete. */
    public const RESULT_FAILURE = 'failure';

    /** Keys allowed in the other field. */
    private const ALLOWED_OTHER_KEYS = ['endpoint', 'result', 'errorcode'];

    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'assign_submission';
    }

    /**
     * Returns the localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventsubmissionsenttoai', 'local_assign_ai');
    }

    /**
     * Returns the description of what happened.
     *
     * @return string
     */
    public function get_description() {
        return get_string('eventsubmissionsenttoai_desc', 'local_assign_ai', (object) [
            'userid' => $this->userid,
            'relateduserid' => $this->relateduserid,
            'endpoint' => $this->other['endpoint'],
            'result' => $this->other['result'],
            'cmid' => $this->contextinstanceid,
        ]);
    }

    /**
     * Returns the URL of the AI review history of the activity.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/assign_ai/history.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Validates the event data.
     *
     * @return void
     * @throws \coding_exception When required data is missing or not allowed.
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
        if ($this->contextlevel != CONTEXT_MODULE) {
            throw new \coding_exception('Context level must be CONTEXT_MODULE.');
        }
        if (empty($this->other['endpoint'])) {
            throw new \coding_exception('The \'endpoint\' value must be set in other.');
        }
        $result = $this->other['result'] ?? null;
        if (!in_array($result, [self::RESULT_SUCCESS, self::RESULT_FAILURE], true)) {
            throw new \coding_exception('The \'result\' value in other must be success or failure.');
        }
        if ($result === self::RESULT_FAILURE && empty($this->other['errorcode'])) {
            throw new \coding_exception('The \'errorcode\' value must be set in other on failure.');
        }
        $unexpected = array_diff(array_keys($this->other), self::ALLOWED_OTHER_KEYS);
        if ($unexpected) {
            throw new \coding_exception('Unexpected keys in other: ' . implode(', ', $unexpected));
        }
    }

    /**
     * Restore mapping of the object id.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'assign_submission', 'restore' => 'submission'];
    }

    /**
     * Restore mapping of the other field: it holds no ids.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
