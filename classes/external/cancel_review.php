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

namespace local_assign_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_assign_ai\assign_submission;

/**
 * External function to cancel a stuck AI review (queued/processing) from the review page.
 *
 * Runs synchronously in the web request, so it works even when cron is down (the usual
 * cause of submissions getting stuck because the ad-hoc task never runs).
 *
 * @package     local_assign_ai
 * @category    external
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cancel_review extends external_api {
    /**
     * Returns the description of the parameters for this external function.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID', VALUE_REQUIRED),
            'pendingid' => new external_value(PARAM_INT, 'Pending record ID', VALUE_REQUIRED),
        ]);
    }

    /**
     * Cancel a queued/processing review, returning it to the initial state.
     *
     * @param int $cmid Course module ID.
     * @param int $pendingid Pending record ID.
     * @return array The result of the operation.
     */
    public static function execute($cmid, $pendingid) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'pendingid' => $pendingid,
        ]);

        $cm = get_coursemodule_from_id('assign', $params['cmid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('local/assign_ai:review', $context);

        $record = $DB->get_record('local_assign_ai_pending', ['id' => $params['pendingid']], '*', MUST_EXIST);
        if ((int) $record->assignmentid !== (int) $cm->id) {
            throw new \moodle_exception('invalidrecord', 'error');
        }

        // Only cancel records that are actually stuck (queued/processing).
        if (!in_array((string) $record->status, [
            assign_submission::STATUS_QUEUED,
            assign_submission::STATUS_PROCESSING,
        ], true)) {
            return ['status' => 'skipped'];
        }

        // Return it to the initial state so it can be reviewed again. Any in-flight ad-hoc
        // task is made inert by the status guard in the task itself.
        assign_submission::update_pending_submission((int) $record->id, [
            'status' => assign_submission::STATUS_INITIAL,
            'errormessage' => null,
            'retries' => 0,
        ]);

        return ['status' => 'ok'];
    }

    /**
     * Returns the description of the return values.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Operation status (ok or skipped)'),
        ]);
    }
}
