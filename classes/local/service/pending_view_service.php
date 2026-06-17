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

namespace local_assign_ai\local\service;

use local_assign_ai\assign_submission;

defined('MOODLE_INTERNAL') || die();

/**
 * Prepare data for the AI review and history tables.
 *
 * @package    local_assign_ai
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pending_view_service {
    /** Default rows per page. */
    public const DEFAULT_PER_PAGE = 20;

    /**
     * Return the review state flags for a record.
     *
     * @param string $status Pending record status.
     * @return array
     */
    public static function get_review_state(string $status): array {
        if ($status === assign_submission::STATUS_INITIAL) {
            return [
                'statekey' => assign_submission::STATUS_INITIAL,
                'statebadgeclass' => 'badge bg-secondary',
                'canrequestai' => true,
                'canapproveai' => false,
                'isinitial' => true,
                'ispending' => false,
                'isqueued' => false,
                'isprocessing' => false,
                'inprogress' => false,
            ];
        }

        if ($status === assign_submission::STATUS_QUEUED) {
            return [
                'statekey' => assign_submission::STATUS_QUEUED,
                'statebadgeclass' => 'badge bg-warning',
                'canrequestai' => false,
                'canapproveai' => false,
                'isinitial' => false,
                'ispending' => false,
                'isqueued' => true,
                'isprocessing' => false,
                'inprogress' => false,
            ];
        }

        if ($status === assign_submission::STATUS_PROCESSING) {
            return [
                'statekey' => assign_submission::STATUS_PROCESSING,
                'statebadgeclass' => 'badge bg-warning',
                'canrequestai' => false,
                'canapproveai' => false,
                'isinitial' => false,
                'ispending' => false,
                'isqueued' => false,
                'isprocessing' => true,
                'inprogress' => true,
            ];
        }

        return [
            'statekey' => assign_submission::STATUS_PENDING,
            'statebadgeclass' => 'badge bg-info',
            'canrequestai' => false,
            'canapproveai' => true,
            'isinitial' => false,
            'ispending' => true,
            'isqueued' => false,
            'isprocessing' => false,
            'inprogress' => false,
        ];
    }
}
