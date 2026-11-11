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

namespace local_assign_ai\api;

use local_assign_ai\local\payload_anonymizer;

/**
 * Client API for local_assign_ai.
 *
 * @package     local_assign_ai
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client {
    /** AI service endpoint that grades a submission. */
    public const ENDPOINT = '/assign/answer';

    /**
     * Sends the payload to the AI provider and returns the response.
     *
     * When a context is given, the Datacurso AI provider logs the request in it, with the student
     * as related user, instead of the system context. Providers without that audit API are used as is.
     *
     * @param array $payload The request payload.
     * @param \context|null $context Context the request is made in, for example the assignment module.
     * @param int|null $relateduserid Moodle id of the student the request is made for.
     * @return array The AI response (reply, grade, rubric, assessment_guide, needs_review, review_reason).
     */
    public static function send_to_ai($payload, ?\context $context = null, ?int $relateduserid = null) {
        $anonymized = payload_anonymizer::anonymize($payload);
        $payload = $anonymized['payload'];
        $replacements = $anonymized['replacements'];

        // The factory is resolved through the DI container so tests can replace the HTTP client.
        $client = \core\di::get(ai_client_factory::class)->create();
        if ($context !== null && method_exists($client, 'set_audit_context')) {
            $client->set_audit_context($context, $relateduserid);
        }

        $response = $client->request('POST', self::ENDPOINT, $payload);

        return [
            'reply' => payload_anonymizer::deanonymize_text((string)($response['reply'] ?? ''), $replacements),
            'grade' => $response['grade'] ?? null,
            'rubric' => $response['rubric'] ?? null,
            'assessment_guide' => $response['assessment_guide'] ?? null,
            // Set by the AI service when it could not assess the work (e.g. an unreadable file).
            'needs_review' => !empty($response['needs_review']),
            'review_reason' => isset($response['review_reason']) ? (string) $response['review_reason'] : null,
        ];
    }
}
