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
 * Tests for the email list admin setting.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai\admin;

/**
 * Tests for {@see \local_assign_ai\admin\setting_emaillist}.
 *
 * @coversDefaultClass \local_assign_ai\admin\setting_emaillist
 * @group local_assign_ai
 */
final class setting_emaillist_test extends \advanced_testcase {
    /**
     * Builds the setting.
     *
     * @return setting_emaillist
     */
    private function make(): setting_emaillist {
        return new setting_emaillist('local_assign_ai/errornotifyemails', 'Emails', 'Desc', '');
    }

    /**
     * Valid lists with mixed separators are accepted, empty too.
     */
    public function test_valid_lists(): void {
        $this->assertTrue($this->make()->validate(''));
        $this->assertTrue($this->make()->validate("a@example.com, b@example.com;c@example.com\nd@example.com"));
    }

    /**
     * Invalid addresses are rejected and listed.
     */
    public function test_invalid_rejected(): void {
        $result = $this->make()->validate("a@example.com\nbad-one, other@");
        $this->assertIsString($result);
        $this->assertStringContainsString('bad-one', $result);
        $this->assertStringContainsString('other@', $result);
        $this->assertStringNotContainsString('a@example.com', $result);
    }

    /**
     * More than ten addresses are rejected.
     */
    public function test_max_ten(): void {
        $ok = [];
        for ($i = 1; $i <= 10; $i++) {
            $ok[] = "u{$i}@example.com";
        }
        $this->assertTrue($this->make()->validate(implode("\n", $ok)));
        $this->assertIsString($this->make()->validate(implode("\n", $ok) . "\nu11@example.com"));
    }

    /**
     * Saving normalises: trim, lowercase, dedupe, one per line.
     */
    public function test_write_normalises(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $error = $this->make()->write_setting(" A@Example.com ,b@example.com;a@example.com\r\n\r\nC@example.com ");
        $this->assertSame('', $error);
        $this->assertSame(
            "a@example.com\nb@example.com\nc@example.com",
            get_config('local_assign_ai', 'errornotifyemails')
        );
    }

    /**
     * Invalid input is not stored.
     */
    public function test_write_rejects_invalid(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $error = $this->make()->write_setting('nope');
        $this->assertNotSame('', $error);
        $this->assertEmpty(get_config('local_assign_ai', 'errornotifyemails'));
    }
}
