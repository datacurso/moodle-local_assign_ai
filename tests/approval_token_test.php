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
 * Tests for the approval token generator.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai;

/**
 * Ensures approval tokens are long and randomly generated.
 *
 * @coversDefaultClass \local_assign_ai\assign_submission
 * @group local_assign_ai
 */
final class approval_token_test extends \advanced_testcase {
    /**
     * The token fills the 64-char column, is alphanumeric and unpredictable.
     *
     * @covers ::generate_approval_token
     */
    public function test_generate_approval_token(): void {
        $token = assign_submission::generate_approval_token();

        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $token);
        $this->assertNotSame($token, assign_submission::generate_approval_token());
    }
}
