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
 * External function to get progress for pending AI reviews.
 *
 * @package     local_assign_ai
 * @category    external
 * @copyright   2025 Wilber Narvaez <https://datacurso.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External API to retrieve progress of pending AI reviews.
 *
 * @package     local_assign_ai
 * @category    external
 * @copyright   2025 Wilber Narvaez <https://datacurso.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_progress extends external_api {
    /**
     * Parameters.
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'pendingids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Pending record id'),
                'IDs of local_assign_ai_pending records to query',
                VALUE_REQUIRED
            ),
        ]);
    }

    /**
     * Execute.
     * @param array $pendingids
     * @return array
     */
    public static function execute($pendingids) {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), [
            'pendingids' => $pendingids,
        ]);
        $pendingids = $params['pendingids'];

        if (empty($pendingids)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($pendingids, SQL_PARAMS_NAMED);
        $fields = 'id, status, grade, errormessage';
        $records = $DB->get_records_select('local_assign_ai_pending', "id $insql", $inparams, '', $fields);

        $out = [];
        foreach ($records as $r) {
            $status = (string)$r->status;

            $out[] = [
                'id' => (int)$r->id,
                'status' => $status,
                'grade' => $r->grade !== null ? (int)$r->grade : null,
                'errormessage' => (string)($r->errormessage ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Returns structure.
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Pending record id'),
            'status' => new external_value(PARAM_TEXT, 'Status value'),
            'grade' => new external_value(PARAM_INT, 'Grade', VALUE_OPTIONAL),
            'errormessage' => new external_value(PARAM_RAW, 'Error message when the review failed', VALUE_OPTIONAL),
        ]));
    }
}
