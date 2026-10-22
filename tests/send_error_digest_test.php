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
 * Tests for the grouped AI error digest task.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai;

use local_assign_ai\task\retry_failed_submissions;
use local_assign_ai\task\send_error_digest;

/**
 * Tests for {@see \local_assign_ai\task\send_error_digest}.
 *
 * @coversDefaultClass \local_assign_ai\task\send_error_digest
 * @group local_assign_ai
 */
final class send_error_digest_test extends \advanced_testcase {
    /**
     * Enables the digest with the given recipients.
     *
     * @param string $emails Raw recipient list.
     */
    private function enable_digest(string $emails = "a@example.com\nb@example.com"): void {
        set_config('errornotifyenabled', 1, 'local_assign_ai');
        set_config('errornotifyemails', $emails, 'local_assign_ai');
    }

    /**
     * Creates an assignment and returns its course, instance and cm.
     *
     * @param \stdClass|null $course Existing course or null to create one.
     * @return array [course, instance, cm]
     */
    private function create_assign(?\stdClass $course = null): array {
        $course = $course ?? $this->getDataGenerator()->create_course(['shortname' => 'DIGESTCOURSE']);
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'name' => 'Essay One']);
        [, $cm] = get_course_and_cm_from_instance($instance->id, 'assign');
        return [$course, $instance, $cm];
    }

    /**
     * Inserts a pending record.
     *
     * @param \stdClass $course Course.
     * @param \stdClass $cm Course module.
     * @param \stdClass $student Student.
     * @param array $overrides Field overrides.
     * @return int Record id.
     */
    private function add_record(\stdClass $course, $cm, \stdClass $student, array $overrides = []): int {
        global $DB;
        return $DB->insert_record('local_assign_ai_pending', (object) ($overrides + [
            'courseid' => $course->id,
            'assignmentid' => $cm->id,
            'title' => 'Essay One',
            'userid' => $student->id,
            'submissionid' => 0,
            'attemptnumber' => 0,
            'submissionmodified' => 0,
            'edited' => 0,
            'errormessage' => get_string('error_generic', 'local_assign_ai'),
            'retries' => retry_failed_submissions::MAX_RETRIES,
            'status' => assign_submission::STATUS_FAILED,
            'approval_token' => md5(uniqid('t', true)),
            'usermodified' => get_admin()->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]));
    }

    /**
     * Splits a captured multipart/alternative message into its decoded plain-text and HTML parts.
     *
     * @param \stdClass $message Message captured by the email sink.
     * @return string[] [plain, html] (empty string when a part is missing)
     */
    private function mime_parts(\stdClass $message): array {
        $plain = '';
        $html = '';
        foreach (explode('Content-Type: ', $message->body) as $part) {
            if (strpos($part, 'text/plain') === 0) {
                $plain = quoted_printable_decode($part);
            } else if (strpos($part, 'text/html') === 0) {
                $html = quoted_printable_decode($part);
            }
        }
        return [$plain, $html];
    }

    /**
     * Runs the task.
     */
    private function run_task(): void {
        ob_start();
        (new send_error_digest())->execute();
        ob_end_clean();
    }

    /**
     * Disabled digest sends nothing and marks nothing.
     */
    public function test_disabled_sends_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        [$course, , $cm] = $this->create_assign();
        $id = $this->add_record($course, $cm, $student);
        set_config('errornotifyenabled', 0, 'local_assign_ai');
        set_config('errornotifyemails', 'a@example.com', 'local_assign_ai');

        $sink = $this->redirectEmails();
        $this->run_task();
        $this->assertCount(0, $sink->get_messages());
        $this->assertNull($DB->get_field('local_assign_ai_pending', 'errornotifiedtime', ['id' => $id]));
    }

    /**
     * The admin setting is offered enabled by default.
     */
    public function test_setting_defaults_to_enabled(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $page = \admin_get_root(true, true)->locate('local_assign_ai_settings');
        // Loading the whole admin tree triggers an unrelated local_adminer XMLDB debugging notice.
        $this->resetDebugging();
        $this->assertNotNull($page);
        $setting = null;
        foreach ((array) $page->settings as $candidate) {
            if ($candidate->name === 'errornotifyenabled') {
                $setting = $candidate;
            }
        }
        $this->assertNotNull($setting);
        $this->assertEquals(1, $setting->get_defaultsetting());
    }

    /**
     * A never-saved setting counts as the default (enabled) and the digest is sent.
     */
    public function test_unset_enabled_defaults_to_sending(): void {
        global $DB;
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        [$course, , $cm] = $this->create_assign();
        $id = $this->add_record($course, $cm, $student);
        unset_config('errornotifyenabled', 'local_assign_ai');
        set_config('errornotifyemails', 'a@example.com', 'local_assign_ai');

        $sink = $this->redirectEmails();
        $this->run_task();
        $this->assertCount(1, $sink->get_messages());
        $this->assertNotNull($DB->get_field('local_assign_ai_pending', 'errornotifiedtime', ['id' => $id]));
    }

    /**
     * Default enabled with an empty recipient list sends nothing and marks nothing.
     */
    public function test_default_enabled_without_recipients_sends_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        [$course, , $cm] = $this->create_assign();
        $id = $this->add_record($course, $cm, $student);
        unset_config('errornotifyenabled', 'local_assign_ai');
        unset_config('errornotifyemails', 'local_assign_ai');

        $sink = $this->redirectEmails();
        $this->run_task();
        $this->assertCount(0, $sink->get_messages());
        $this->assertNull($DB->get_field('local_assign_ai_pending', 'errornotifiedtime', ['id' => $id]));
    }

    /**
     * Enabled without any valid recipient does nothing.
     */
    public function test_no_valid_recipients_sends_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        [$course, , $cm] = $this->create_assign();
        $id = $this->add_record($course, $cm, $student);
        $this->enable_digest('not-an-email');

        $sink = $this->redirectEmails();
        $this->run_task();
        $this->assertCount(0, $sink->get_messages());
        $this->assertNull($DB->get_field('local_assign_ai_pending', 'errornotifiedtime', ['id' => $id]));
    }

    /**
     * One email per address, no recipient exposure, records marked, second run silent.
     */
    public function test_one_email_per_address_and_marking(): void {
        global $DB;
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user(['firstname' => 'Zebulon', 'lastname' => 'Quimby']);
        [$course, , $cm] = $this->create_assign();
        $id = $this->add_record($course, $cm, $student);
        $this->enable_digest();

        $sink = $this->redirectEmails();
        $this->run_task();
        $messages = $sink->get_messages();
        $this->assertCount(2, $messages);
        $to = array_map(fn($m) => strtolower(trim($m->to)), $messages);
        sort($to);
        $this->assertSame(['a@example.com', 'b@example.com'], $to);
        foreach ($messages as $message) {
            $other = strtolower(trim($message->to)) === 'a@example.com' ? 'b@example.com' : 'a@example.com';
            $this->assertStringNotContainsString($other, strtolower($message->to));
            $this->assertStringNotContainsString($other, strtolower($message->header ?? ''));
            $this->assertEmpty($message->cc ?? '');
            $this->assertEmpty($message->bcc ?? '');
        }
        $this->assertNotEmpty($DB->get_field('local_assign_ai_pending', 'errornotifiedtime', ['id' => $id]));

        $sink->clear();
        $this->run_task();
        $this->assertCount(0, $sink->get_messages());
    }

    /**
     * A record modified after being notified is notified again.
     */
    public function test_modified_record_is_renotified(): void {
        global $DB;
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        [$course, , $cm] = $this->create_assign();
        $id = $this->add_record($course, $cm, $student);
        $this->enable_digest('a@example.com');

        $sink = $this->redirectEmails();
        $this->run_task();
        $this->assertCount(1, $sink->get_messages());
        $sink->clear();

        $DB->set_field('local_assign_ai_pending', 'timemodified', time() + 100, ['id' => $id]);
        $this->run_task();
        $this->assertCount(1, $sink->get_messages());
    }

    /**
     * Failed records that still have retries left are not included.
     */
    public function test_failed_with_retries_left_is_excluded(): void {
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        [$course, , $cm] = $this->create_assign();
        $this->add_record($course, $cm, $student, ['retries' => retry_failed_submissions::MAX_RETRIES - 1]);
        $this->enable_digest('a@example.com');

        $sink = $this->redirectEmails();
        $this->run_task();
        $this->assertCount(0, $sink->get_messages());
    }

    /**
     * A terminal unreadable-file failure is included once its retries are exhausted.
     */
    public function test_unreadable_terminal_failure_is_included(): void {
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        [$course, , $cm] = $this->create_assign();
        $this->add_record($course, $cm, $student, [
            'status' => assign_submission::STATUS_FAILED,
            'retries' => \local_assign_ai\task\retry_failed_submissions::MAX_RETRIES,
            'errormessage' => get_string('error_unreadable_file', 'local_assign_ai'),
        ]);
        $this->enable_digest('a@example.com');

        $sink = $this->redirectEmails();
        $this->run_task();
        $this->assertCount(1, $sink->get_messages());
    }

    /**
     * Records older than seven days are ignored.
     */
    public function test_stale_records_are_ignored(): void {
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        [$course, , $cm] = $this->create_assign();
        $this->add_record($course, $cm, $student, ['timemodified' => time() - 8 * DAYSECS]);
        $this->enable_digest('a@example.com');

        $sink = $this->redirectEmails();
        $this->run_task();
        $this->assertCount(0, $sink->get_messages());
    }

    /**
     * Content: grouping counts, links and no sensitive data.
     */
    public function test_email_content_groups_and_hides_sensitive_data(): void {
        $this->resetAfterTest();
        $students = [];
        for ($i = 0; $i < 4; $i++) {
            $students[] = $this->getDataGenerator()->create_user(['firstname' => 'Zebulon' . $i, 'lastname' => 'Quimby']);
        }
        [$course, , $cm] = $this->create_assign();
        $secret = new \lang_string('error_file_too_large', 'local_assign_ai', (object) [
            'file' => 'secret-thesis.pdf', 'size' => 99, 'limit' => 10,
        ]);
        for ($i = 0; $i < 3; $i++) {
            $this->add_record($course, $cm, $students[$i], ['errormessage' => (string) $secret]);
        }
        $this->add_record($course, $cm, $students[3]);
        $this->enable_digest('a@example.com');

        $sink = $this->redirectEmails();
        $this->run_task();
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        [$body] = $this->mime_parts($messages[0]);
        $this->assertStringContainsString('DIGESTCOURSE', $body);
        $this->assertStringContainsString('Essay One', $body);
        $this->assertStringContainsString('/local/assign_ai/history.php?id=' . $cm->id, $body);
        $this->assertStringContainsString(get_string('errordigest_kind_filetoolarge', 'local_assign_ai'), $body);
        $this->assertStringContainsString(get_string('errordigest_kind_failed', 'local_assign_ai'), $body);
        $this->assertMatchesRegularExpression('/\b3\b/', $body);
        $this->assertStringContainsString('4', $messages[0]->subject);
        $this->assertStringNotContainsString('Zebulon', $body);
        $this->assertStringNotContainsString('Quimby', $body);
        $this->assertStringNotContainsString('secret-thesis', $body);
        $this->assertStringNotContainsString('exceeds the maximum size', $body);
        $this->assertStringNotContainsString(get_string('error_generic', 'local_assign_ai'), $body);
    }

    /**
     * The digest goes out as multipart/alternative: HTML part with the group line and an anchor, plus plain text.
     */
    public function test_email_has_html_and_plain_text_parts(): void {
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        [$course, , $cm] = $this->create_assign();
        $this->add_record($course, $cm, $student);
        $this->enable_digest('a@example.com');

        $sink = $this->redirectEmails();
        $this->run_task();
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);

        $this->assertStringContainsString('multipart/alternative', $messages[0]->header);
        [$plain, $html] = $this->mime_parts($messages[0]);
        $url = (new \moodle_url('/local/assign_ai/history.php', ['id' => $cm->id]))->out(false);

        $this->assertNotSame('', $html, 'The HTML alternative is missing.');
        $this->assertStringContainsString('<a href="' . $url . '">', $html);
        $this->assertStringContainsString('DIGESTCOURSE / Essay One', $html);

        $this->assertNotSame('', $plain, 'The plain-text alternative is missing.');
        $this->assertStringContainsString($url, $plain);
        $this->assertStringContainsString('DIGESTCOURSE / Essay One', $plain);
        $this->assertStringNotContainsString('<a href', $plain);
    }

    /**
     * Kind classification maps known stored messages and defaults to generic.
     */
    public function test_classify_kind(): void {
        $this->resetAfterTest();
        $failed = assign_submission::STATUS_FAILED;
        $mk = fn($status, $msg) => (object) ['status' => $status, 'errormessage' => $msg];
        $total = get_string('error_payload_too_large', 'local_assign_ai', (object) ['total' => 50, 'limit' => 30]);
        $this->assertSame('payloadtoolarge', send_error_digest::classify_kind($mk($failed, $total)));
        $this->assertSame('servicerejected', send_error_digest::classify_kind(
            $mk($failed, get_string('error_service_payload_rejected', 'local_assign_ai'))
        ));
        $this->assertSame('unreadable', send_error_digest::classify_kind(
            $mk($failed, get_string('error_unreadable_file', 'local_assign_ai'))
        ));
        $this->assertSame('inconsistent', send_error_digest::classify_kind(
            $mk($failed, get_string('error_inconsistent_evaluation', 'local_assign_ai'))
        ));
        $this->assertSame('failed', send_error_digest::classify_kind($mk($failed, 'whatever')));
        $this->assertSame('failed', send_error_digest::classify_kind($mk($failed, null)));
    }

    /**
     * Only the first 50 groups are listed and the rest are summarised and left for the next run.
     */
    public function test_group_cap(): void {
        global $DB;
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['shortname' => 'DIGESTCOURSE']);
        $ids = [];
        for ($i = 0; $i < 52; $i++) {
            [, , $cm] = $this->create_assign($course);
            $ids[] = $this->add_record($course, $cm, $student);
        }
        $this->enable_digest('a@example.com');

        $sink = $this->redirectEmails();
        $this->run_task();
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame(50, substr_count($this->mime_parts($messages[0])[0], '/local/assign_ai/history.php?id='));
        $this->assertStringContainsString(get_string('errordigest_more', 'local_assign_ai', 2), $this->mime_parts($messages[0])[0]);
        $this->assertStringContainsString('52', $messages[0]->subject);
        $marked = $DB->count_records_select('local_assign_ai_pending', 'errornotifiedtime IS NOT NULL');
        $this->assertSame(50, $marked);

        $sink->clear();
        $this->run_task();
        $this->assertCount(1, $sink->get_messages());
    }

    /**
     * Invalid configured addresses are skipped while valid ones still receive the digest.
     */
    public function test_invalid_address_does_not_block_valid_ones(): void {
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        [$course, , $cm] = $this->create_assign();
        $this->add_record($course, $cm, $student);
        $this->enable_digest("bad-address\nok@example.com");

        $sink = $this->redirectEmails();
        $this->run_task();
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame('ok@example.com', strtolower(trim($messages[0]->to)));
    }

    /**
     * The task is registered every ten minutes.
     */
    public function test_task_registered(): void {
        $task = \core\task\manager::get_scheduled_task(send_error_digest::class);
        $this->assertNotFalse($task);
        $this->assertSame('*/10', $task->get_minute());
        $this->assertNotEmpty($task->get_name());
    }
}
