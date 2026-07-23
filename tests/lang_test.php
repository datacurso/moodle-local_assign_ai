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
 * Tests for the plugin language packs.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai;

/**
 * Unit tests for the completeness of the shipped language packs (MDL-INT-024).
 *
 * These are string-level checks (pack presence and key parity against the English reference);
 * full UI language switching is covered by acceptance (Behat) testing instead.
 *
 * @coversNothing
 * @group local_assign_ai
 */
final class lang_test extends \advanced_testcase {
    /** @var string[] Language pack codes the plugin ships. */
    private const EXPECTED_PACKS = ['de', 'en', 'es', 'es_mx', 'es_mx_kids', 'fr', 'id', 'pt_br', 'ru'];

    /**
     * Return the absolute path of the plugin lang directory.
     *
     * @return string The lang directory path.
     */
    private function get_lang_dir(): string {
        global $CFG;

        return $CFG->dirroot . '/local/assign_ai/lang';
    }

    /**
     * Load a language pack file in isolation and return the string keys it defines.
     *
     * @param string $langcode Language pack code (lang directory name).
     * @return string[] The defined string keys.
     */
    private function load_string_keys(string $langcode): array {
        $string = [];
        require($this->get_lang_dir() . '/' . $langcode . '/local_assign_ai.php');

        return array_keys($string);
    }

    /**
     * MDL-INT-024: The plugin ships exactly the nine expected language packs, each providing a
     * lang/<code>/local_assign_ai.php file.
     */
    public function test_expected_language_packs_are_present(): void {
        $langdir = $this->get_lang_dir();

        $found = [];
        foreach (new \DirectoryIterator($langdir) as $entry) {
            if ($entry->isDot() || !$entry->isDir()) {
                continue;
            }
            if (is_readable($langdir . '/' . $entry->getFilename() . '/local_assign_ai.php')) {
                $found[] = $entry->getFilename();
            }
        }
        sort($found);

        $expected = self::EXPECTED_PACKS;
        sort($expected);

        $this->assertSame($expected, $found);
    }

    /**
     * MDL-INT-024: Every shipped language pack defines exactly the same string keys as the
     * English reference pack, with no missing and no extra keys.
     */
    public function test_all_packs_define_the_same_string_keys_as_english(): void {
        $reference = $this->load_string_keys('en');
        $this->assertNotEmpty($reference);

        foreach (self::EXPECTED_PACKS as $langcode) {
            if ($langcode === 'en') {
                continue;
            }

            $keys = $this->load_string_keys($langcode);

            $missing = array_values(array_diff($reference, $keys));
            $this->assertSame(
                [],
                $missing,
                "Language pack '{$langcode}' is missing keys: " . implode(', ', $missing)
            );

            $extra = array_values(array_diff($keys, $reference));
            $this->assertSame(
                [],
                $extra,
                "Language pack '{$langcode}' defines extra keys: " . implode(', ', $extra)
            );
        }
    }
}
