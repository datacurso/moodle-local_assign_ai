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

use aiprovider_datacurso\local\outbound_privacy;
use local_assign_ai\local\payload_anonymizer;

/**
 * Unit tests for the payload anonymizer.
 *
 * @coversDefaultClass \local_assign_ai\local\payload_anonymizer
 * @group local_assign_ai
 */
final class payload_anonymizer_test extends \advanced_testcase {
    /**
     * Build a payload carrying every allowlisted outbound field.
     *
     * @param array $overrides Fields to override or add.
     * @return array
     */
    private function full_payload(array $overrides = []): array {
        return array_merge([
            'course_id' => 12,
            'course' => 'Environmental Science',
            'assignment_id' => 34,
            'cmi_id' => 56,
            'assignment_title' => 'Argumentative essay',
            'assignment_description' => 'Write about renewable energy.',
            'assignment_activity_instructions' => 'Cite your sources.',
            'rubric' => null,
            'assessment_guide' => null,
            'userid' => '12',
            'student_name' => 'María Pérez',
            'submission_assign' => 'This is my essay about renewable energy sources.',
            'submission_files' => [],
            'maximum_grade' => 100,
            'prompt' => 'Be strict.',
            'lang' => 'en',
        ], $overrides);
    }

    /**
     * MDL-UNIT-002: Anonymization replaces the student name before sending the payload to the AI service.
     *
     * @covers ::anonymize
     */
    public function test_student_name_is_replaced_by_placeholder(): void {
        $result = payload_anonymizer::anonymize($this->full_payload());

        $this->assertSame('[STUDENT_NAME]', $result['payload']['student_name']);
        $this->assertSame(['[STUDENT_NAME]' => 'María Pérez'], $result['replacements']);

        $encoded = json_encode($result['payload'], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('María Pérez', $encoded);

        // Non-anonymized fields must remain untouched.
        $this->assertSame('Argumentative essay', $result['payload']['assignment_title']);
        $this->assertSame(
            'This is my essay about renewable energy sources.',
            $result['payload']['submission_assign']
        );
        $this->assertSame('Environmental Science', $result['payload']['course']);
    }

    /**
     * MDL-UNIT-002: A payload without a student name is returned unchanged with no replacements.
     *
     * @covers ::anonymize
     */
    public function test_payload_without_student_name_is_left_untouched(): void {
        $payload = [
            'assignment_title' => 'Argumentative essay',
            'submission_assign' => 'This is my essay about renewable energy sources.',
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
            'submission_assign' => 'This is my essay about renewable energy sources.',
        ];
        $result = payload_anonymizer::anonymize($payload);

        $reply = 'Well done [STUDENT_NAME], your essay shows a clear structure. Keep it up, [STUDENT_NAME]!';
        $restored = payload_anonymizer::deanonymize_text($reply, $result['replacements']);

        $this->assertSame('Well done María Pérez, your essay shows a clear structure. Keep it up, María Pérez!', $restored);
        $this->assertStringNotContainsString('[STUDENT_NAME]', $restored);
    }

    /**
     * LAA-PRIV-001: Only the documented allowlist of fields leaves the site; any extra key is dropped
     * so the outbound contract cannot grow silently.
     *
     * @covers ::anonymize
     */
    public function test_fields_outside_the_allowlist_are_dropped(): void {
        $payload = $this->full_payload([
            'email' => 'maria@example.com',
            'idnumber' => 'STU-0001',
        ]);

        $result = payload_anonymizer::anonymize($payload);

        $this->assertEqualsCanonicalizing(payload_anonymizer::ALLOWED_FIELDS, array_keys($result['payload']));
        $this->assertArrayNotHasKey('email', $result['payload']);
        $this->assertArrayNotHasKey('idnumber', $result['payload']);
        $this->assertStringNotContainsString('maria@example.com', json_encode($result['payload']));
    }

    /**
     * LAA-PRIV-001: The outbound userid is a stable, non-reversible, site-scoped token: the raw
     * Moodle id never leaves, the same user always maps to the same token and different users to
     * different tokens.
     *
     * @covers ::anonymize
     */
    public function test_userid_is_replaced_by_a_deterministic_site_scoped_pseudonym(): void {
        $first = payload_anonymizer::anonymize($this->full_payload(['userid' => '12']));
        $again = payload_anonymizer::anonymize($this->full_payload(['userid' => '12']));
        $other = payload_anonymizer::anonymize($this->full_payload(['userid' => '13']));

        $token = $first['payload']['userid'];
        $this->assertIsString($token);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
        $this->assertNotSame('12', $token);
        $this->assertSame(outbound_privacy::pseudonymise_userid(12), $token);
        $this->assertSame($token, $again['payload']['userid']);
        $this->assertNotSame($token, $other['payload']['userid']);
        $this->assertStringNotContainsString('"userid":"12"', json_encode($first['payload']));
    }

    /**
     * LAA-PRIV-001: The outbound userid is the token produced by the provider's shared helper, so
     * the plugin and aiprovider_datacurso always agree on the per-user key.
     *
     * @covers ::anonymize
     */
    public function test_userid_matches_the_provider_pseudonym(): void {
        $result = payload_anonymizer::anonymize($this->full_payload(['userid' => '12']));

        $this->assertSame(outbound_privacy::pseudonymise_userid(12), $result['payload']['userid']);
    }
}
