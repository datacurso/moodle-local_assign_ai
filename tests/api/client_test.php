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

/**
 * Tests for the audit context the AI client gives the Datacurso HTTP client.
 *
 * @package    local_assign_ai
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_assign_ai
 * @covers     \local_assign_ai\api\client
 */
final class client_test extends \advanced_testcase {
    /** @var array Calls recorded by the fake HTTP client, in order. */
    private array $calls = [];

    /**
     * Skips the test when the provider client cannot set an audit context.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        if (!method_exists(\aiprovider_datacurso\httpclient\ai_services_api::class, 'set_audit_context')) {
            $this->markTestSkipped('The installed aiprovider_datacurso cannot set an audit context.');
        }
        $this->calls = [];
    }

    /**
     * Replace the HTTP client with a fake that records its calls.
     *
     * @return void
     */
    private function fake_ai_client(): void {
        $spy = $this->getMockBuilder(\aiprovider_datacurso\httpclient\ai_services_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['request', 'set_audit_context'])
            ->getMock();
        $spy->method('set_audit_context')->willReturnCallback(
            function (\context $context, ?int $relateduserid = null) use (&$spy) {
                $this->calls[] = ['set_audit_context', $context->id, $relateduserid];
                return $spy;
            }
        );
        $spy->method('request')->willReturnCallback(
            function (string $method, string $path, array $body = []): array {
                $this->calls[] = ['request', $path];
                return ['reply' => 'ok', 'grade' => 4];
            }
        );
        $factory = $this->createStub(ai_client_factory::class);
        $factory->method('create')->willReturn($spy);
        \core\di::set(ai_client_factory::class, $factory);
    }

    /**
     * The request is logged in the given context, with the student as related user.
     *
     * @return void
     */
    public function test_request_is_audited_in_the_given_context(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $context = \context_module::instance($instance->cmid);
        $this->fake_ai_client();

        client::send_to_ai(['userid' => '1'], $context, 42);

        $this->assertSame([
            ['set_audit_context', $context->id, 42],
            ['request', client::ENDPOINT],
        ], $this->calls);
    }

    /**
     * Without a context the provider keeps its own default.
     *
     * @return void
     */
    public function test_request_without_context_keeps_the_provider_default(): void {
        $this->resetAfterTest();
        $this->fake_ai_client();

        client::send_to_ai(['userid' => '1']);

        $this->assertSame([['request', client::ENDPOINT]], $this->calls);
    }
}
