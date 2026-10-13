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
 * Every payload handed to the AI provider goes through {@see anonymize()}. The plugin owns
 * everything that is specific to its endpoint: the field allowlist ({@see ALLOWED_FIELDS}) and
 * the student-name placeholder, together with its restore in the AI reply. The only shared
 * piece is the userid pseudonym, delegated to the provider's {@see outbound_privacy} so the
 * whole Datacurso suite derives the same per-user token with one formula. See _docs/privacy.md
 * for the justification of each field.
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

    /** @var string Placeholder that replaces the student full name in the outbound payload. */
    private const STUDENT_NAME_PLACEHOLDER = '[STUDENT_NAME]';

    /**
     * Anonymize an outbound payload.
     *
     * Drops every key outside {@see ALLOWED_FIELDS}, replaces a numeric userid with the
     * provider's site-scoped pseudonym and swaps the student name for its placeholder.
     *
     * @param array $payload Original payload.
     * @return array{payload: array, replacements: array<string, string>}
     */
    public static function anonymize(array $payload): array {
        $payload = array_intersect_key($payload, array_flip(self::ALLOWED_FIELDS));

        if (isset($payload['userid']) && is_numeric($payload['userid'])) {
            $payload['userid'] = outbound_privacy::pseudonymise_userid_value($payload['userid']);
        }

        $replacements = [];
        if (isset($payload['student_name']) && is_string($payload['student_name']) && $payload['student_name'] !== '') {
            $replacements[self::STUDENT_NAME_PLACEHOLDER] = $payload['student_name'];
            $payload['student_name'] = self::STUDENT_NAME_PLACEHOLDER;
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
        if ($text === '' || empty($replacements)) {
            return $text;
        }

        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }
}
