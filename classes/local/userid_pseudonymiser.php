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

namespace local_assign_ai\local;

/**
 * Port for the userid pseudonymisation applied to outbound AI payloads.
 *
 * Resolved through the DI container: production binds {@see provider_pseudonymiser}, which
 * delegates to the AI provider's shared helper, and the unit tests bind a deterministic fake so
 * they run regardless of the provider version installed.
 *
 * @package     local_assign_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface userid_pseudonymiser {
    /**
     * Return a stable, non-reversible, site-scoped token for a user id.
     *
     * The same user on the same site always yields the same token, the raw Moodle id cannot be
     * recovered from it, and tokens cannot be correlated across sites.
     *
     * @param int|string $userid Moodle user id (raw id or an already derived token).
     * @return string 32-character lowercase hexadecimal token.
     */
    public function pseudonymise(int|string $userid): string;
}
