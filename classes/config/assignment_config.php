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

namespace local_assign_ai\config;

use assign;

/**
 * Assignment configuration helpers for local_assign_ai.
 *
 * @package     local_assign_ai
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_config {
    /**
     * Checks whether assign AI features are globally enabled.
     *
     * @return bool
     */
    public static function is_feature_enabled(): bool {
        $enabled = get_config('local_assign_ai', 'enableassignai');
        if ($enabled === false || $enabled === '') {
            return true;
        }

        return !empty($enabled);
    }

    /**
     * Checks whether assignment AI can be enabled globally.
     *
     * @return bool
     */
    public static function is_global_ai_enabled(): bool {
        $enabled = get_config('local_assign_ai', 'defaultenableai');
        if ($enabled === false || $enabled === '') {
            return true;
        }

        return !empty($enabled);
    }

    /**
     * Retrieves cached configuration for a given assignment instance.
     *
     * @param int $assignmentid The assignment instance ID (from {assign}).
     * @return \stdClass|null
     */
    public static function get(int $assignmentid): ?\stdClass {
        global $DB;

        static $cache = [];

        if (!$assignmentid) {
            return null;
        }

        if (!array_key_exists($assignmentid, $cache)) {
            $record = $DB->get_record('local_assign_ai_config', ['assignmentid' => $assignmentid]);
            $cache[$assignmentid] = $record ?: null;
        }

        return $cache[$assignmentid];
    }

    /**
     * Checks whether auto-grading is enabled for a given assignment.
     *
     * @param assign $assign The assignment instance.
     * @return bool
     */
    public static function is_autograde_enabled(assign $assign): bool {
        if (!self::is_feature_enabled()) {
            return false;
        }

        $config = self::get_effective((int)$assign->get_instance()->id);
        return !empty($config->enableai) && !empty($config->autograde);
    }

    /**
     * Returns the effective configuration for an assignment, falling back to site defaults.
     *
     * @param int $assignmentid The assignment instance ID (from {assign}).
     * @return \stdClass
     */
    public static function get_effective(int $assignmentid): \stdClass {
        $record = self::get($assignmentid);

        $rawdefaultenableai = get_config('local_assign_ai', 'defaultenableai');
        $rawdefaultautograde = get_config('local_assign_ai', 'defaultautograde');
        $rawdefaultusedelay = get_config('local_assign_ai', 'defaultusedelay');
        $rawdefaultdelayminutes = get_config('local_assign_ai', 'defaultdelayminutes');
        $rawdefaultprompt = get_config('local_assign_ai', 'defaultprompt');
        $rawdefaultlang = get_config('core', 'lang');

        $defaultenableai = ($rawdefaultenableai === false || $rawdefaultenableai === '') ? 1 : (int)$rawdefaultenableai;
        $defaultautograde = ($rawdefaultautograde === false || $rawdefaultautograde === '') ? 0 : (int)$rawdefaultautograde;
        $defaultusedelay = ($rawdefaultusedelay === false || $rawdefaultusedelay === '') ? 0 : (int)$rawdefaultusedelay;
        $defaultdelayminutes = ($rawdefaultdelayminutes === false || $rawdefaultdelayminutes === '')
            ? 60
            : max(1, (int)$rawdefaultdelayminutes);
        $defaultprompt = ($rawdefaultprompt === false || trim((string)$rawdefaultprompt) === '')
            ? get_string('promptdefaulttext', 'local_assign_ai')
            : (string)$rawdefaultprompt;
        $defaultlang = ($rawdefaultlang === false || trim((string)$rawdefaultlang) === '')
            ? current_language()
            : trim((string)$rawdefaultlang);

        $config = (object) [
            'enableai' => $defaultenableai,
            'autograde' => $defaultautograde,
            'usedelay' => $defaultusedelay,
            'delayminutes' => $defaultdelayminutes,
            'graderid' => null,
            'prompt' => $defaultprompt,
            'lang' => $defaultlang,
        ];

        if (!$record) {
            return $config;
        }

        if (isset($record->enableai)) {
            $config->enableai = (int)$record->enableai;
        }
        if (isset($record->autograde)) {
            $config->autograde = (int)$record->autograde;
        }
        if (isset($record->usedelay)) {
            $config->usedelay = (int)$record->usedelay;
        }
        if (isset($record->delayminutes) && (int)$record->delayminutes > 0) {
            $config->delayminutes = (int)$record->delayminutes;
        }
        if (!empty($record->graderid)) {
            $config->graderid = (int)$record->graderid;
        }
        if (isset($record->prompt) && trim((string)$record->prompt) !== '') {
            $config->prompt = (string)$record->prompt;
        }
        if (isset($record->lang) && trim((string)$record->lang) !== '') {
            $config->lang = trim((string)$record->lang);
        }

        if (!self::is_global_ai_enabled()) {
            $config->enableai = 0;
            $config->autograde = 0;
            $config->usedelay = 0;
            $config->delayminutes = 0;
            $config->graderid = null;
        }

        return $config;
    }
}
