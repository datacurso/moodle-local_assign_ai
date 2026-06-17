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

namespace local_assign_ai\reportbuilder\systemreports;

use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\report\action;
use core_reportbuilder\system_report;
use lang_string;
use local_assign_ai\assign_submission;
use local_assign_ai\reportbuilder\entities\history as history_entity;
use moodle_url;
use pix_icon;

/**
 * AI review history report.
 *
 * @package    local_assign_ai
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class history extends system_report {
    /**
     * Initialise report.
     */
    protected function initialise(): void {
        global $DB;

        $entity = new history_entity();
        $this->add_entity($entity);

        $pending = $entity->get_table_alias('local_assign_ai_pending');
        $this->set_main_table('local_assign_ai_pending', $pending);
        // Fields required by the row actions (placeholders) must be available as base fields.
        $this->add_base_fields("{$pending}.id, {$pending}.userid");
        $this->add_base_condition_simple("{$pending}.courseid", $this->get_parameter('courseid', 0, PARAM_INT));
        $this->add_base_condition_simple("{$pending}.assignmentid", $this->get_parameter('assignmentid', 0, PARAM_INT));

        // Show both successful (approved) and failed AI processing attempts.
        // Reportbuilder requires generated parameter names (prefixed with rbparam).
        $prefix = database::generate_param_name() . '_';
        [$insql, $inparams] = $DB->get_in_or_equal(
            [assign_submission::STATUS_APPROVED, assign_submission::STATUS_FAILED],
            SQL_PARAMS_NAMED,
            $prefix
        );
        $this->add_base_condition_sql("{$pending}.status {$insql}", $inparams);
        $this->set_default_per_page(20);
        $this->add_columns_from_entity($entity->get_entity_name());
        $this->set_initial_sort_column('history:lastmodified', SORT_DESC);

        $this->add_actions();

        // Allow exporting the report data natively.
        $this->set_downloadable(true, get_string('reviewhistory', 'local_assign_ai'));
    }

    /**
     * Add the per-row action menu (the "three dots"), mirroring /admin/tasklogs.php.
     */
    protected function add_actions(): void {
        $cmid = $this->get_parameter('assignmentid', 0, PARAM_INT);
        $historyurl = new moodle_url('/local/assign_ai/history.php', ['id' => $cmid]);

        // Retry: re-queue this review as an ad-hoc task (available on every row).
        $this->add_action(new action(
            new moodle_url($historyurl, ['retry' => ':id', 'sesskey' => sesskey()]),
            new pix_icon('t/reload', ''),
            [],
            false,
            new lang_string('retry', 'local_assign_ai'),
        ));

        // View the full processing log.
        $this->add_action(new action(
            new moodle_url($historyurl, ['logid' => ':id', 'view' => 'log']),
            new pix_icon('i/log', ''),
            [],
            false,
            new lang_string('viewlog', 'local_assign_ai'),
        ));

        // Download the log as a text file.
        $this->add_action(new action(
            new moodle_url($historyurl, ['logid' => ':id', 'download' => 1]),
            new pix_icon('t/download', ''),
            [],
            false,
            new lang_string('downloadlog', 'local_assign_ai'),
        ));

        // Jump to the assignment grader for this student.
        $this->add_action(new action(
            new moodle_url('/mod/assign/view.php', ['id' => $cmid, 'action' => 'grader', 'userid' => ':userid']),
            new pix_icon('i/grades', ''),
            [],
            false,
            new lang_string('editgrade', 'local_assign_ai'),
        ));
    }

    /**
     * {@inheritDoc}
     */
    protected function can_view(): bool {
        return has_capability('local/assign_ai:review', $this->get_context());
    }

    /**
     * {@inheritDoc}
     */
    public static function get_name(): string {
        return get_string('reviewhistory', 'local_assign_ai');
    }
}
