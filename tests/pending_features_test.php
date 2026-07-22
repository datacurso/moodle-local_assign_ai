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
 * Placeholders for scope features the plugin does not implement yet.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai;

/**
 * Placeholder tests tracing scope items that are pending implementation (MDL-INT-025..031).
 *
 * Each method maps one scope feature the plugin does not implement yet to its spec id, so the
 * suite documents the gap and the placeholder turns into a real test once the feature lands.
 *
 * @coversNothing
 * @group local_assign_ai
 */
final class pending_features_test extends \advanced_testcase {
    /**
     * MDL-INT-025: Applying AI feedback should respect the state of the "feedback comments"
     * plugin type and never overwrite the student submission text with the inline comment.
     */
    public function test_feedback_comments_plugin_state_is_respected(): void {
        $this->markTestSkipped(
            'Pending feature: the plugin does not check that the "feedback comments" type is enabled '
            . 'and the inline comment overwrites the submission text (MDL-INT-025).'
        );
    }

    /**
     * MDL-INT-026: The AI review should be able to produce feedback files attached to the grade.
     */
    public function test_feedback_files_are_generated(): void {
        $this->markTestSkipped('Pending feature: feedback files are not generated (MDL-INT-026).');
    }

    /**
     * MDL-INT-027: Publishing an AI grade should coordinate with the grade-to-pass setting and
     * the automatic attempt reopening method of the assignment.
     */
    public function test_grade_to_pass_and_attempt_reopening_are_coordinated(): void {
        $this->markTestSkipped(
            'Pending feature: grade-to-pass / automatic attempt reopening is not coordinated (MDL-INT-027).'
        );
    }

    /**
     * MDL-INT-028: Group submissions should be processed once per group instead of once per member.
     */
    public function test_group_submissions_are_processed_once_per_group(): void {
        $this->markTestSkipped(
            'Pending feature: group submissions are not supported, they are processed per member (MDL-INT-028).'
        );
    }

    /**
     * MDL-INT-029: When marking allocation is enabled, only the allocated marker should be used
     * to attribute the AI grading.
     */
    public function test_marking_allocation_is_respected(): void {
        $this->markTestSkipped('Pending feature: marking allocation is not respected (MDL-INT-029).');
    }

    /**
     * MDL-INT-030: Students should receive a notification when an AI grade is published for them.
     */
    public function test_students_are_notified_when_ai_grade_is_published(): void {
        $this->markTestSkipped(
            'Pending feature: students are not notified when an AI grade is published (MDL-INT-030).'
        );
    }

    /**
     * MDL-INT-031: The additional files configured on the assignment should be sent to the AI
     * service together with the submission content.
     */
    public function test_assignment_additional_files_are_sent_to_ai_service(): void {
        $this->markTestSkipped(
            'Pending feature: assignment additional files are not sent to the AI service (MDL-INT-031).'
        );
    }
}
