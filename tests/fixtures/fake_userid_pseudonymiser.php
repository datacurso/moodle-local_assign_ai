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

namespace local_assign_ai;

use local_assign_ai\local\userid_pseudonymiser;

/**
 * Deterministic fake pseudonymiser so the unit tests run without the AI provider installed.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fake_userid_pseudonymiser implements userid_pseudonymiser {
    /**
     * Return a 32-character hexadecimal token derived only from the user id.
     *
     * @param int|string $userid Moodle user id.
     * @return string
     */
    public function pseudonymise(int|string $userid): string {
        return str_repeat('a', 24) . sprintf('%08x', (int) $userid);
    }
}
