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

use aiprovider_datacurso\httpclient\ai_services_api;
use local_assign_ai\local\payload_anonymizer;

/**
 * Client API for local_assign_ai.
 *
 * @package     local_assign_ai
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client {
    /** @var callable|null Factory returning the API object, replaced by tests to avoid the external provider. */
    private static $apifactory = null;

    /**
     * Overrides how the provider API object is built. Intended for unit tests only.
     *
     * @param callable|null $factory Returns an object exposing request(), or null to restore the default.
     * @return void
     */
    public static function set_api_factory(?callable $factory): void {
        self::$apifactory = $factory;
    }

    /**
     * Sends the payload to the AI provider and returns the response.
     *
     * @param array $payload The request payload.
     * @return array The AI response.
     */
    public static function send_to_ai($payload) {
        $anonymized = payload_anonymizer::anonymize($payload);
        $payload = $anonymized['payload'];
        $replacements = $anonymized['replacements'];

        $client = self::$apifactory ? (self::$apifactory)() : new ai_services_api();

        $response = $client->request('POST', '/assign/answer', $payload);

        return [
            'reply' => payload_anonymizer::deanonymize_text((string)($response['reply'] ?? ''), $replacements),
            'grade' => $response['grade'],
            'rubric' => $response['rubric'] ?? null,
            'assessment_guide' => $response['assessment_guide'] ?? null,
        ];
    }
}
