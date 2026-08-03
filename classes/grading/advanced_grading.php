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
 * Advanced grading helpers for local_assign_ai.
 *
 * @package     local_assign_ai
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class advanced_grading {
    /**
     * Converts the assignment advanced grading (rubric or guide) into simplified JSON.
     *
     * @param assign $assign The assignment instance.
     * @return array|null An array containing the method and the formatted data, or null if not active.
     */
    public static function get_definition_json(assign $assign): ?array {
        $context = $assign->get_context();
        $gradingmanager = get_grading_manager($context, 'mod_assign', 'submissions');
        $method = $gradingmanager->get_active_method();

        if (empty($method)) {
            return null;
        }

        $controller = $gradingmanager->get_controller($method);
        if (!$controller) {
            return null;
        }

        $definition = $controller->get_definition();
        if (empty($definition)) {
            return null;
        }

        if ($method === 'rubric' && !empty($definition->rubric_criteria)) {
            $data = [
                'title' => $definition->name ?? '',
                'description' => $definition->description ?? '',
                'criteria' => [],
            ];

            foreach ($definition->rubric_criteria as $criterionid => $criterion) {
                $crit = [
                    'id' => $criterionid,
                    'criterion' => $criterion['description'],
                    'levels' => [],
                ];

                foreach ($criterion['levels'] as $levelid => $level) {
                    $crit['levels'][] = [
                        'id' => $levelid,
                        'points' => (float) $level['score'],
                        'description' => $level['definition'],
                    ];
                }
                $data['criteria'][] = $crit;
            }
            return ['method' => 'rubric', 'data' => $data];
        } else if ($method === 'guide' && !empty($definition->guide_criteria)) {
            $data = [
                'title' => $definition->name ?? '',
                'description' => $definition->description ?? '',
                'criteria' => [],
                'predefined_comments' => [],
            ];

            foreach ($definition->guide_criteria as $criterionid => $criterion) {
                $data['criteria'][] = [
                    'id' => $criterionid,
                    'criterion' => $criterion['shortname'],
                    'description_students' => $criterion['descriptionmarkers'],
                    'description_evaluators' => $criterion['description'],
                    'maximum_score' => (float) $criterion['maxscore'],
                ];
            }

            if (!empty($definition->guide_comments)) {
                foreach ($definition->guide_comments as $comment) {
                    $data['predefined_comments'][] = $comment['description'];
                }
            }
            return ['method' => 'guide', 'data' => $data];
        }

        return null;
    }

    /**
     * Helper to get the grade menu or scale for a grading controller.
     *
     * @param assign $assign The assignment instance.
     * @return array The grade menu or scale map.
     */
    public static function get_grade_menu(assign $assign): array {
        global $DB;

        $grademenu = [];
        $instancegrade = $assign->get_instance()->grade;
        if ($instancegrade > 0) {
            $grademenu = make_grades_menu($instancegrade);
        } else if ($instancegrade < 0) {
            $scale = $DB->get_record('scale', ['id' => -($instancegrade)]);
            if ($scale) {
                $grademenu = make_menu_from_list($scale->scale);
            }
        }
        return $grademenu;
    }

    /**
     * Builds rubric response JSON from the current grading instance.
     *
     * @param \stdClass $grade The user grade record.
     * @param grading_manager $gradingmanager The grading manager.
     * @return string|null JSON string or null if unavailable.
     */
    public static function build_rubric_response(\stdClass $grade, grading_manager $gradingmanager): ?string {
        $controller = $gradingmanager->get_controller('rubric');
        if (!$controller) {
            return null;
        }

        $definition = $controller->get_definition();
        $instance = $controller->get_current_instance(0, $grade->id);
        if (!$definition || !$instance) {
            return null;
        }

        $filling = $instance->get_rubric_filling();
        $criteria = $definition->rubric_criteria;
        $data = [];

        foreach ($filling['criteria'] as $criterionid => $filled) {
            if (empty($criteria[$criterionid])) {
                continue;
            }
            $criterion = $criteria[$criterionid];
            $levelid = $filled['levelid'] ?? null;
            if (!$levelid || empty($criterion['levels'][$levelid])) {
                continue;
            }
            $level = $criterion['levels'][$levelid];
            $comment = $filled['remark'] ?? '';

            $data[] = [
                'id' => (int) $criterionid,
                'criterion' => $criterion['description'],
                'levels' => [[
                    'id' => (int) $levelid,
                    'points' => (float) $level['score'],
                    'comment' => $comment,
                ]],
            ];
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Builds guide response JSON from the current grading instance.
     *
     * @param \stdClass $grade The user grade record.
     * @param grading_manager $gradingmanager The grading manager.
     * @return string|null JSON string or null if unavailable.
     */
    public static function build_guide_response(\stdClass $grade, grading_manager $gradingmanager): ?string {
        $controller = $gradingmanager->get_controller('guide');
        if (!$controller) {
            return null;
        }

        $definition = $controller->get_definition();
        $instance = $controller->get_current_instance(0, $grade->id);
        if (!$definition || !$instance) {
            return null;
        }

        $filling = $instance->get_guide_filling();
        $criteria = $definition->guide_criteria;
        $data = [];

        foreach ($filling['criteria'] as $criterionid => $filled) {
            if (empty($criteria[$criterionid])) {
                continue;
            }
            $criterion = $criteria[$criterionid];
            $shortname = $criterion['shortname'] ?? '';
            if ($shortname === '') {
                continue;
            }
            $data[$shortname] = [
                'grade' => (float) ($filled['score'] ?? 0),
                'reply' => $filled['remark'] ?? '',
            ];
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
