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

namespace local_assign_ai\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/assign/locallib.php');

use aiprovider_datacurso\local\plugin_settings;
use core\task\adhoc_task;
use local_assign_ai\local\service\submission_ai_processor;
use local_assign_ai\local\tenant_context;

/**
 * Ad-hoc task to process AI submission for an assignment.
 *
 * @package    local_assign_ai
 * @category   task
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_submission_ai extends adhoc_task {
    /**
     * Execute the task.
     *
     * Expected custom data:
     *  - userid (int)
     *  - cmid (int)
     *
     * @return void
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        if (empty($data->userid) || empty($data->cmid)) {
            return;
        }

        // The provider switch follows the tenant of the student, never the course's tenant nor the cron user.
        if (!plugin_settings::is_enabled('local_assign_ai', tenant_context::get_tenant_id_for_user((int) $data->userid))) {
            mtrace(get_string('task_skipped_plugindisabled', 'local_assign_ai'));
            return;
        }

        try {
            submission_ai_processor::process((int)$data->userid, (int)$data->cmid);
        } catch (\Throwable $e) {
            mtrace($e);
        }
    }
}
