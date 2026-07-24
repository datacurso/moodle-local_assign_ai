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
 * Review page for local_assign_ai.
 *
 * @package     local_assign_ai
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_assign_ai\output\header_logo;
use local_assign_ai\table\review_table;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

try {
    $cmid = required_param('id', PARAM_INT);

    // Get the course module and the course.
    $cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $context = context_module::instance($cm->id);

    // Save login state and check permissions.
    require_login($course, true, $cm);

    // Verify that the user has the capability to review AI suggestions for this assignment.
    if (!has_capability('local/assign_ai:review', $context)) {
        $courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
        throw new moodle_exception(
            'nopermissions',
            'error',
            $courseurl,
            get_string('assign_ai:review', 'local_assign_ai')
        );
    }

    // Instantiate the assign object.
    $assign = new assign($context, $cm, $course);

    // Page configuration.
    $PAGE->set_url(new moodle_url('/local/assign_ai/review.php', ['id' => $cmid]));
    $PAGE->set_course($course);
    $PAGE->set_context($context);
    $PAGE->set_title(get_string('reviewwithai', 'local_assign_ai'));
    $PAGE->set_heading(format_string($course->fullname));
    $PAGE->requires->js_call_amd('local_assign_ai/review', 'init');
    $PAGE->requires->js_call_amd('local_assign_ai/review_with_ai', 'init');
    $PAGE->requires->js_call_amd('local_assign_ai/review_progress', 'init', [$cmid]);
    $PAGE->requires->css('/local/assign_ai/styles/review.css');

    $PAGE->activityheader->disable();

    echo $OUTPUT->header();

    $pendingcount = $DB->count_records('local_assign_ai_pending', [
        'courseid' => $course->id,
        'assignmentid' => $cm->id,
        'status' => 'initial',
    ]);
    $allblocked = ($pendingcount === 0);
    $hasinitial = ($pendingcount > 0);

    $pendingforapprove = $DB->count_records('local_assign_ai_pending', [
        'courseid' => $course->id,
        'assignmentid' => $cm->id,
        'status' => 'pending',
    ]);
    $haspending = ($pendingforapprove > 0);

    // Verify capabilities for this user to control button visibility.
    $canchangestatus = has_capability('local/assign_ai:changestatus', $context);
    $canviewdetails = has_capability('local/assign_ai:viewdetails', $context);

    $table = new review_table('local_assign_ai_review_' . $cmid, $assign);
    ob_start();
    $table->out(20, false);
    $tablehtml = ob_get_clean();

    $templatecontext = [
        'backurl' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
        'allblocked' => $allblocked,
        'hasinitial' => $hasinitial,
        'haspending' => $haspending,
        'cmid' => $cmid,
        'courseid' => $course->id,
        'alttext' => get_string('altlogo', 'local_assign_ai'),
        'canchangestatus' => $canchangestatus,
        'canviewdetails' => $canviewdetails,
        'tablehtml' => $tablehtml,
        'headerlogo' => (new header_logo())->export_for_template($OUTPUT),
    ];

    echo $OUTPUT->render_from_template('local_assign_ai/review_page', $templatecontext);
    echo $OUTPUT->footer();
} catch (moodle_exception $e) {
    // Las moodle_exception ya manejan su propio renderizado y redirección.
    // No intentar mostrar footer aquí para evitar conflictos de estado.
    throw $e;
} catch (Exception $e) {
    // Solo para excepciones inesperadas que NO son de permisos.
    // Si el header ya se mostró, intentar mostrar footer. Si no, dejar que Moodle maneje el error.
    if ($PAGE->state >= 2) {
        // Header ya se mostró, podemos intentar footer.
        \core\notification::error(get_string('unexpectederror', 'local_assign_ai', $e->getMessage()));
        echo $OUTPUT->footer();
    } else {
        // Página no iniciada, redirigir con error.
        throw $e;
    }
}
