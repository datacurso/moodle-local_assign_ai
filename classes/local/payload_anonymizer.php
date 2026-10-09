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
 * Payload anonymizer for local_assign_ai AI requests.
 *
 * @package     local_assign_ai
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai\local;

use aiprovider_datacurso\local\outbound_privacy;

/**
 * Outbound boundary of the plugin towards the AI provider.
 *
 * Every payload handed to the AI provider goes through {@see anonymize()}. The class owns only
 * what is specific to this plugin: the per-endpoint field allowlist ({@see ALLOWED_FIELDS}) and
 * the fact that the `student_name` field travels as a placeholder. The mechanics (allowlist
 * filtering, the site-scoped userid pseudonym and the placeholder restore) are delegated to the
 * provider's shared helper {@see outbound_privacy}, so the plugin and aiprovider_datacurso always
 * agree on the outbound token. See _docs/privacy.md for the justification of each field.
 */
class payload_anonymizer {
    /**
     * Every field allowed to leave the site towards the AI provider.
     *
     * Any key not listed here is dropped before sending, so the outbound contract cannot grow
     * silently. Keep this list and _docs/privacy.md in sync.
     *
     * @var string[]
     */
    public const ALLOWED_FIELDS = [
        'course_id',
        'course',
        'assignment_id',
        'cmi_id',
        'assignment_title',
        'assignment_description',
        'assignment_activity_instructions',
        'rubric',
        'assessment_guide',
        'userid',
        'student_name',
        'submission_assign',
        'submission_files',
        'maximum_grade',
        'prompt',
        'lang',
    ];

    /**
     * Anonymize an outbound payload.
     *
     * Drops every key outside {@see ALLOWED_FIELDS}, replaces the userid with the provider's
     * site-scoped pseudonym and swaps the student name for {@see outbound_privacy::PLACEHOLDER_NAME}.
     *
     * @param array $payload Original payload.
     * @return array{payload: array, replacements: array<string, string>}
     */
    public static function anonymize(array $payload): array {
        $payload = outbound_privacy::apply_allowlist($payload, self::ALLOWED_FIELDS);

        if (isset($payload['userid']) && is_numeric($payload['userid'])) {
            $payload['userid'] = outbound_privacy::pseudonymise_userid_value($payload['userid']);
        }

        // The provider helper only records name placeholders when given a user record; here the
        // payload already carries the rendered full name, so the single replacement is built from it.
        $replacements = [];
        if (isset($payload['student_name']) && is_string($payload['student_name']) && $payload['student_name'] !== '') {
            $replacements[outbound_privacy::PLACEHOLDER_NAME] = $payload['student_name'];
            $payload['student_name'] = outbound_privacy::PLACEHOLDER_NAME;
        }

        return [
            'payload' => $payload,
            'replacements' => $replacements,
        ];
    }

    /**
     * Restore anonymized placeholders in AI reply text.
     *
     * @param string $text AI reply text.
     * @param array $replacements Placeholder to original value map.
     * @return string
     */
    public static function deanonymize_text(string $text, array $replacements): string {
        return outbound_privacy::restore_text($text, $replacements);
    }
}
