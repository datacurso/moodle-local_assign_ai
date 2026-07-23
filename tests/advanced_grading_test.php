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
 * Tests for the advanced grading helpers.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai;

use local_assign_ai\grading\advanced_grading;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Unit tests for the advanced grading helpers.
 *
 * @coversDefaultClass \local_assign_ai\grading\advanced_grading
 * @group local_assign_ai
 */
final class advanced_grading_test extends \advanced_testcase {
    /**
     * Creates a course with an assignment and returns the assign object plus its module context.
     *
     * @return array The assign object and the module context.
     */
    private function create_assign(): array {
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $instance->id);
        $context = \context_module::instance($cm->id);

        return [
            new \assign($context, $cm, $course),
            $context,
        ];
    }

    /**
     * MDL-UNIT-003: A rubric definition serializes with its criteria, levels and scores.
     *
     * @covers ::get_definition_json
     */
    public function test_rubric_definition_serializes_criteria_levels_and_scores(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$assign, $context] = $this->create_assign();

        $rubricgenerator = $this->getDataGenerator()->get_plugin_generator('gradingform_rubric');
        $rubricgenerator->create_instance(
            $context,
            'mod_assign',
            'submissions',
            'Essay rubric',
            'Rubric used to grade the essay',
            [
                'Spelling' => [
                    'Several mistakes' => 0,
                    'No mistakes' => 2,
                ],
                'Structure' => [
                    'Poor structure' => 0,
                    'Clear structure' => 3,
                ],
            ]
        );

        $result = advanced_grading::get_definition_json($assign);

        $this->assertNotNull($result);
        $this->assertSame('rubric', $result['method']);
        $this->assertSame('Essay rubric', $result['data']['title']);
        $this->assertCount(2, $result['data']['criteria']);

        $criteria = array_column($result['data']['criteria'], null, 'criterion');
        $this->assertArrayHasKey('Spelling', $criteria);
        $this->assertArrayHasKey('Structure', $criteria);

        $spellinglevels = array_column($criteria['Spelling']['levels'], 'points', 'description');
        $this->assertSame(0.0, $spellinglevels['Several mistakes']);
        $this->assertSame(2.0, $spellinglevels['No mistakes']);

        $structurelevels = array_column($criteria['Structure']['levels'], 'points', 'description');
        $this->assertSame(0.0, $structurelevels['Poor structure']);
        $this->assertSame(3.0, $structurelevels['Clear structure']);
    }

    /**
     * MDL-UNIT-003: A marking guide definition serializes with criteria, descriptions and maximum scores.
     *
     * @covers ::get_definition_json
     */
    public function test_guide_definition_serializes_criteria_descriptions_and_max_scores(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$assign, $context] = $this->create_assign();

        $guidegenerator = $this->getDataGenerator()->get_plugin_generator('gradingform_guide');
        $guidegenerator->create_instance(
            $context,
            'mod_assign',
            'submissions',
            'Essay marking guide',
            'Guide used to grade the essay',
            [
                'Spelling' => [
                    'description' => 'Deduct one point per spelling mistake.',
                    'descriptionmarkers' => 'Check your spelling before submitting.',
                    'maxscore' => 25,
                ],
                'Structure' => [
                    'description' => 'Award full marks for a clear introduction, body and conclusion.',
                    'descriptionmarkers' => 'Organise your essay in introduction, body and conclusion.',
                    'maxscore' => 15,
                ],
            ]
        );

        $result = advanced_grading::get_definition_json($assign);

        $this->assertNotNull($result);
        $this->assertSame('guide', $result['method']);
        $this->assertSame('Essay marking guide', $result['data']['title']);
        $this->assertCount(2, $result['data']['criteria']);

        $criteria = array_column($result['data']['criteria'], null, 'criterion');
        $this->assertArrayHasKey('Spelling', $criteria);
        $this->assertArrayHasKey('Structure', $criteria);

        $this->assertSame('Check your spelling before submitting.', $criteria['Spelling']['description_students']);
        $this->assertSame('Deduct one point per spelling mistake.', $criteria['Spelling']['description_evaluators']);
        $this->assertSame(25.0, $criteria['Spelling']['maximum_score']);
        $this->assertSame(15.0, $criteria['Structure']['maximum_score']);
    }

    /**
     * MDL-UNIT-003: An assignment without an active advanced grading method serializes to null.
     *
     * @covers ::get_definition_json
     */
    public function test_assignment_without_advanced_grading_returns_null(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$assign] = $this->create_assign();

        $this->assertNull(advanced_grading::get_definition_json($assign));
    }
}
