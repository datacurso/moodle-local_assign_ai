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

/**
 * Assign AI plugin configuration.
 *
 * @package    local_assign_ai
 * @copyright  2025 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_assign_ai_get_details' => [
        'classname' => 'local_assign_ai\external\get_details',
        'methodname' => 'execute',
        'classpath' => '',
        'description' => 'Obtener detalles de retroalimentación IA',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_assign_ai_update_response' => [
        'classname' => 'local_assign_ai\external\update_response',
        'methodname' => 'execute',
        'classpath' => '',
        'description' => 'Actualizar mensaje IA',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_assign_ai_change_status' => [
        'classname' => 'local_assign_ai\external\change_status',
        'methodname' => 'execute',
        'classpath' => '',
        'description' => 'Cambiar estado de retroalimentación IA',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_assign_ai_process_submission' => [
        'classname' => 'local_assign_ai\external\process_submission',
        'methodname' => 'execute',
        'description' => 'Process assignment submissions using AI.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/assign_ai:review',
    ],
    'local_assign_ai_cancel_review' => [
        'classname' => 'local_assign_ai\external\cancel_review',
        'methodname' => 'execute',
        'description' => 'Cancel a stuck (queued/processing) AI review, returning it to the initial state.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/assign_ai:review',
    ],
    'local_assign_ai_approve_all_pending' => [
        'classname' => 'local_assign_ai\external\approve_all_pending',
        'methodname' => 'execute',
        'description' => 'Approve all pending AI feedback for an assignment.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/assign_ai:changestatus',
    ],
    'local_assign_ai_get_progress' => [
        'classname' => 'local_assign_ai\external\get_progress',
        'methodname' => 'execute',
        'description' => 'Get progress info for pending AI reviews.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/assign_ai:review',
    ],
    'local_assign_ai_get_token' => [
        'classname' => 'local_assign_ai\external\get_token',
        'methodname' => 'execute',
        'classpath' => '',
        'description' => 'Get AI approval token for a given user id',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/assign_ai:review',
    ],
];
