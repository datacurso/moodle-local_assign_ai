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
 * Production adapter: delegates the userid pseudonymisation to the AI provider's shared helper.
 *
 * The whole Datacurso suite derives the same per-user token with one formula, owned by
 * aiprovider_datacurso; this plugin never implements a token derivation of its own.
 *
 * @package     local_assign_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider_pseudonymiser implements userid_pseudonymiser {
    /**
     * Return the provider's site-scoped token, keeping an already derived token as is.
     *
     * @param int|string $userid Moodle user id (raw id or an already derived token).
     * @return string 32-character lowercase hexadecimal token.
     */
    public function pseudonymise(int|string $userid): string {
        return \aiprovider_datacurso\local\outbound_privacy::pseudonymise_userid_value($userid);
    }
}
