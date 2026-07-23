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
 * Tests for the AI feedback applier.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai;

use local_assign_ai\grading\feedback_applier;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');
require_once($CFG->dirroot . '/grade/grading/lib.php');

/**
 * Unit tests for the AI feedback applier.
 *
 * @coversDefaultClass \local_assign_ai\grading\feedback_applier
 * @group local_assign_ai
 */
final class feedback_applier_test extends \advanced_testcase {
    use \mod_assign_test_generator;

    /**
     * Bump the assign instance id sequence past the given id.
     *
     * assignment_config keeps a per-process static cache keyed by assignment id while the PHPUnit
     * database reset reuses ids across tests, so each test claims a process-unique id range to
     * guarantee it operates on an assignment id no other test has cached.
     *
     * @param int $id Filler id imported directly into the assign table.
     * @param int $courseid Course id used by the filler row.
     * @return void
     */
    private function bump_assign_sequence(int $id, int $courseid): void {
        global $DB;

        $DB->import_record('assign', (object) [
            'id' => $id,
            'course' => $courseid,
            'name' => 'filler',
            'intro' => '',
            'introformat' => FORMAT_HTML,
        ]);
        $DB->get_manager()->reset_sequence('assign');
    }

    /**
     * Insert a pending AI record for the given student, as stored by the plugin pipeline.
     *
     * @param \assign $assign The assignment instance.
     * @param \stdClass $student The student the AI feedback belongs to.
     * @param array $overrides Column overrides (grade, message, rubric_response, ...).
     * @return \stdClass The inserted record.
     */
    private function create_pending_record(\assign $assign, \stdClass $student, array $overrides = []): \stdClass {
        global $DB;

        $now = time();
        $record = (object) array_merge([
            'courseid' => $assign->get_course()->id,
            'assignmentid' => $assign->get_course_module()->id,
            'title' => $assign->get_instance()->name,
            'userid' => $student->id,
            'submissionid' => null,
            'attemptnumber' => 0,
            'submissionmodified' => $now,
            'edited' => 0,
            'message' => 'AI feedback message',
            'grade' => null,
            'rubric_response' => null,
            'assessment_guide_response' => null,
            'status' => 'pending',
            'approval_token' => md5('local_assign_ai_test_' . $student->id . '_' . $now),
            'usermodified' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ], $overrides);
        $record->id = $DB->insert_record('local_assign_ai_pending', $record);

        return $record;
    }

    /**
     * Read the advanced grading fillings stored for a user grade.
     *
     * @param \stdClass $grade The assign user grade record.
     * @param string $table Fillings table name (without prefix).
     * @return array Filling rows indexed by criterion id.
     */
    private function get_fillings(\stdClass $grade, string $table): array {
        global $DB;

        $instance = $DB->get_record('grading_instances', ['itemid' => $grade->id], '*', MUST_EXIST);

        $fillings = [];
        foreach ($DB->get_records($table, ['instanceid' => $instance->id]) as $filling) {
            $fillings[$filling->criterionid] = $filling;
        }

        return $fillings;
    }

    /**
     * MDL-INT-005: A well-formed AI rubric response selects the matching level of every criterion,
     * stores the AI remarks and produces a total grade equal to the sum of the selected level points.
     *
     * @covers ::apply_ai_feedback
     * @covers ::apply_rubric_grading
     */
    public function test_rubric_response_selects_levels_remarks_and_totals_grade(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(4010, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);

        // Level points add up to 100 (the assignment maximum grade), so the rubric total maps 1:1.
        $this->getDataGenerator()->get_plugin_generator('gradingform_rubric')->create_instance(
            $assign->get_context(),
            'mod_assign',
            'submissions',
            'Essay rubric',
            'Rubric used to grade the essay',
            [
                'Spelling' => [
                    'Poor spelling' => 10,
                    'Good spelling' => 40,
                ],
                'Structure' => [
                    'Weak structure' => 20,
                    'Strong structure' => 60,
                ],
            ]
        );

        $this->add_submission($student, $assign, 'My essay text');

        $record = $this->create_pending_record($assign, $student, [
            'grade' => 60,
            'message' => 'Overall rubric feedback',
            'rubric_response' => json_encode([
                [
                    'criterion' => 'Spelling',
                    'levels' => [['points' => 40, 'comment' => 'Nice spelling']],
                ],
                [
                    'criterion' => 'Structure',
                    'levels' => [['points' => 20, 'comment' => 'Work on the structure']],
                ],
            ]),
        ]);

        feedback_applier::apply_ai_feedback($assign, $record, (int) $teacher->id);

        // The total grade is the sum of the selected level points (40 + 20 out of 100).
        $grade = $assign->get_user_grade($student->id, false);
        $this->assertNotEmpty($grade);
        $this->assertEquals(60.0, (float) $grade->grade);
        $this->assertEquals($teacher->id, $grade->grader);

        // Each criterion has the right level selected and the AI remark filled.
        $gradingmanager = get_grading_manager($assign->get_context(), 'mod_assign', 'submissions');
        $definition = $gradingmanager->get_controller('rubric')->get_definition();
        $fillings = $this->get_fillings($grade, 'gradingform_rubric_fillings');

        $expectedbycriterion = ['Spelling' => [40.0, 'Nice spelling'], 'Structure' => [20.0, 'Work on the structure']];
        foreach ($definition->rubric_criteria as $criterionid => $criterion) {
            [$points, $remark] = $expectedbycriterion[$criterion['description']];
            $this->assertArrayHasKey($criterionid, $fillings);
            $filling = $fillings[$criterionid];
            $this->assertEquals($points, (float) $criterion['levels'][$filling->levelid]['score']);
            $this->assertSame($remark, $filling->remark);
        }

        $this->resetDebugging();
    }

    /**
     * MDL-INT-005: Applying an AI rubric response also stores the AI message as feedback comments.
     *
     * @covers ::apply_ai_feedback
     * @covers ::save_feedback_comments
     */
    public function test_rubric_grading_saves_feedback_comment(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(4020, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignfeedback_comments_enabled' => 1,
        ]);

        $this->getDataGenerator()->get_plugin_generator('gradingform_rubric')->create_instance(
            $assign->get_context(),
            'mod_assign',
            'submissions',
            'Essay rubric',
            'Rubric used to grade the essay',
            [
                'Spelling' => [
                    'Poor spelling' => 0,
                    'Good spelling' => 100,
                ],
            ]
        );

        $this->add_submission($student, $assign, 'My essay text');

        $record = $this->create_pending_record($assign, $student, [
            'grade' => 100,
            'message' => 'Well written, keep it up',
            'rubric_response' => json_encode([
                [
                    'criterion' => 'Spelling',
                    'levels' => [['points' => 100, 'comment' => 'Perfect']],
                ],
            ]),
        ]);

        feedback_applier::apply_ai_feedback($assign, $record, (int) $teacher->id);

        $grade = $assign->get_user_grade($student->id, false);
        $feedback = $DB->get_record('assignfeedback_comments', ['grade' => $grade->id], '*', MUST_EXIST);
        $this->assertSame('Well written, keep it up', $feedback->commenttext);

        $this->resetDebugging();
    }

    /**
     * MDL-INT-005: An AI rubric response whose criteria do not match the rubric definition should
     * be rejected loudly instead of silently downgrading the grading method.
     *
     * @covers ::apply_ai_feedback
     * @covers ::apply_rubric_grading
     */
    public function test_rubric_criteria_mismatch_should_fail_loudly(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(4025, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);

        // Rubric defines criteria A and B.
        $this->getDataGenerator()->get_plugin_generator('gradingform_rubric')->create_instance(
            $assign->get_context(),
            'mod_assign',
            'submissions',
            'Mismatch rubric',
            'Rubric with criteria A and B',
            [
                'Criterion A' => [
                    'Poor' => 0,
                    'Good' => 50,
                ],
                'Criterion B' => [
                    'Poor' => 0,
                    'Good' => 50,
                ],
            ]
        );

        $this->add_submission($student, $assign, 'My essay text');

        // AI response references criteria C and D — completely mismatched against the rubric definition.
        $record = $this->create_pending_record($assign, $student, [
            'grade' => 50,
            'message' => 'Mismatched rubric feedback',
            'rubric_response' => json_encode([
                [
                    'criterion' => 'Criterion C',
                    'levels' => [['points' => 50, 'comment' => 'Good C']],
                ],
                [
                    'criterion' => 'Criterion D',
                    'levels' => [['points' => 50, 'comment' => 'Good D']],
                ],
            ]),
        ]);

        try {
            feedback_applier::apply_ai_feedback($assign, $record, (int) $teacher->id);
            $this->fail('Expected moodle_exception was not thrown');
        } catch (\moodle_exception $e) {
            $this->resetDebugging();
            $this->assertStringContainsString('does not match the rubric', $e->getMessage());
        }
    }

    /**
     * MDL-INT-006: A well-formed AI marking guide response scores every criterion within range,
     * stores the AI remarks and produces a total grade equal to the sum of the criterion scores.
     *
     * @covers ::apply_ai_feedback
     * @covers ::apply_guide_grading
     */
    public function test_guide_response_scores_criteria_remarks_and_totals_grade(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(4030, $course->id);
        // The assignment maximum equals the guide maximum score (25 + 15), so the total maps 1:1.
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignfeedback_comments_enabled' => 1,
            'grade' => 40,
        ]);

        $this->getDataGenerator()->get_plugin_generator('gradingform_guide')->create_instance(
            $assign->get_context(),
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
                    'description' => 'Award full marks for a clear structure.',
                    'descriptionmarkers' => 'Organise your essay clearly.',
                    'maxscore' => 15,
                ],
            ]
        );

        $this->add_submission($student, $assign, 'My essay text');

        $record = $this->create_pending_record($assign, $student, [
            'grade' => 30,
            'message' => 'Overall guide feedback',
            'assessment_guide_response' => json_encode([
                'Spelling' => ['grade' => 20, 'reply' => 'Solid spelling'],
                'Structure' => ['grade' => 10, 'reply' => ['Add a conclusion', 'Check paragraph flow']],
            ]),
        ]);

        feedback_applier::apply_ai_feedback($assign, $record, (int) $teacher->id);

        // The total grade is the sum of the criterion scores (20 + 10 out of 40).
        $grade = $assign->get_user_grade($student->id, false);
        $this->assertNotEmpty($grade);
        $this->assertEquals(30.0, (float) $grade->grade);
        $this->assertEquals($teacher->id, $grade->grader);

        // Each criterion is scored within its range and carries the AI remark.
        $gradingmanager = get_grading_manager($assign->get_context(), 'mod_assign', 'submissions');
        $definition = $gradingmanager->get_controller('guide')->get_definition();
        $fillings = $this->get_fillings($grade, 'gradingform_guide_fillings');

        $expectedbycriterion = [
            'Spelling' => [20.0, 'Solid spelling'],
            'Structure' => [10.0, 'Add a conclusion, Check paragraph flow'],
        ];
        foreach ($definition->guide_criteria as $criterionid => $criterion) {
            [$score, $remark] = $expectedbycriterion[$criterion['shortname']];
            $this->assertArrayHasKey($criterionid, $fillings);
            $filling = $fillings[$criterionid];
            $this->assertEquals($score, (float) $filling->score);
            $this->assertGreaterThanOrEqual(0, (float) $filling->score);
            $this->assertLessThanOrEqual((float) $criterion['maxscore'], (float) $filling->score);
            $this->assertSame($remark, $filling->remark);
        }

        // The AI message is stored as feedback comments too.
        $feedback = $DB->get_record('assignfeedback_comments', ['grade' => $grade->id], '*', MUST_EXIST);
        $this->assertSame('Overall guide feedback', $feedback->commenttext);

        $this->resetDebugging();
    }

    /**
     * MDL-INT-006: An AI guide response whose criteria do not match the marking guide definition
     * should be rejected loudly instead of silently downgrading the grading method.
     *
     * @covers ::apply_ai_feedback
     * @covers ::apply_guide_grading
     */
    public function test_guide_criteria_mismatch_should_fail_loudly(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(4035, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'grade' => 40,
        ]);

        // Marking guide defines criteria X and Y.
        $this->getDataGenerator()->get_plugin_generator('gradingform_guide')->create_instance(
            $assign->get_context(),
            'mod_assign',
            'submissions',
            'Mismatch guide',
            'Guide with criteria X and Y',
            [
                'Criterion X' => [
                    'description' => 'Criterion X description.',
                    'descriptionmarkers' => 'Criterion X marker hint.',
                    'maxscore' => 20,
                ],
                'Criterion Y' => [
                    'description' => 'Criterion Y description.',
                    'descriptionmarkers' => 'Criterion Y marker hint.',
                    'maxscore' => 20,
                ],
            ]
        );

        $this->add_submission($student, $assign, 'My essay text');

        // AI response references criterion Z only — completely mismatched against the guide definition.
        $record = $this->create_pending_record($assign, $student, [
            'grade' => 20,
            'message' => 'Mismatched guide feedback',
            'assessment_guide_response' => json_encode([
                'Criterion Z' => ['grade' => 20, 'reply' => 'Good Z'],
            ]),
        ]);

        try {
            feedback_applier::apply_ai_feedback($assign, $record, (int) $teacher->id);
            $this->fail('Expected moodle_exception was not thrown');
        } catch (\moodle_exception $e) {
            $this->resetDebugging();
            $this->assertStringContainsString('does not match the marking guide', $e->getMessage());
        }
    }

    /**
     * MDL-INT-007: Simple grading clamps the AI grade into the [0, maxgrade] range of the assignment.
     *
     * @covers ::apply_ai_feedback
     * @covers ::apply_simple_grading
     */
    public function test_simple_grading_clamps_grade_to_assignment_bounds(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $overachiever = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $underachiever = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(4040, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);

        $this->add_submission($overachiever, $assign, 'Above the maximum');
        $this->add_submission($underachiever, $assign, 'Below zero');

        // An AI grade above the maximum is clamped down to the assignment maximum (100).
        $record = $this->create_pending_record($assign, $overachiever, ['grade' => 150]);
        feedback_applier::apply_ai_feedback($assign, $record, (int) $teacher->id);
        $grade = $assign->get_user_grade($overachiever->id, false);
        $this->assertEquals(100.0, (float) $grade->grade);

        // A negative AI grade is clamped up to zero.
        $record = $this->create_pending_record($assign, $underachiever, ['grade' => -5]);
        feedback_applier::apply_ai_feedback($assign, $record, (int) $teacher->id);
        $grade = $assign->get_user_grade($underachiever->id, false);
        $this->assertEquals(0.0, (float) $grade->grade);

        $this->resetDebugging();
    }

    /**
     * MDL-INT-007: When the assignment is graded with a scale, the numeric AI grade is translated
     * into the matching 1-based scale item and applied, while the AI message is still stored.
     *
     * @covers ::apply_ai_feedback
     * @covers ::apply_simple_grading
     */
    public function test_simple_grading_with_scale_pushes_translated_grade(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Poor, Average, Good']);
        $this->bump_assign_sequence(4050, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignfeedback_comments_enabled' => 1,
            'grade' => -((int) $scale->id),
        ]);

        $this->add_submission($student, $assign, 'My essay text');

        $record = $this->create_pending_record($assign, $student, [
            'grade' => 2,
            'message' => 'Scale feedback',
        ]);
        feedback_applier::apply_ai_feedback($assign, $record, (int) $teacher->id);

        // The AI value 2 maps to scale item 2 ("Average") and is applied.
        $grade = $assign->get_user_grade($student->id, false);
        $this->assertNotEmpty($grade);
        $this->assertEquals(2.0, (float) $grade->grade);

        // The AI message is still stored as feedback comments.
        $feedback = $DB->get_record('assignfeedback_comments', ['grade' => $grade->id], '*', MUST_EXIST);
        $this->assertSame('Scale feedback', $feedback->commenttext);

        $this->resetDebugging();
    }

    /**
     * MDL-INT-007: Simple grading stores the AI message as an assignment feedback comment.
     *
     * @covers ::apply_ai_feedback
     * @covers ::save_feedback_comments
     */
    public function test_simple_grading_writes_feedback_comment(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(4060, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignfeedback_comments_enabled' => 1,
        ]);

        $this->add_submission($student, $assign, 'My essay text');

        $record = $this->create_pending_record($assign, $student, [
            'grade' => 70,
            'message' => 'Simple grading feedback',
        ]);
        feedback_applier::apply_ai_feedback($assign, $record, (int) $teacher->id);

        $grade = $assign->get_user_grade($student->id, false);
        $this->assertEquals(70.0, (float) $grade->grade);
        $feedback = $DB->get_record('assignfeedback_comments', ['grade' => $grade->id], '*', MUST_EXIST);
        $this->assertSame('Simple grading feedback', $feedback->commenttext);

        $this->resetDebugging();
    }

    /**
     * MDL-INT-007: An out-of-range AI grade is clamped to a valid 1-based scale index.
     *
     * @covers ::apply_simple_grading
     */
    public function test_scale_grade_is_translated_and_clamped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $scaledef = 'Poor, Average, Good'; // Three items: valid indexes are 1..3.

        // Above the top of the scale is clamped down to the last item (3).
        $this->assertEquals(3.0, $this->apply_scale_grade($scaledef, 5, 4070));
        // Below the bottom (0) is clamped up to the first item (1).
        $this->assertEquals(1.0, $this->apply_scale_grade($scaledef, 0, 4080));
    }

    /**
     * Applies an AI grade on a fresh scale assignment and returns the stored user grade.
     *
     * @param string $scaledef Comma-separated scale definition.
     * @param int $aigrade Numeric grade returned by the AI.
     * @param int $seq Unique sequence bump to isolate the assignment.
     * @return float The resulting assign_grades.grade value.
     */
    private function apply_scale_grade(string $scaledef, int $aigrade, int $seq): float {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $scale = $this->getDataGenerator()->create_scale(['scale' => $scaledef]);
        $this->bump_assign_sequence($seq, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'grade' => -((int) $scale->id),
        ]);
        $this->add_submission($student, $assign, 'Essay');

        $record = $this->create_pending_record($assign, $student, ['grade' => $aigrade, 'message' => 'x']);
        feedback_applier::apply_ai_feedback($assign, $record, (int) $teacher->id);
        $this->resetDebugging();

        return (float) $assign->get_user_grade($student->id, false)->grade;
    }

    /**
     * MDL-INT-032: With marking workflow enabled, applying AI feedback advances the student's
     * workflow state to released so the grade reaches the gradebook.
     *
     * @covers ::apply_ai_feedback
     * @covers ::advance_marking_workflow
     */
    public function test_marking_workflow_is_advanced_to_released(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->bump_assign_sequence(4070, $course->id);
        $assign = $this->create_instance($course, [
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'markingworkflow' => 1,
        ]);

        $this->add_submission($student, $assign, 'My essay text');

        $record = $this->create_pending_record($assign, $student, [
            'grade' => 70,
            'message' => 'Workflow feedback',
        ]);
        feedback_applier::apply_ai_feedback($assign, $record, (int) $teacher->id);

        $flags = $assign->get_user_flags($student->id, false);
        $this->assertNotEmpty($flags);
        $this->assertSame(ASSIGN_MARKING_WORKFLOW_STATE_RELEASED, $flags->workflowstate);

        $grade = $assign->get_user_grade($student->id, false);
        $this->assertEquals(70.0, (float) $grade->grade);

        $this->resetDebugging();
    }
}
