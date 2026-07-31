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
 * History page for local_assign_ai.
 *
 * @package     local_assign_ai
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_reportbuilder\system_report_factory;
use local_assign_ai\assign_submission;
use local_assign_ai\grading\feedback_applier;
use local_assign_ai\output\header_logo;
use local_assign_ai\reportbuilder\systemreports\history as history_report;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

$cmid = required_param('id', PARAM_INT);
$logid = optional_param('logid', null, PARAM_INT);
$download = optional_param('download', false, PARAM_BOOL);
$retry = optional_param('retry', 0, PARAM_INT);
$retryall = optional_param('retryall', 0, PARAM_BOOL);

$cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);

// Verificar permisos ANTES de configurar la página para evitar conflictos de estado.
if (!has_capability('local/assign_ai:review', $context)) {
    $courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
    throw new moodle_exception(
        'nopermissions',
        'error',
        $courseurl,
        get_string('assign_ai:review', 'local_assign_ai')
    );
}

$reviewurl = new moodle_url('/local/assign_ai/review.php', ['id' => $cmid]);

// Retry a single review (re-queue as an ad-hoc task). Available on every history row.
if ($retry) {
    require_sesskey();
    $record = $DB->get_record('local_assign_ai_pending', ['id' => $retry], '*', MUST_EXIST);
    if ((int) $record->assignmentid !== (int) $cm->id) {
        throw new moodle_exception('invalidrecord', 'error');
    }

    if ((string) $record->status === assign_submission::STATUS_APPROVED) {
        // Do not replace a successful record: create a new attempt as a new log row.
        $newid = assign_submission::create_pending_submission((object) [
            'courseid' => $record->courseid,
            'assignmentid' => $record->assignmentid,
            'userid' => $record->userid,
            'submissionid' => $record->submissionid,
            'attemptnumber' => $record->attemptnumber,
            'submissionmodified' => $record->submissionmodified,
            'title' => $record->title,
            'message' => null,
            'grade' => null,
            'status' => assign_submission::STATUS_QUEUED,
        ]);
        assign_submission::queue_ai_review((int) $cm->id, (int) $course->id, (int) $record->userid, (int) $newid);
    } else {
        // Failed (or any other) record: re-queue the same row (manual retry resets the counter).
        assign_submission::queue_ai_review((int) $cm->id, (int) $course->id, (int) $record->userid, (int) $record->id, true);
    }

    redirect($reviewurl, get_string('retryqueued', 'local_assign_ai'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Retry all failed reviews for this assignment (re-queue them, processed by one ad-hoc task).
if ($retryall) {
    require_sesskey();
    $failed = $DB->get_records('local_assign_ai_pending', [
        'assignmentid' => $cm->id,
        'status' => assign_submission::STATUS_FAILED,
    ]);
    $count = count($failed);

    if ($count > 0) {
        foreach ($failed as $failedrecord) {
            // Refresh timemodified (so the reaper won't re-fail them) and reset the auto-retry counter.
            assign_submission::update_pending_submission((int) $failedrecord->id, [
                'status' => assign_submission::STATUS_QUEUED,
                'retries' => 0,
            ]);
        }

        $task = new \local_assign_ai\task\process_all_submissions();
        $task->set_custom_data([
            'cmid' => (int) $cm->id,
            'courseid' => (int) $course->id,
            'pendingcount' => $count,
        ]);
        \core\task\manager::queue_adhoc_task($task);
    }

    redirect($reviewurl, get_string('retryallqueued', 'local_assign_ai', $count), null, \core\output\notification::NOTIFY_SUCCESS);
}

// View or download a single AI processing log (mirrors /admin/tasklogs.php).
if ($logid !== null) {
    $record = $DB->get_record('local_assign_ai_pending', ['id' => $logid], '*', MUST_EXIST);

    // Ensure the record belongs to this assignment to avoid cross-task leakage.
    if ((int) $record->assignmentid !== (int) $cm->id) {
        throw new moodle_exception('invalidrecord', 'error');
    }

    $student = $DB->get_record('user', ['id' => $record->userid]);
    $studentname = $student ? fullname($student) : (string) $record->userid;
    $statuslabel = match ((string) $record->status) {
        assign_submission::STATUS_APPROVED => get_string('statusapprove', 'local_assign_ai'),
        assign_submission::STATUS_SUPERSEDED => get_string('statussuperseded', 'local_assign_ai'),
        assign_submission::STATUS_FAILED, assign_submission::STATUS_REJECTED =>
            get_string('statuserror', 'local_assign_ai'),
        default => get_string('statuspending', 'local_assign_ai'),
    };
    $gradetext = $record->grade !== null ? (string) $record->grade : '-';
    $datetext = !empty($record->timemodified) ? userdate((int) $record->timemodified) : '-';

    // Validity: superseded when a more recent visible evaluation exists for this student here.
    $issuperseded = $DB->record_exists_select(
        'local_assign_ai_pending',
        'assignmentid = :cmid AND userid = :userid AND id > :id AND status ' .
            "IN ('" . assign_submission::STATUS_APPROVED . "','" . assign_submission::STATUS_FAILED . "')",
        ['cmid' => $record->assignmentid, 'userid' => $record->userid, 'id' => $record->id]
    );
    $validitytext = $issuperseded
        ? get_string('superseded', 'local_assign_ai')
        : get_string('current', 'local_assign_ai');

    // Build the log text once, reused for both the on-screen view and the download.
    $lines = [
        get_string('fullname', 'local_assign_ai') . ': ' . $studentname,
        get_string('attemptnumber', 'local_assign_ai') . ': ' . ((int) $record->attemptnumber + 1)
            . ' (' . $validitytext . ')',
        get_string('status', 'local_assign_ai') . ': ' . $statuslabel,
        get_string('grade', 'local_assign_ai') . ': ' . $gradetext,
        get_string('lastmodified', 'local_assign_ai') . ': ' . $datetext,
        '',
        get_string('modaltitle', 'local_assign_ai') . ':',
        trim(html_to_text((string) $record->message)),
    ];
    if (!empty($record->errormessage)) {
        $lines[] = '';
        $lines[] = get_string('logerror', 'local_assign_ai') . ':';
        $lines[] = (string) $record->errormessage;
    }

    // Rubric matching diagnostics: show how each AI criterion resolves against the
    // current rubric definition (by id, by name, or not at all) plus the raw JSON.
    // A diagnostics failure must never break the log page, hence the try/catch.
    if (!empty($record->rubric_response)) {
        try {
            $lines[] = '';
            $rubricdata = json_decode((string) $record->rubric_response, true);
            if (!is_array($rubricdata)) {
                $lines[] = get_string('logrubricinvalidjson', 'local_assign_ai');
                $lines[] = (string) $record->rubric_response;
            } else {
                $definition = null;
                $gradingmanager = get_grading_manager($context, 'mod_assign', 'submissions');
                if ($gradingmanager->get_active_method() === 'rubric') {
                    $controller = $gradingmanager->get_controller('rubric');
                    $definition = $controller->get_definition();
                }

                if ($definition && !empty($definition->rubric_criteria)) {
                    $resolved = feedback_applier::resolve_rubric_criteria($rubricdata, $definition->rubric_criteria);
                    $resolutions = $resolved['criteria'];
                    $total = count($resolutions);
                    $matched = 0;
                    $detaillines = [];
                    foreach ($resolutions as $resolution) {
                        $criterionoutcome = match ($resolution['criterionmatch']) {
                            'id' => get_string('logrubriccriterionbyid', 'local_assign_ai', $resolution['criterionid']),
                            'name' => get_string('logrubriccriterionbyname', 'local_assign_ai', $resolution['criterionid']),
                            default => get_string('logrubriccriterionnotmatched', 'local_assign_ai'),
                        };
                        $leveloutcome = match ($resolution['levelmatch']) {
                            'id' => get_string('logrubriclevelbyid', 'local_assign_ai', $resolution['levelid']),
                            'points' => get_string('logrubriclevelbypoints', 'local_assign_ai', $resolution['levelid']),
                            default => get_string('logrubriclevelnotmatched', 'local_assign_ai'),
                        };
                        if ($resolution['failure'] === null) {
                            $matched++;
                        }
                        $detailline = '- ' . $resolution['criterion'] . ': ' . $criterionoutcome . '; ' . $leveloutcome;
                        if ($resolution['failure'] !== null) {
                            $detailline .= ' ('
                                . get_string('logrubricfailurereason', 'local_assign_ai', $resolution['failure']) . ')';
                        }
                        $detaillines[] = $detailline;
                    }

                    $lines[] = $resolved['mode'] === 'strict'
                        ? get_string('logrubricmodestrict', 'local_assign_ai')
                        : get_string('logrubricmodelegacy', 'local_assign_ai');
                    $counts = (object) ['matched' => $matched, 'total' => $total];
                    $lines[] = $matched === $total && $total > 0
                        ? get_string('logrubricmatchok', 'local_assign_ai', $counts)
                        : get_string('logrubricmatchfailed', 'local_assign_ai', $counts);
                    $lines = array_merge($lines, $detaillines);
                } else {
                    $lines[] = get_string('logrubricnodefinition', 'local_assign_ai');
                }

                $lines[] = get_string('logrubricjson', 'local_assign_ai') . ':';
                $lines[] = json_encode($rubricdata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        } catch (\Throwable $e) {
            $lines[] = get_string('logrubricdiagfailed', 'local_assign_ai');
        }
    }
    $logtext = implode("\n", $lines);

    if ($download) {
        $filename = "assign_ai_log-{$record->id}.txt";
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        readstring_accel($logtext, 'text/plain; charset=utf-8');
        exit;
    }

    $title = get_string('logdetails', 'local_assign_ai') . " ({$record->id})";
    $PAGE->set_url(new moodle_url('/local/assign_ai/history.php', ['id' => $cmid, 'logid' => $logid]));
    $PAGE->set_course($course);
    $PAGE->set_context($context);
    $PAGE->set_title($title);
    $PAGE->set_heading(format_string($course->fullname));
    $PAGE->activityheader->disable();
    $PAGE->navbar->add($title, null);

    echo $OUTPUT->header();

    echo html_writer::tag('pre', s($logtext), [
        'class' => 'task-output border rounded p-3',
        'style' => 'min-height: 24lh; white-space: pre-wrap; background: #333; color: #fff;',
    ]);

    echo $OUTPUT->action_link(
        new moodle_url('/local/assign_ai/history.php', ['id' => $cmid]),
        get_string('backtoreview', 'local_assign_ai'),
        null,
        null,
        new pix_icon('i/log', '')
    );
    echo ' ';
    echo $OUTPUT->action_link(
        new moodle_url('/local/assign_ai/history.php', ['id' => $cmid, 'logid' => $logid, 'download' => true]),
        get_string('downloadlog', 'local_assign_ai'),
        null,
        null,
        new pix_icon('t/download', '')
    );

    echo $OUTPUT->footer();
    exit;
}

$PAGE->set_url(new moodle_url('/local/assign_ai/history.php', ['id' => $cmid]));
$PAGE->set_course($course);
$PAGE->set_context($context);
$PAGE->set_title(get_string('reviewhistory', 'local_assign_ai'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css('/local/assign_ai/styles/review.css');
$PAGE->activityheader->disable();

$report = system_report_factory::create(history_report::class, $context, '', '', 0, [
    'courseid' => $course->id,
    'assignmentid' => $cm->id,
]);

echo $OUTPUT->header();

echo html_writer::link(
    $reviewurl,
    get_string('backtoreview', 'local_assign_ai'),
    ['class' => 'btn btn-secondary mb-3']
);

// The "Retry all failed" button is always shown, disabled when there are no failed reviews
// (mirrors the "Review all" button in review.php).
$failedcount = $DB->count_records('local_assign_ai_pending', [
    'assignmentid' => $cm->id,
    'status' => assign_submission::STATUS_FAILED,
]);
if ($failedcount > 0) {
    echo html_writer::link(
        new moodle_url('/local/assign_ai/history.php', ['id' => $cmid, 'retryall' => 1, 'sesskey' => sesskey()]),
        get_string('retryallfailed', 'local_assign_ai'),
        ['class' => 'btn btn-warning mb-3 ml-2']
    );
} else {
    // Match the disabled look of the "Review all" button in review.php (a real disabled <button>).
    echo html_writer::tag('button', get_string('retryallfailed', 'local_assign_ai'), [
        'class' => 'btn btn-warning mb-3 ml-2',
        'type' => 'button',
        'disabled' => 'disabled',
    ]);
}

$templatecontext = [
    'headerlogo' => (new header_logo())->export_for_template($OUTPUT),
    'alttext' => get_string('altlogo', 'local_assign_ai'),
    'tablehtml' => $report->output(),
];

echo $OUTPUT->heading(get_string('reviewhistory', 'local_assign_ai'), 2);
echo $OUTPUT->render_from_template('local_assign_ai/history_table', $templatecontext);
echo $OUTPUT->footer();
