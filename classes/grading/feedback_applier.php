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
     * @throws \moodle_exception When the assignment uses advanced grading (rubric or
     *                           marking guide) and the AI response cannot be applied
     *                           to it — simple grading is never used as a fallback.
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

        $definition = null;
        if ($method === 'rubric' || $method === 'guide') {
            $definition = $gradingmanager->get_controller($method)->get_definition();
        }

        if ($definition) {
            // Advanced grading is configured for this assignment: it is the only
            // acceptable grading path. Falling back to simple grading would record
            // a number without any evaluated criteria, hiding the problem from the
            // teacher, so every failure here must surface as an exception.
            $response = $method === 'rubric'
                ? ($record->rubric_response ?? null)
                : ($record->assessment_guide_response ?? null);
            if (empty($response)) {
                debugging($debugmsg, DEBUG_DEVELOPER);
                throw new \moodle_exception('error_advancedresponsemissing', 'local_assign_ai', '', $method);
            }

            if ($method === 'rubric') {
                $gradepushed = self::apply_rubric_grading($assign, $grade, $record, $graderid, $gradingmanager);
            } else {
                $gradepushed = self::apply_guide_grading($assign, $grade, $record, $graderid, $gradingmanager);
            }

            if (!$gradepushed) {
                debugging($debugmsg, DEBUG_DEVELOPER);
                throw new \moodle_exception('unexpectederror', 'local_assign_ai', '', 'advanced grading could not be applied');
            }
        } else {
            // No advanced grading definition: the assignment grades with a simple
            // number (matches Moodle behaviour when a method is selected but the
            // form was never defined).
            $debugmsg .= "Sin definicion avanzada: calificacion simple.\n";
            $gradepushed = self::apply_simple_grading($assign, $grade, $record, $graderid);
            $debugmsg .= "Resultado simple: " . ($gradepushed ? 'ok' : 'fallo') . ".\n";
        }

        // Queue the standard mod_assign "feedback available" notification (sent by
        // cron), honouring the assignment's "Notify students" setting.
        if ($gradepushed && !empty($assign->get_instance()->sendstudentnotifications)) {
            $assign->notify_grade_modified($grade, true);
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
     * Resolves the AI rubric criteria against the Moodle rubric definition.
     *
     * The record's matching mode is detected first:
     *  - 'strict': at least one AI criterion carries a numeric Moodle id. Every criterion
     *    must then resolve through its criterion id and its first level id; the name and
     *    the points are never used, not even for criteria lacking an id in a mixed record.
     *  - 'legacy': no AI criterion carries an id (records stored before the AI service
     *    stamped ids). The criterion resolves by normalized name, the level by points.
     *
     * Each entry under 'criteria' contains:
     *  - criterion (string): AI criterion name as received.
     *  - points (float|null): points of the first AI level, when present.
     *  - comment (string): remark of the first AI level.
     *  - criterionid (int|null): resolved Moodle criterion id, or null when unresolved.
     *  - criterionmatch (string|null): 'id' or 'name' when resolved, null otherwise.
     *  - levelid (int|null): resolved Moodle level id, or null when unresolved.
     *  - levelmatch (string|null): 'id' or 'points' when resolved, null otherwise.
     *  - failure (string|null): null when fully resolved, otherwise the reason:
     *    'missing_criterion_id', 'unknown_criterion_id', 'missing_level_id' or
     *    'unknown_level_id' in strict mode; 'name_not_found' or 'points_not_found'
     *    in legacy mode.
     *
     * @param array $rubricdata Decoded rubric_response entries ([{id?, criterion, levels: [{id?, points, comment}]}]).
     * @param array $moodlecriteria Rubric definition criteria indexed by criterion id (rubric_criteria).
     * @return array ['mode' => 'strict'|'legacy', 'criteria' => array] with one entry per AI criterion.
     */
    public static function resolve_rubric_criteria(array $rubricdata, array $moodlecriteria): array {
        $strict = false;
        foreach ($rubricdata as $criteriondata) {
            if (is_array($criteriondata) && isset($criteriondata['id']) && is_numeric($criteriondata['id'])) {
                $strict = true;
                break;
            }
        }

        $results = [];

        foreach ($rubricdata as $criteriondata) {
            if (!is_array($criteriondata)) {
                $criteriondata = [];
            }

            $ainame = trim((string) ($criteriondata['criterion'] ?? ''));
            $levels = $criteriondata['levels'] ?? [];
            $leveldata = (is_array($levels) && !empty($levels)) ? reset($levels) : null;
            $leveldata = is_array($leveldata) ? $leveldata : null;
            $aipoints = ($leveldata !== null && isset($leveldata['points']) && is_numeric($leveldata['points']))
                ? (float) $leveldata['points']
                : null;

            $result = [
                'criterion' => $ainame,
                'points' => $aipoints,
                'comment' => (string) ($leveldata['comment'] ?? ''),
                'criterionid' => null,
                'criterionmatch' => null,
                'levelid' => null,
                'levelmatch' => null,
                'failure' => null,
            ];

            $result = $strict
                ? self::resolve_criterion_strict($criteriondata, $leveldata, $moodlecriteria, $result)
                : self::resolve_criterion_legacy($ainame, $aipoints, $moodlecriteria, $result);

            $results[] = $result;
        }

        return [
            'mode' => $strict ? 'strict' : 'legacy',
            'criteria' => $results,
        ];
    }

    /**
     * Resolves one AI criterion strictly by its Moodle ids (no name/points fallback).
     *
     * @param array $criteriondata Raw AI criterion entry.
     * @param array|null $leveldata First AI level entry, when present.
     * @param array $moodlecriteria Rubric definition criteria indexed by criterion id.
     * @param array $result Base resolution entry to complete.
     * @return array The completed resolution entry.
     */
    private static function resolve_criterion_strict(
        array $criteriondata,
        ?array $leveldata,
        array $moodlecriteria,
        array $result
    ): array {
        $aicriterionid = (isset($criteriondata['id']) && is_numeric($criteriondata['id']))
            ? (int) $criteriondata['id']
            : null;
        if ($aicriterionid === null) {
            $result['failure'] = 'missing_criterion_id';
            return $result;
        }
        if (!isset($moodlecriteria[$aicriterionid])) {
            $result['failure'] = 'unknown_criterion_id';
            return $result;
        }

        $result['criterionid'] = $aicriterionid;
        $result['criterionmatch'] = 'id';

        $ailevelid = ($leveldata !== null && isset($leveldata['id']) && is_numeric($leveldata['id']))
            ? (int) $leveldata['id']
            : null;
        if ($ailevelid === null) {
            $result['failure'] = 'missing_level_id';
            return $result;
        }

        $criterionlevels = $moodlecriteria[$aicriterionid]['levels'] ?? [];
        if (!isset($criterionlevels[$ailevelid])) {
            $result['failure'] = 'unknown_level_id';
            return $result;
        }

        $result['levelid'] = $ailevelid;
        $result['levelmatch'] = 'id';
        return $result;
    }

    /**
     * Resolves one AI criterion by normalized name and level points (legacy records).
     *
     * @param string $ainame Trimmed AI criterion name.
     * @param float|null $aipoints Points of the first AI level, when present.
     * @param array $moodlecriteria Rubric definition criteria indexed by criterion id.
     * @param array $result Base resolution entry to complete.
     * @return array The completed resolution entry.
     */
    private static function resolve_criterion_legacy(
        string $ainame,
        ?float $aipoints,
        array $moodlecriteria,
        array $result
    ): array {
        if ($ainame !== '') {
            $normalizedainame = self::normalize_criterion_name($ainame);
            foreach ($moodlecriteria as $criterionid => $criterion) {
                if (self::normalize_criterion_name((string) ($criterion['description'] ?? '')) === $normalizedainame) {
                    $result['criterionid'] = (int) $criterionid;
                    $result['criterionmatch'] = 'name';
                    break;
                }
            }
        }
        if ($result['criterionid'] === null) {
            $result['failure'] = 'name_not_found';
            return $result;
        }

        if ($aipoints !== null) {
            $criterionlevels = $moodlecriteria[$result['criterionid']]['levels'] ?? [];
            foreach ($criterionlevels as $levelid => $level) {
                if (abs((float) $level['score'] - $aipoints) < 0.0001) {
                    $result['levelid'] = (int) $levelid;
                    $result['levelmatch'] = 'points';
                    break;
                }
            }
        }
        if ($result['levelid'] === null) {
            $result['failure'] = 'points_not_found';
        }

        return $result;
    }

    /**
     * Normalizes a criterion name for the legacy name-based matching.
     *
     * Applies the same transformation to both the AI and the Moodle side: strip tags,
     * collapse every whitespace run (including \r\n) to a single space, remove accents
     * and lowercase, so names mangled by the LLM still match their criterion.
     *
     * @param string $name Raw criterion name/description.
     * @return string Normalized name.
     */
    private static function normalize_criterion_name(string $name): string {
        $name = trim(strip_tags($name));
        $name = preg_replace('/\s+/u', ' ', $name);
        $name = \core_text::specialtoascii($name);
        return \core_text::strtolower($name);
    }

    /**
     * Handles rubric grading application.
     *
     * @param assign $assign The assignment instance.
     * @param \stdClass $grade The user grade record.
     * @param \stdClass $record The pending AI record.
     * @param int $graderid The user ID applying the change.
     * @param grading_manager $gradingmanager The grading manager.
     * @return bool True on success.
     * @throws \moodle_exception When the response cannot be parsed or criteria do not match.
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
            throw new \moodle_exception('errorparsingrubric', 'local_assign_ai', '', 'empty or invalid JSON');
        }

        $instance = $controller->get_or_create_instance(0, $graderid, $grade->id);
        $fillingdata = ['criteria' => []];
        $moodlecriteria = $definition->rubric_criteria;
        $unmatched = [];

        $resolved = self::resolve_rubric_criteria($rubricdata, $moodlecriteria);
        foreach ($resolved['criteria'] as $resolution) {
            if ($resolution['failure'] !== null) {
                $name = $resolution['criterion'] !== '' ? $resolution['criterion'] : '(unnamed)';
                $unmatched[] = $name . ' (' . $resolution['failure'] . ')';
                continue;
            }

            $fillingdata['criteria'][$resolution['criterionid']] = [
                'levelid' => $resolution['levelid'],
                'remark' => $resolution['comment'],
            ];
        }

        // Every rubric criterion must be filled: unmatched AI criteria or Moodle
        // criteria left without a level mean the rubric cannot be applied.
        foreach ($moodlecriteria as $criterionid => $criterion) {
            if (!isset($fillingdata['criteria'][$criterionid])) {
                $unmatched[] = trim(strip_tags($criterion['description'])) . ' (not_evaluated)';
            }
        }

        if (!empty($unmatched)) {
            // Persist the mode and the per-criterion failure reasons in the exception
            // message so the pending record's errormessage carries the full diagnosis.
            $detail = '[mode: ' . $resolved['mode'] . '] ' . implode('; ', array_unique($unmatched));
            throw new \moodle_exception('error_rubricmismatch', 'local_assign_ai', '', $detail);
        }

        try {
            $grade->grade = $instance->submit_and_get_grade($fillingdata, $grade->id);
            $grade->grader = $graderid;
            self::advance_marking_workflow($assign, $record->userid);
            return $assign->update_grade($grade);
        } catch (\moodle_exception $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new \moodle_exception('unexpectederror', 'local_assign_ai', '', $e->getMessage());
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
     * @return bool True on success.
     * @throws \moodle_exception When the response cannot be parsed or criteria do not match.
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
            throw new \moodle_exception('errorparsingguide', 'local_assign_ai', '', 'empty or invalid JSON');
        }

        $instance = $controller->get_or_create_instance(0, $graderid, $grade->id);
        $fillingdata = ['criteria' => []];
        $unmatched = [];
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
                $unmatched[] = $aicriterionclean;
            }
        }

        // Every guide criterion must be filled: unmatched AI criteria or Moodle
        // criteria left without a score mean the guide cannot be applied.
        foreach ($moodlecriteria as $id => $criterion) {
            if (!isset($fillingdata['criteria'][$id])) {
                $unmatched[] = trim(strip_tags($criterion['shortname']));
            }
        }

        if (!empty($unmatched)) {
            $debugmsg .= "Criterios sin match: " . implode(', ', $unmatched) . ".\n";
            debugging($debugmsg, DEBUG_DEVELOPER);
            throw new \moodle_exception('error_guidemismatch', 'local_assign_ai', '', implode(', ', array_unique($unmatched)));
        }

        try {
            $grade->grade = $instance->submit_and_get_grade($fillingdata, $grade->id);
            $grade->grader = $graderid;
            self::advance_marking_workflow($assign, $record->userid);
            $debugmsg .= "Guide submit OK.\n";
            debugging($debugmsg, DEBUG_DEVELOPER);
            return $assign->update_grade($grade);
        } catch (\moodle_exception $e) {
            debugging($debugmsg, DEBUG_DEVELOPER);
            throw $e;
        } catch (\Exception $e) {
            $debugmsg .= "Guide exception: " . $e->getMessage() . "\n";
            debugging($debugmsg, DEBUG_DEVELOPER);
            throw new \moodle_exception('unexpectederror', 'local_assign_ai', '', $e->getMessage());
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
        if ($instancegrade < 0) {
            // Scale: translate the AI numeric value to a valid 1-based scale index.
            $scalemenu = advanced_grading::get_grade_menu($assign);
            $n = count($scalemenu);
            if ($n < 1) {
                return false;
            }
            $grade->grade = max(1, min((int) round((float) $record->grade), $n));
        } else if ($instancegrade > 0) {
            $grade->grade = max(0, min((float) $record->grade, $instancegrade));
        } else {
            return false; // "No grade" type: nothing to apply.
        }

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
