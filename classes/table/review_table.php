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

namespace local_assign_ai\table;

use assign;
use html_writer;
use local_assign_ai\assign_submission;
use local_assign_ai\grading\feedback_applier;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->libdir . '/tablelib.php');

/**
 * Review table for local_assign_ai.
 *
 * @package    local_assign_ai
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class review_table extends \table_sql {
    /** @var assign Assignment instance. */
    private assign $assign;

    /** @var \context_module Module context. */
    private \context_module $context;

    /** @var bool Whether the current user can change status. */
    private bool $canchangestatus;

    /** @var bool Whether the current user can view details. */
    private bool $canviewdetails;

    /** @var bool Whether the comments feedback plugin is active for this assignment. */
    private bool $commentsactive;

    /**
     * Constructor.
     *
     * @param string $uniqueid Unique table identifier.
     * @param assign $assign Assignment instance.
     */
    public function __construct(string $uniqueid, assign $assign) {
        parent::__construct($uniqueid);

        $this->assign = $assign;
        $this->context = $assign->get_context();
        $this->canchangestatus = has_capability('local/assign_ai:changestatus', $this->context);
        $this->canviewdetails = has_capability('local/assign_ai:viewdetails', $this->context);
        $this->commentsactive = feedback_applier::is_comments_plugin_active($assign);

        $this->define_baseurl(new moodle_url('/local/assign_ai/review.php', ['id' => $assign->get_course_module()->id]));
        $this->set_attribute('class', 'generaltable generalbox table table-bordered table-striped w-100 text-center mb-0');
        $this->define_columns(['fullname', 'email', 'status', 'lastmodified', 'files', 'grade', 'aistatus', 'actions']);
        $this->define_headers([
            get_string('fullname', 'local_assign_ai'),
            get_string('email', 'local_assign_ai'),
            get_string('status', 'local_assign_ai'),
            get_string('lastmodified', 'local_assign_ai'),
            get_string('submittedfiles', 'local_assign_ai'),
            get_string('grade', 'local_assign_ai'),
            get_string('aistatus', 'local_assign_ai'),
            get_string('actions', 'local_assign_ai'),
        ]);

        [$insql, $inparams] = $this->build_status_sql();
        $this->set_sql(
            'p.id, p.courseid, p.assignmentid, p.userid, p.message, p.grade, p.status AS aistatus, p.timemodified, '
            . 'u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.email',
            '{local_assign_ai_pending} p JOIN {user} u ON u.id = p.userid',
            'p.courseid = :courseid AND p.assignmentid = :assignmentid AND p.status ' . $insql
            . ' AND NOT EXISTS (SELECT 1 FROM {local_assign_ai_pending} p2'
            . ' WHERE p2.assignmentid = p.assignmentid AND p2.userid = p.userid'
            . ' AND p2.attemptnumber > p.attemptnumber)',
            [
                'courseid' => $assign->get_course()->id,
                'assignmentid' => $assign->get_course_module()->id,
            ] + $inparams
        );
    }

    /**
     * Build the status SQL fragment.
     *
     * @return array
     */
    private function build_status_sql(): array {
        global $DB;

        return $DB->get_in_or_equal([
            assign_submission::STATUS_INITIAL,
            assign_submission::STATUS_QUEUED,
            assign_submission::STATUS_PROCESSING,
            assign_submission::STATUS_PENDING,
            assign_submission::STATUS_REJECTED,
        ], SQL_PARAMS_NAMED, 'st');
    }

    /**
     * Return the row class.
     *
     * @param array|\stdClass $row Row data.
     * @return string
     */
    public function get_row_class($row): string {
        return match ((string) ($row->aistatus ?? '')) {
            assign_submission::STATUS_INITIAL => 'js-review-row js-row-initial',
            assign_submission::STATUS_QUEUED => 'js-review-row js-row-queued',
            assign_submission::STATUS_PROCESSING => 'js-review-row js-row-inprogress',
            assign_submission::STATUS_REJECTED => 'js-review-row js-row-rejected',
            default => 'js-review-row js-row-pending',
        };
    }

    /**
     * Render fullname.
     *
     * @param \stdClass $row Row data.
     * @return string
     */
    public function col_fullname($row): string {
        return fullname($row, has_capability('moodle/site:viewfullnames', $this->context));
    }

    /**
     * Render email.
     *
     * @param \stdClass $row Row data.
     * @return string
     */
    public function col_email($row): string {
        return s((string) $row->email);
    }

    /**
     * Render submission status.
     *
     * @param \stdClass $row Row data.
     * @return string
     */
    public function col_status($row): string {
        $submission = $this->assign->get_user_submission((int) $row->userid, false);
        if (!$submission) {
            return get_string('submission_none', 'local_assign_ai');
        }

        return match ((string) $submission->status) {
            ASSIGN_SUBMISSION_STATUS_SUBMITTED => get_string('submission_submitted', 'local_assign_ai'),
            ASSIGN_SUBMISSION_STATUS_DRAFT => get_string('submission_draft', 'local_assign_ai'),
            ASSIGN_SUBMISSION_STATUS_NEW => get_string('submission_new', 'local_assign_ai'),
            default => get_string('submission_none', 'local_assign_ai'),
        };
    }

    /**
     * Render last modified.
     *
     * @param \stdClass $row Row data.
     * @return string
     */
    public function col_lastmodified($row): string {
        return !empty($row->timemodified) ? userdate((int) $row->timemodified) : '-';
    }

    /**
     * Render submitted files.
     *
     * @param \stdClass $row Row data.
     * @return string
     */
    public function col_files($row): string {
        $submission = $this->assign->get_user_submission((int) $row->userid, false);
        if (!$submission) {
            return '';
        }

        $files = get_file_storage()->get_area_files(
            $this->context->id,
            'assignsubmission_file',
            'submission_files',
            $submission->id,
            'id',
            false
        );

        if (!$files) {
            return '';
        }

        $links = [];
        foreach ($files as $file) {
            $url = moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                $file->get_component(),
                $file->get_filearea(),
                $file->get_itemid(),
                $file->get_filepath(),
                $file->get_filename()
            );
            $links[] = html_writer::link($url, s($file->get_filename()));
        }

        return implode(' ', $links);
    }

    /**
     * Render grade.
     *
     * @param \stdClass $row Row data.
     * @return string
     */
    public function col_grade($row): string {
        return $row->grade !== null ? (string) $row->grade : '-';
    }

    /**
     * Render AI status.
     *
     * @param \stdClass $row Row data.
     * @return string
     */
    public function col_aistatus($row): string {
        $status = (string) ($row->aistatus ?? '');

        if ($status === assign_submission::STATUS_INITIAL) {
            return $this->render_state(
                'badge bg-secondary',
                get_string('aistatus_initial_short', 'local_assign_ai'),
                get_string('aistatus_initial_help', 'local_assign_ai')
            );
        }

        if ($status === assign_submission::STATUS_QUEUED) {
            return $this->render_state(
                'badge bg-warning',
                get_string('aistatus_queued_short', 'local_assign_ai'),
                get_string('aistatus_queued_help', 'local_assign_ai')
            );
        }

        if ($status === assign_submission::STATUS_PROCESSING) {
            return $this->render_state(
                'badge bg-warning',
                get_string('processing', 'local_assign_ai'),
                get_string('aistatus_processing_help', 'local_assign_ai'),
                true
            );
        }

        if ($status === assign_submission::STATUS_REJECTED) {
            return $this->render_state(
                'badge bg-danger',
                get_string('statusrejected', 'local_assign_ai'),
                get_string('statusrejected', 'local_assign_ai')
            );
        }

        return $this->render_state(
            'badge bg-info',
            get_string('aistatus_pending_short', 'local_assign_ai'),
            get_string('aistatus_pending_help', 'local_assign_ai')
        );
    }

    /**
     * Render action buttons.
     *
     * @param \stdClass $row Row data.
     * @return string
     */
    public function col_actions($row): string {
        $status = (string) ($row->aistatus ?? '');
        $isprocessing = $status === assign_submission::STATUS_PROCESSING;
        $isqueued = $status === assign_submission::STATUS_QUEUED;
        $canapproveai = $status === assign_submission::STATUS_PENDING;
        $disabled = $isprocessing || $isqueued;

        $buttons = html_writer::tag('span', '', [
            'class' => 'd-none js-pending-marker',
            'data-pendingid' => (int) $row->id,
        ]);

        $buttons .= html_writer::link(
            new moodle_url('/mod/assign/view.php', [
                'id' => $this->assign->get_course_module()->id,
                'action' => 'grader',
                'userid' => $row->userid,
            ]),
            get_string('qualify', 'local_assign_ai'),
            [
                'class' => 'btn btn-primary btn-sm text-nowrap js-btn-grade' . ($disabled ? ' disabled' : ''),
                'aria-disabled' => $disabled ? 'true' : null,
                'tabindex' => $disabled ? '-1' : null,
            ]
        );

        // The details modal only exposes the AI feedback message, which cannot be
        // delivered when the comments feedback plugin is disabled.
        if ($canapproveai && $this->canviewdetails && $this->commentsactive) {
            $buttons .= html_writer::tag('button', get_string('viewdetails', 'local_assign_ai'), [
                'class' => 'btn btn-success btn-sm text-nowrap view-details js-btn-details',
                'data-courseid' => (int) $row->courseid,
                'data-cmid' => (int) $row->assignmentid,
                'data-userid' => (int) $row->userid,
                'data-showapprovebuttons' => $this->canchangestatus ? 'true' : 'false',
                'disabled' => $disabled ? 'disabled' : null,
            ]);
        }

        if (!$canapproveai) {
            $buttons .= html_writer::tag('button', get_string('review', 'local_assign_ai'), [
                'class' => 'btn btn-warning btn-sm text-nowrap js-review-ai js-btn-review',
                'type' => 'button',
                'data-cmid' => (int) $row->assignmentid,
                'data-userid' => (int) $row->userid,
                'data-pendingid' => (int) $row->id,
                'disabled' => $disabled ? 'disabled' : null,
            ]);
        }

        // Allow cancelling a stuck review (queued/processing) to free it up without waiting.
        if ($disabled) {
            $buttons .= html_writer::tag('button', get_string('cancel', 'core'), [
                'class' => 'btn btn-outline-danger btn-sm text-nowrap js-btn-cancel',
                'type' => 'button',
                'data-cmid' => (int) $row->assignmentid,
                'data-pendingid' => (int) $row->id,
            ]);
        }

        return html_writer::div($buttons, 'local_assign_ai_action-buttons');
    }

    /**
     * Render the state badge and hint.
     *
     * @param string $badgeclass Badge class.
     * @param string $badge Badge text.
     * @param string $hint Hint text.
     * @param bool $spinner Whether to add spinner.
     * @return string
     */
    private function render_state(string $badgeclass, string $badge, string $hint, bool $spinner = false): string {
        $html = html_writer::span($badge, $badgeclass . ' js-state-badge');
        $html .= html_writer::div($hint, 'text-muted small js-state-hint');

        if ($spinner) {
            $html .= html_writer::div(
                html_writer::tag('span', '', [
                    'class' => 'spinner-border spinner-border-sm me-1',
                    'role' => 'status',
                    'aria-hidden' => 'true',
                ]),
                'small text-warning js-progress-indicator'
            );
        }

        return $html;
    }
}
