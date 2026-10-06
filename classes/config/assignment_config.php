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
use local_assign_ai\local\tenant_config;
use local_assign_ai\local\tenant_context;

/**
 * Assignment configuration helpers for local_assign_ai.
 *
 * @package     local_assign_ai
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_config {
    /**
     * Returns a global default for a tenant.
     *
     * Every read of a site level default goes through here, so a Workplace tenant can override it.
     * Without tenancy (or a stored tenant value) the site wide plugin config is returned.
     *
     * @param string $name Setting name.
     * @param int|null $tenantid Tenant id, null for the tenant of the current user, 0 for the site value.
     * @return mixed Raw stored value, or false when it is not set anywhere.
     */
    public static function get_default(string $name, ?int $tenantid = null) {
        $tenantid = $tenantid ?? tenant_context::get_current_tenant_id();
        return tenant_config::get($name, $tenantid);
    }

    /**
     * Returns the normalised global defaults of a tenant.
     *
     * @param int|null $tenantid Tenant id, null for the tenant of the current user, 0 for the site values.
     * @return \stdClass With enableai, autograde, usedelay, delayminutes, prompt and lang.
     */
    public static function get_defaults(?int $tenantid = null): \stdClass {
        $tenantid = $tenantid ?? tenant_context::get_current_tenant_id();

        $rawenableai = self::get_default('defaultenableai', $tenantid);
        $rawautograde = self::get_default('defaultautograde', $tenantid);
        $rawusedelay = self::get_default('defaultusedelay', $tenantid);
        $rawdelayminutes = self::get_default('defaultdelayminutes', $tenantid);
        $rawprompt = self::get_default('defaultprompt', $tenantid);
        $rawlang = get_config('core', 'lang');

        return (object) [
            'enableai' => ($rawenableai === false || $rawenableai === '') ? 1 : (int)$rawenableai,
            'autograde' => ($rawautograde === false || $rawautograde === '') ? 0 : (int)$rawautograde,
            'usedelay' => ($rawusedelay === false || $rawusedelay === '') ? 0 : (int)$rawusedelay,
            'delayminutes' => ($rawdelayminutes === false || $rawdelayminutes === '')
                ? 60
                : max(1, (int)$rawdelayminutes),
            'prompt' => ($rawprompt === false || trim((string)$rawprompt) === '')
                ? get_string('promptdefaulttext', 'local_assign_ai')
                : (string)$rawprompt,
            'lang' => ($rawlang === false || trim((string)$rawlang) === '')
                ? current_language()
                : trim((string)$rawlang),
        ];
    }

    /**
     * Checks whether assign AI features are globally enabled.
     *
     * @param int|null $tenantid Tenant id, null for the tenant of the current user.
     * @return bool
     */
    public static function is_feature_enabled(?int $tenantid = null): bool {
        $enabled = self::get_default('enableassignai', $tenantid);
        if ($enabled === false || $enabled === '') {
            return true;
        }

        return !empty($enabled);
    }

    /**
     * Checks whether assignment AI can be enabled globally.
     *
     * @param int|null $tenantid Tenant id, null for the tenant of the current user.
     * @return bool
     */
    public static function is_global_ai_enabled(?int $tenantid = null): bool {
        $enabled = self::get_default('defaultenableai', $tenantid);
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
        $assignmentid = (int)$assign->get_instance()->id;
        if (!self::is_feature_enabled(tenant_context::get_tenant_id_for_assignment($assignmentid))) {
            return false;
        }

        $config = self::get_effective($assignmentid);
        return !empty($config->enableai) && !empty($config->autograde);
    }

    /**
     * Returns the effective configuration for an assignment, falling back to the defaults of its tenant.
     *
     * @param int $assignmentid The assignment instance ID (from {assign}).
     * @return \stdClass
     */
    public static function get_effective(int $assignmentid): \stdClass {
        return self::build_effective(
            self::get($assignmentid),
            tenant_context::get_tenant_id_for_assignment($assignmentid)
        );
    }

    /**
     * Returns the configuration a new assignment of a course starts with.
     *
     * A new assignment has no instance yet, so its tenant is resolved from the course being edited.
     *
     * @param int $courseid Course id.
     * @return \stdClass
     */
    public static function get_effective_for_course(int $courseid): \stdClass {
        return self::build_effective(null, tenant_context::resolve($courseid));
    }

    /**
     * Whether AI processing may run for an assignment: the tenant switches and its own switch are on.
     *
     * @param int $assignmentid The assignment instance ID (from {assign}).
     * @return bool
     */
    public static function is_ai_enabled_for_assignment(int $assignmentid): bool {
        if (!self::is_feature_enabled(tenant_context::get_tenant_id_for_assignment($assignmentid))) {
            return false;
        }

        return !empty(self::get_effective($assignmentid)->enableai);
    }

    /**
     * Merges the stored row (if any) over the defaults of a tenant.
     *
     * @param \stdClass|null $record Stored assignment configuration.
     * @param int $tenantid Tenant id used for the defaults.
     * @return \stdClass
     */
    private static function build_effective(?\stdClass $record, int $tenantid): \stdClass {
        $defaults = self::get_defaults($tenantid);

        $config = (object) [
            'enableai' => $defaults->enableai,
            'autograde' => $defaults->autograde,
            'usedelay' => $defaults->usedelay,
            'delayminutes' => $defaults->delayminutes,
            'graderid' => null,
            'prompt' => $defaults->prompt,
            'lang' => $defaults->lang,
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

        if (!self::is_global_ai_enabled($tenantid)) {
            $config->enableai = 0;
            $config->autograde = 0;
            $config->usedelay = 0;
            $config->delayminutes = 0;
            $config->graderid = null;
        }

        return $config;
    }
}
