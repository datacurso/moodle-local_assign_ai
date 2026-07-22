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
 * Tests for the review modal template.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai\output;

/**
 * Ensures the review modal template escapes the AI message (no stored XSS).
 *
 * @group local_assign_ai
 */
final class review_modal_test extends \advanced_testcase {
    /**
     * Renders the modal template with the given message.
     *
     * @param string $message The AI message.
     * @return string
     */
    private function render(string $message): string {
        global $OUTPUT;
        return $OUTPUT->render_from_template('local_assign_ai/review_modal', [
            'message' => $message,
            'courseid' => 1,
            'cmid' => 1,
            'userid' => 1,
            'savelabel' => 'Save',
            'saveapprovelabel' => 'Save and Approve',
            'canchangestatus' => true,
        ]);
    }

    /**
     * A malicious message must not break out of the textarea or inject nodes.
     */
    public function test_message_is_escaped(): void {
        $this->resetAfterTest();

        $html = $this->render('</textarea><script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('</textarea><script>', $html);
        $this->assertStringContainsString('&lt;/textarea&gt;', $html);
    }

    /**
     * Legitimate HTML feedback survives as an escaped round-trip value.
     */
    public function test_legitimate_html_is_preserved_escaped(): void {
        $this->resetAfterTest();

        $html = $this->render('<p>Bien hecho</p>');

        $this->assertStringContainsString('&lt;p&gt;Bien hecho&lt;/p&gt;', $html);
        $this->assertStringNotContainsString('<p>Bien hecho</p>', $html);
    }
}
