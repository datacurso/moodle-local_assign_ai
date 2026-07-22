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

namespace local_assign_ai\grading;

use assign;
use grading_manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/grade/grading/lib.php');

/**
 * Applies AI feedback to Moodle grading data.
 *
 * @package     local_assign_ai
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feedback_applier {
    /**
     * Applies AI feedback (grade + comments) to a submission.
     *
     * This is the main dispatcher that identifies the grading method and calls
     * the appropriate handler.
     *
     * @param assign $assign The assignment instance.
     * @param \stdClass $record The pending AI record.
     * @param int $graderid The user ID applying the change.
     * @return void
     */
    public static function apply_ai_feedback(assign $assign, \stdClass $record, int $graderid): void {
        $debugmsg = '';
        $debugmsg .= "local_assign_ai_apply_ai_feedback: inicio.\n";

        // Target the attempt the AI actually reviewed; the default (-1) would bind
        // the grade to the student's latest submission attempt instead.
        $grade = $assign->get_user_grade($record->userid, true, (int) ($record->attemptnumber ?? -1));
        if (!$grade) {
            $debugmsg .= "No grade para userid={$record->userid}.\n";
            debugging($debugmsg, DEBUG_DEVELOPER);
            debugging("No grade exists for userid={$record->userid}.", DEBUG_DEVELOPER);
            return;
        }

        $gradepushed = false;
        $gradingmanager = get_grading_manager($assign->get_context(), 'mod_assign', 'submissions');
        $method = $gradingmanager->get_active_method();

        $debugmsg .= "Metodo activo: {$method}.\n";
        $debugmsg .= "rubric_response presente: " . (!empty($record->rubric_response) ? 'si' : 'no') . ".\n";
        $debugmsg .= "assessment_guide_response presente: " . (!empty($record->assessment_guide_response) ? 'si' : 'no') . ".\n";

        if ($method === 'rubric' && !empty($record->rubric_response)) {
            $debugmsg .= "Ruta rubric seleccionada.\n";
            $gradepushed = self::apply_rubric_grading($assign, $grade, $record, $graderid, $gradingmanager);
            $debugmsg .= "Resultado rubric: " . ($gradepushed ? 'ok' : 'fallo') . ".\n";
        } else if ($method === 'guide' && !empty($record->assessment_guide_response)) {
            $debugmsg .= "Ruta guide seleccionada.\n";
            $gradepushed = self::apply_guide_grading($assign, $grade, $record, $graderid, $gradingmanager);
            $debugmsg .= "Resultado guide: " . ($gradepushed ? 'ok' : 'fallo') . ".\n";
        } else {
            $debugmsg .= "No se selecciono ruta avanzada.\n";
        }

        // Default to simple grading if no advanced grading was successful or used.
        if (!$gradepushed) {
            $debugmsg .= "Aplicando calificacion simple.\n";
            $gradepushed = self::apply_simple_grading($assign, $grade, $record, $graderid);
            $debugmsg .= "Resultado simple: " . ($gradepushed ? 'ok' : 'fallo') . ".\n";
        }

        // Always save feedback comments regardless of the grading method.
        self::save_feedback_comments($assign, $grade, $record->message ?? null);

        $debugmsg .= "Fin apply_ai_feedback.\n";
        debugging($debugmsg, DEBUG_DEVELOPER);

        // Trigger event if not already pushed (though update_grade usually triggers it).
        if (!$gradepushed) {
            $event = \mod_assign\event\submission_graded::create_from_grade($assign, $grade);
            $event->trigger();
        }
    }

    /**
     * Handles rubric grading application.
     *
     * @param assign $assign The assignment instance.
     * @param \stdClass $grade The user grade record.
     * @param \stdClass $record The pending AI record.
     * @param int $graderid The user ID applying the change.
     * @param grading_manager $gradingmanager The grading manager.
     * @return bool True on success, false otherwise.
     */
    public static function apply_rubric_grading(
        assign $assign,
        \stdClass $grade,
        \stdClass $record,
        int $graderid,
        grading_manager $gradingmanager
    ): bool {
        $controller = $gradingmanager->get_controller('rubric');

        // Set grade range.
        $grademenu = advanced_grading::get_grade_menu($assign);
        $controller->set_grade_range($grademenu, $controller->get_allow_grade_decimals());

        $definition = $controller->get_definition();
        $rubricdata = json_decode($record->rubric_response, true);

        if (!$definition || empty($rubricdata) || !is_array($rubricdata)) {
            return false;
        }

        $instance = $controller->get_or_create_instance(0, $graderid, $grade->id);
        $fillingdata = ['criteria' => []];
        $moodlecriteria = $definition->rubric_criteria;

        foreach ($rubricdata as $criteriondata) {
            $criteriondesc = trim($criteriondata['criterion'] ?? '');
            $levels = $criteriondata['levels'] ?? [];

            if (empty($levels) || $criteriondesc === '') {
                continue;
            }

            $aiclean = trim(strip_tags($criteriondesc));

            foreach ($moodlecriteria as $criterionid => $criterion) {
                $moodleclean = trim(strip_tags($criterion['description']));

                if ($moodleclean === $aiclean) {
                    $leveldata = reset($levels);
                    $points = (float) ($leveldata['points'] ?? 0);
                    $remark = $leveldata['comment'] ?? '';

                    foreach ($criterion['levels'] as $levelid => $level) {
                        $levelscore = (float) $level['score'];
                        if (abs($levelscore - $points) < 0.0001) {
                            $fillingdata['criteria'][$criterionid] = [
                                'levelid' => $levelid,
                                'remark' => $remark,
                            ];
                            break;
                        }
                    }
                    break;
                }
            }
        }

        if (empty($fillingdata['criteria'])) {
            return false;
        }

        try {
            $grade->grade = $instance->submit_and_get_grade($fillingdata, $grade->id);
            $grade->grader = $graderid;
            self::advance_marking_workflow($assign, $record->userid);
            return $assign->update_grade($grade);
        } catch (\Exception $e) {
            debugging("local_assign_ai: Rubric error: " . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Handles grading guide application.
     *
     * @param assign $assign The assignment instance.
     * @param \stdClass $grade The user grade record.
     * @param \stdClass $record The pending AI record.
     * @param int $graderid The user ID applying the change.
     * @param grading_manager $gradingmanager The grading manager.
     * @return bool True on success, false otherwise.
     */
    public static function apply_guide_grading(
        assign $assign,
        \stdClass $grade,
        \stdClass $record,
        int $graderid,
        grading_manager $gradingmanager
    ): bool {
        $debugmsg = '';
        $debugmsg .= "local_assign_ai_apply_guide_grading: inicio.\n";
        $debugmsg .= "assessment_guide_response length: " .
            (isset($record->assessment_guide_response) ? strlen((string) $record->assessment_guide_response) : 0) . ".\n";
        $controller = $gradingmanager->get_controller('guide');

        $grademenu = advanced_grading::get_grade_menu($assign);
        $controller->set_grade_range($grademenu, $controller->get_allow_grade_decimals());

        $definition = $controller->get_definition();
        $guidedata = json_decode($record->assessment_guide_response, true);

        if (!$definition || empty($guidedata) || !is_array($guidedata)) {
            $debugmsg .= "Guide sin definicion o guidata invalida.\n";
            $debugmsg .= "definition: " . (!empty($definition) ? 'ok' : 'null') . ".\n";
            $debugmsg .= "guidedata tipo: " . gettype($guidedata) . ".\n";
            $debugmsg .= "guidedata empty: " . (empty($guidedata) ? 'si' : 'no') . ".\n";
            debugging($debugmsg, DEBUG_DEVELOPER);
            return false;
        }

        $instance = $controller->get_or_create_instance(0, $graderid, $grade->id);
        $fillingdata = ['criteria' => []];
        $moodlecriteria = $definition->guide_criteria;

        $debugmsg .= "Total criterios Moodle: " . (is_array($moodlecriteria) ? count($moodlecriteria) : 0) . ".\n";
        if (is_array($moodlecriteria)) {
            $i = 0;
            foreach ($moodlecriteria as $id => $criterion) {
                $shortname = trim(strip_tags($criterion['shortname'] ?? ''));
                $debugmsg .= "Moodle criterio[$id]: {$shortname}.\n";
                $i++;
                if ($i >= 20) {
                    $debugmsg .= "(Lista de criterios Moodle truncada a 20)\n";
                    break;
                }
            }
        }

        $debugmsg .= "Guidedata keys: " . implode(', ', array_keys($guidedata)) . ".\n";

        // Guidedata is keyed by criterion name: "Criterion A" => ["grade" => 10, "reply" => ["Good", "Comments"]].
        foreach ($guidedata as $aicriterionname => $item) {
            $aicriterionclean = trim(strip_tags($aicriterionname));
            $debugmsg .= "Procesando criterio AI: {$aicriterionclean}.\n";
            $debugmsg .= "Item AI keys: " .
                (is_array($item) ? implode(', ', array_keys($item)) : gettype($item)) . ".\n";
            $matched = false;

            // Find matching Moodle criterion.
            foreach ($moodlecriteria as $id => $criterion) {
                $moodlecriterionclean = trim(strip_tags($criterion['shortname']));

                if (strcasecmp($moodlecriterionclean, $aicriterionclean) === 0) {
                    $matched = true;
                    $score = (float) ($item['grade'] ?? 0);

                    $remark = '';
                    if (!empty($item['reply'])) {
                        if (is_array($item['reply'])) {
                            $remark = implode(', ', $item['reply']);
                        } else {
                            $remark = (string) $item['reply'];
                        }
                    }

                    $fillingdata['criteria'][$id] = [
                        'score' => $score,
                        'remark' => $remark,
                        'remarkformat' => FORMAT_HTML,
                    ];
                    $debugmsg .= "Match criterio: {$moodlecriterionclean}. score={$score}.\n";
                    break;
                }
            }

            if (!$matched) {
                $debugmsg .= "Sin match para criterio AI: {$aicriterionclean}.\n";
            }
        }

        if (empty($fillingdata['criteria'])) {
            $debugmsg .= "Sin criterios para enviar.\n";
            debugging($debugmsg, DEBUG_DEVELOPER);
            return false;
        }

        try {
            $grade->grade = $instance->submit_and_get_grade($fillingdata, $grade->id);
            $grade->grader = $graderid;
            self::advance_marking_workflow($assign, $record->userid);
            $debugmsg .= "Guide submit OK.\n";
            debugging($debugmsg, DEBUG_DEVELOPER);
            return $assign->update_grade($grade);
        } catch (\Exception $e) {
            $debugmsg .= "Guide exception: " . $e->getMessage() . "\n";
            debugging($debugmsg, DEBUG_DEVELOPER);
            debugging("local_assign_ai: Guide error: " . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Handles simple direct grading (numeric).
     *
     * @param assign $assign The assignment instance.
     * @param \stdClass $grade The user grade record.
     * @param \stdClass $record The pending AI record.
     * @param int $graderid The user ID applying the change.
     * @return bool True on success, false otherwise.
     */
    public static function apply_simple_grading(assign $assign, \stdClass $grade, \stdClass $record, int $graderid): bool {
        if ($record->grade === null || $record->grade === '') {
            return false;
        }

        $instancegrade = (float) $assign->get_instance()->grade;
        if ($instancegrade <= 0) {
            return false; // Scales not supported for automatic numeric grading yet.
        }

        $grade->grade = max(0, min((float) $record->grade, $instancegrade));
        $grade->grader = $graderid;

        self::advance_marking_workflow($assign, $record->userid);
        return $assign->update_grade($grade);
    }

    /**
     * Helper to advance the marking workflow state for a user to 'Released'.
     *
     * @param assign $assign The assignment instance.
     * @param int $userid The student user ID.
     * @return void
     */
    public static function advance_marking_workflow(assign $assign, int $userid): void {
        if ($assign->get_instance()->markingworkflow) {
            $flags = $assign->get_user_flags($userid, true);
            $flags->workflowstate = ASSIGN_MARKING_WORKFLOW_STATE_RELEASED;
            $assign->update_user_flags($flags);
        }
    }

    /**
     * Checks whether the comments feedback plugin is active for the assignment instance.
     *
     * Moodle requires the plugin to be both enabled for the instance and visible at
     * site level before rendering its feedback to students.
     *
     * @param assign $assign The assignment instance.
     * @return bool True if the comments feedback plugin is active.
     */
    public static function is_comments_plugin_active(assign $assign): bool {
        $plugin = $assign->get_feedback_plugin_by_type('comments');
        return $plugin && $plugin->is_enabled() && $plugin->is_visible();
    }

    /**
     * Helper to save feedback comments for a given submission.
     *
     * @param assign $assign The assignment instance.
     * @param \stdClass $grade The user grade record.
     * @param string|null $message The AI feedback message.
     * @return void
     */
    public static function save_feedback_comments(assign $assign, \stdClass $grade, ?string $message): void {
        global $DB;

        if (empty($message)) {
            return;
        }

        // Moodle only renders feedback from plugins that are enabled for the instance
        // and visible at site level, so writing while disabled would leave the comment
        // stored but never shown to the student.
        if (!self::is_comments_plugin_active($assign)) {
            debugging('local_assign_ai: feedback comments plugin disabled for this assignment, skipping.', DEBUG_DEVELOPER);
            return;
        }

        $feedback = $DB->get_record('assignfeedback_comments', ['grade' => $grade->id]);
        if ($feedback) {
            $feedback->commenttext = $message;
            $feedback->commentformat = FORMAT_HTML;
            $DB->update_record('assignfeedback_comments', $feedback);
        } else {
            $feedback = (object) [
                'assignment' => $assign->get_instance()->id,
                'grade' => $grade->id,
                'commenttext' => $message,
                'commentformat' => FORMAT_HTML,
            ];
            $DB->insert_record('assignfeedback_comments', $feedback);
        }
    }
}
