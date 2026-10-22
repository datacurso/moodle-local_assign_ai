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

namespace local_assign_ai\admin;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Admin textarea setting holding a list of email addresses (one per line).
 *
 * Addresses may also be separated by commas or semicolons. The list is validated on save and stored
 * normalised: trimmed, lowercased, de-duplicated, one address per line.
 *
 * @package     local_assign_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_emaillist extends \admin_setting_configtextarea {
    /** @var int Maximum number of addresses accepted. */
    public const MAX_ADDRESSES = 10;

    /**
     * Constructor.
     *
     * @param string $name Unique setting name (plugin/name).
     * @param string $visiblename Localised name.
     * @param string $description Localised description.
     * @param string $defaultsetting Default value.
     */
    public function __construct(string $name, string $visiblename, string $description, string $defaultsetting = '') {
        parent::__construct($name, $visiblename, $description, $defaultsetting, PARAM_RAW, 40, 4);
    }

    /**
     * Splits a raw list into unique, lowercased addresses.
     *
     * @param string|null $raw Raw list (newline, comma, semicolon or whitespace separated).
     * @return array Addresses in order of first appearance, valid or not.
     */
    public static function split(?string $raw): array {
        $parts = preg_split('/[\s,;]+/', trim((string) $raw), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_map('core_text::strtolower', $parts)));
    }

    /**
     * Returns the valid addresses of a raw list (at most MAX_ADDRESSES).
     *
     * @param string|null $raw Raw list.
     * @return string[] Valid addresses.
     */
    public static function get_valid_addresses(?string $raw): array {
        $valid = array_filter(self::split($raw), fn($address) => validate_email($address));
        return array_slice(array_values($valid), 0, self::MAX_ADDRESSES);
    }

    #[\Override]
    public function validate($data) {
        $result = parent::validate($data);
        if ($result !== true) {
            return $result;
        }
        $addresses = self::split($data);
        $invalid = array_filter($addresses, fn($address) => !validate_email($address));
        if ($invalid) {
            return get_string('errornotifyemails_invalid', 'local_assign_ai', implode(', ', $invalid));
        }
        if (count($addresses) > self::MAX_ADDRESSES) {
            return get_string('errornotifyemails_toomany', 'local_assign_ai', self::MAX_ADDRESSES);
        }
        return true;
    }

    #[\Override]
    public function write_setting($data) {
        $validated = $this->validate($data);
        if ($validated !== true) {
            return $validated;
        }
        return parent::write_setting(implode("\n", self::split($data)));
    }
}
