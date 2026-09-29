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

namespace local_assign_ai;

/**
 * Replaces the Datacurso provider API with an in-memory fake so tests need no external plugin.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait ai_client_mock {
    /** @var string[] Response bodies queued for the next AI calls, consumed in order. */
    private array $aibodies = [];

    /**
     * Installs the fake API in the client.
     *
     * @return void
     */
    private function configure_ai_provider(): void {
        $this->aibodies = [];
        $bodies = &$this->aibodies;
        api\client::set_api_factory(static function () use (&$bodies) {
            return new class ($bodies) {
                /** @var string[] Queue shared with the test. */
                private array $queue;

                /**
                 * Constructor.
                 *
                 * @param string[] $queue Response bodies, passed by reference.
                 */
                public function __construct(array &$queue) {
                    $this->queue = &$queue;
                }

                /**
                 * Returns the next queued response, failing like the real client on an empty body.
                 *
                 * @param string $method HTTP method.
                 * @param string $path Endpoint path.
                 * @param array $payload Request payload.
                 * @return array Decoded response.
                 */
                public function request(string $method, string $path, array $payload = []): array {
                    if (!$this->queue) {
                        throw new \RuntimeException('No fake AI response queued');
                    }
                    $body = array_shift($this->queue);
                    if ($body === '') {
                        throw new \RuntimeException('Empty response from the AI service');
                    }
                    return json_decode($body, true) ?? [];
                }
            };
        });
    }

    /**
     * Queues the response body consumed by one client::send_to_ai() call.
     *
     * @param string $answerbody Body returned for the /assign/answer POST; an empty string simulates a failure.
     * @return void
     */
    private function mock_ai_pipeline(string $answerbody): void {
        $this->aibodies[] = $answerbody;
    }

    /**
     * Queues a successful AI review with the given grade and reply.
     *
     * @param int $grade Grade returned by the fake AI service.
     * @param string $reply Feedback text returned by the fake AI service.
     * @return void
     */
    private function mock_ai_success(int $grade, string $reply): void {
        $this->mock_ai_pipeline(json_encode([
            'reply' => $reply,
            'grade' => $grade,
            'rubric' => null,
            'assessment_guide' => null,
        ]));
    }

    /**
     * Restores the real API factory so other tests are not affected.
     *
     * @return void
     */
    protected function tearDown(): void {
        api\client::set_api_factory(null);
        parent::tearDown();
    }
}
