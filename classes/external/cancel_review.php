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

require_once($CFG->dirroot . '/mod/assign/locallib.php');

use local_assign_ai\local\provider_dependency;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_assign_ai\assign_submission;
use local_assign_ai\local\activity_access;
use local_assign_ai\local\tenant_context;

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
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'pendingid' => $pendingid,
        ]);

        provider_dependency::require_enabled(tenant_context::get_current_tenant_id());

        $access = activity_access::require_access($params['cmid'], 'local/assign_ai:review');

        // Scoped to the authorised activity: a record of another activity fails exactly like an absent one.
        $record = activity_access::require_pending_record($access, pendingid: $params['pendingid']);

        // Only cancel records that are actually stuck (queued/processing).
        $cancellable = [assign_submission::STATUS_QUEUED, assign_submission::STATUS_PROCESSING];
        if (!in_array((string) $record->status, $cancellable, true)) {
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
