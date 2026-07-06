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

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;

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
        $fields = 'id, assignmentid, status, grade, errormessage';
        $records = $DB->get_records_select('local_assign_ai_pending', "id $insql", $inparams, '', $fields);

        $out = [];
        $allowedcmid = [];
        foreach ($records as $r) {
            $cmid = (int)$r->assignmentid;

            // Resolve each record's module context and require review permission there,
            // caching the result per cmid. Records the caller cannot review are silently
            // dropped so ids cannot be enumerated across activities.
            if (!array_key_exists($cmid, $allowedcmid)) {
                $allowedcmid[$cmid] = false;
                try {
                    $cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
                    $context = \context_module::instance($cm->id);
                    self::validate_context($context);
                    require_capability('local/assign_ai:review', $context);
                    $allowedcmid[$cmid] = true;
                } catch (\Exception $e) {
                    $allowedcmid[$cmid] = false;
                }
            }

            if (!$allowedcmid[$cmid]) {
                continue;
            }

            $out[] = [
                'id' => (int)$r->id,
                'status' => (string)$r->status,
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
