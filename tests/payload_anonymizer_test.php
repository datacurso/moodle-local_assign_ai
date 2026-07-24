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
 * Tests for the payload anonymizer.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai;

use local_assign_ai\local\payload_anonymizer;

/**
 * Unit tests for the payload anonymizer.
 *
 * @coversDefaultClass \local_assign_ai\local\payload_anonymizer
 * @group local_assign_ai
 */
final class payload_anonymizer_test extends \advanced_testcase {
    /**
     * MDL-UNIT-002: Anonymization replaces the student name before sending the payload to the AI service.
     *
     * @covers ::anonymize
     */
    public function test_student_name_is_replaced_by_placeholder(): void {
        $payload = [
            'student_name' => 'María Pérez',
            'assignment_name' => 'Argumentative essay',
            'submission_text' => 'This is my essay about renewable energy sources.',
            'context' => [
                'course' => 'Environmental Science',
                'max_grade' => 100,
            ],
        ];

        $result = payload_anonymizer::anonymize($payload);

        $this->assertSame('[STUDENT_NAME]', $result['payload']['student_name']);
        $this->assertSame(['[STUDENT_NAME]' => 'María Pérez'], $result['replacements']);

        $encoded = json_encode($result['payload'], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('María Pérez', $encoded);

        // Non-anonymized fields must remain untouched.
        $this->assertSame('Argumentative essay', $result['payload']['assignment_name']);
        $this->assertSame('This is my essay about renewable energy sources.', $result['payload']['submission_text']);
        $this->assertSame('Environmental Science', $result['payload']['context']['course']);
    }

    /**
     * MDL-UNIT-002: A payload without a student name is returned unchanged with no replacements.
     *
     * @covers ::anonymize
     */
    public function test_payload_without_student_name_is_left_untouched(): void {
        $payload = [
            'assignment_name' => 'Argumentative essay',
            'submission_text' => 'This is my essay about renewable energy sources.',
        ];

        $result = payload_anonymizer::anonymize($payload);

        $this->assertSame($payload, $result['payload']);
        $this->assertSame([], $result['replacements']);
    }

    /**
     * MDL-UNIT-002: De-anonymization restores the real student name in the AI reply text.
     *
     * @covers ::deanonymize_text
     */
    public function test_deanonymize_text_restores_the_real_name(): void {
        $payload = [
            'student_name' => 'María Pérez',
            'submission_text' => 'This is my essay about renewable energy sources.',
        ];
        $result = payload_anonymizer::anonymize($payload);

        $reply = 'Well done [STUDENT_NAME], your essay shows a clear structure. Keep it up, [STUDENT_NAME]!';
        $restored = payload_anonymizer::deanonymize_text($reply, $result['replacements']);

        $this->assertSame('Well done María Pérez, your essay shows a clear structure. Keep it up, María Pérez!', $restored);
        $this->assertStringNotContainsString('[STUDENT_NAME]', $restored);
    }
}
