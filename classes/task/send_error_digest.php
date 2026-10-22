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

namespace local_assign_ai\task;

use local_assign_ai\admin\setting_emaillist;
use local_assign_ai\assign_submission;

/**
 * Scheduled task that emails administrators a grouped digest of AI errors.
 *
 * Final failures (retries exhausted, terminal size errors, unreadable files, final timeouts) are grouped
 * by course, activity and error kind. No student, file or free-text error data
 * is included: only counts and a link to the AI history of each activity.
 *
 * @package    local_assign_ai
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_error_digest extends \core\task\scheduled_task {
    /** @var int Maximum number of groups listed per email. */
    public const MAX_GROUPS = 50;

    /** @var int Records not modified for this many seconds are considered stale backlog. */
    public const MAX_AGE = 7 * DAYSECS;

    /**
     * Stored message string => kind.
     *
     * @var array
     */
    private const MESSAGE_KINDS = [
        'error_payload_too_large' => 'payloadtoolarge',
        'error_file_too_large' => 'filetoolarge',
        'error_service_payload_rejected' => 'servicerejected',
        'error_unreadable_file' => 'unreadable',
        'error_inconsistent_evaluation' => 'inconsistent',
    ];

    /**
     * Return the task name shown in admin screens.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_send_error_digest', 'local_assign_ai');
    }

    /**
     * Derives a short, stable kind key from a record without exposing its free text.
     *
     * The stored message is localised text, so it is compared against the plugin's own strings in every
     * installed language. Anything unknown maps to the generic kind of its status.
     *
     * @param \stdClass $record Pending record (status, errormessage).
     * @return string Kind key: payloadtoolarge, filetoolarge, servicerejected, unreadable, inconsistent
     *                or failed.
     */
    public static function classify_kind(\stdClass $record): string {
        $message = trim((string) ($record->errormessage ?? ''));

        if ($message !== '') {
            $stringman = get_string_manager();
            $langs = array_unique(array_merge(['en'], array_keys($stringman->get_list_of_translations(true))));
            foreach (self::MESSAGE_KINDS as $key => $kind) {
                foreach ($langs as $lang) {
                    $strings = $stringman->load_component_strings('local_assign_ai', $lang);
                    if (!isset($strings[$key])) {
                        continue;
                    }
                    $parts = preg_split('/\{\$a(?:->\w+)?\}/', $strings[$key]);
                    $pattern = '/^' . implode('.*?', array_map(fn($part) => preg_quote($part, '/'), $parts)) . '$/su';
                    if (preg_match($pattern, $message)) {
                        return $kind;
                    }
                }
            }
        }

        return 'failed';
    }

    /**
     * Sends the digest and marks the reported records.
     */
    public function execute() {
        global $DB;

        // A never-saved setting (false) counts as the default (enabled); an explicit 0 or '' disables it.
        $enabled = get_config('local_assign_ai', 'errornotifyenabled');
        if ($enabled !== false && empty($enabled)) {
            return;
        }
        $recipients = setting_emaillist::get_valid_addresses(get_config('local_assign_ai', 'errornotifyemails'));
        if (!$recipients) {
            return;
        }

        $now = time();
        $records = $DB->get_records_select(
            'local_assign_ai_pending',
            'status = :failed AND retries >= :max
                AND (errornotifiedtime IS NULL OR errornotifiedtime < timemodified)
                AND timemodified >= :since',
            [
                'failed' => assign_submission::STATUS_FAILED,
                'max' => retry_failed_submissions::MAX_RETRIES,
                'since' => $now - self::MAX_AGE,
            ],
            'id ASC',
            'id, courseid, assignmentid, status, errormessage, timemodified'
        );
        if (!$records) {
            return;
        }

        // Group by course, activity and kind.
        $groups = [];
        foreach ($records as $record) {
            $kind = self::classify_kind($record);
            $key = $record->courseid . '|' . $record->assignmentid . '|' . $kind;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'courseid' => (int) $record->courseid,
                    'cmid' => (int) $record->assignmentid,
                    'kind' => $kind,
                    'records' => [],
                ];
            }
            $groups[$key]['records'][$record->id] = (int) $record->timemodified;
        }
        // Biggest groups first; the sort is stable so equal groups keep insertion order.
        uasort($groups, fn($a, $b) => count($b['records']) <=> count($a['records']));

        $shown = array_slice($groups, 0, self::MAX_GROUPS);
        $hidden = count($groups) - count($shown);

        $lang = get_config('core', 'lang') ?: 'en';
        $lines = [];
        $reported = [];
        foreach ($shown as $group) {
            $course = $DB->get_record('course', ['id' => $group['courseid']], 'id, shortname');
            $cm = get_coursemodule_from_id('assign', $group['cmid'], 0, false, IGNORE_MISSING);
            if (!$course || !$cm) {
                // The course or activity is gone: nothing to link to, and nothing to report.
                $reported += $group['records'];
                continue;
            }
            $count = count($group['records']);
            $reported += $group['records'];
            $lines[] = [
                'course' => format_string($course->shortname, true, ['context' => \context_course::instance($course->id)]),
                'activity' => format_string($cm->name, true, ['context' => \context_module::instance($cm->id)]),
                'kind' => self::string('errordigest_kind_' . $group['kind'], null, $lang),
                'count' => $count,
                'url' => (new \moodle_url('/local/assign_ai/history.php', ['id' => $cm->id]))->out(false),
            ];
        }
        if (!$lines) {
            $this->mark($reported);
            return;
        }

        $site = format_string(get_site()->shortname, true, ['context' => \context_system::instance()]);
        $subject = self::string('errordigest_subject', (object) ['site' => $site, 'count' => count($records)], $lang);
        [$text, $html] = $this->build_bodies($lines, $hidden, $lang);

        // Mark only what was actually reported. Sending is done once PER address because
        // email_to_user() supports neither CC nor BCC, and addresses must not see each other.
        $sent = 0;
        $from = \core_user::get_noreply_user();
        foreach ($recipients as $address) {
            try {
                // External address: same dummy user (id -1) approach as core\antivirus\manager.
                $to = clone \core_user::get_noreply_user();
                $to->id = -1;
                $to->email = $address;
                $to->firstname = '';
                $to->lastname = '';
                // The noreply clone is plain text, which makes email_to_user() drop the HTML part.
                $to->mailformat = FORMAT_HTML;
                $to->emailstop = 0;
                if (email_to_user($to, $from, $subject, $text, $html)) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                debugging('local_assign_ai error digest could not be sent to one address: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        if ($sent > 0) {
            $this->mark($reported);
        }
        mtrace("local_assign_ai: error digest sent to {$sent} of " . count($recipients) . ' address(es).');
    }

    /**
     * Gets a plugin string in an explicit language.
     *
     * @param string $identifier String identifier.
     * @param mixed $a Placeholder values.
     * @param string $lang Language code.
     * @return string
     */
    private static function string(string $identifier, $a, string $lang): string {
        return get_string_manager()->get_string($identifier, 'local_assign_ai', $a, $lang);
    }

    /**
     * Marks records as reported, keeping each record's own timemodified so later changes re-notify.
     *
     * @param array $records Map of record id => timemodified seen when selected.
     */
    private function mark(array $records): void {
        global $DB;
        foreach ($records as $id => $timemodified) {
            $DB->set_field('local_assign_ai_pending', 'errornotifiedtime', $timemodified, ['id' => $id]);
        }
    }

    /**
     * Builds the plain text and HTML bodies.
     *
     * @param array $lines Group lines (course, activity, kind, count, url).
     * @param int $hidden Number of groups not listed.
     * @param string $lang Language code.
     * @return string[] [text, html]
     */
    private function build_bodies(array $lines, int $hidden, string $lang): array {
        $text = self::string('errordigest_intro', null, $lang) . "\n\n";
        $html = \html_writer::tag('p', s(self::string('errordigest_intro', null, $lang))) . '<ul>';
        foreach ($lines as $line) {
            $a = (object) $line;
            $label = self::string('errordigest_group', $a, $lang);
            $text .= "- {$label}\n  {$line['url']}\n";
            $html .= '<li>' . s($label) . ' <a href="' . s($line['url']) . '">' . s($line['url']) . '</a></li>';
        }
        $html .= '</ul>';
        if ($hidden > 0) {
            $more = self::string('errordigest_more', $hidden, $lang);
            $text .= "\n{$more}\n";
            $html .= \html_writer::tag('p', s($more));
        }
        $footer = self::string('errordigest_footer', null, $lang);
        $text .= "\n{$footer}\n";
        $html .= \html_writer::tag('p', s($footer));
        return [$text, $html];
    }
}
