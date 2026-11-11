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
 * Single point that checks the Datacurso AI provider before the plugin uses it.
 *
 * Assign AI keeps its on/off state in the Datacurso AI provider through
 * `\aiprovider_datacurso\local\plugin_settings`, a class added on the provider's Moodle 5.x line in 2.2.0. When an
 * older provider is installed the class does not exist; every caller goes through this guard so
 * the site keeps working: hooks, observers, tasks and callbacks do nothing, while the plugin's
 * pages and web services stop with a message asking to update the provider.
 *
 * @package     local_assign_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider_dependency {
    /** Frankenstyle name of this plugin in the provider. */
    private const COMPONENT = 'local_assign_ai';

    /** Provider class introduced in aiprovider_datacurso 2.2.0 (Moodle 5.x line). */
    private const SETTINGS_CLASS = '\\aiprovider_datacurso\\local\\plugin_settings';

    /** Methods of the provider class the plugin calls. */
    private const SETTINGS_METHODS = ['is_enabled', 'require_enabled'];

    /** @var bool|null Availability forced by PHPUnit tests; null uses the installed provider. */
    private static ?bool $availabilityoverride = null;

    /**
     * Whether the installed Datacurso AI provider has the API this plugin needs (2.2.0 or later).
     *
     * @return bool
     */
    public static function is_available(): bool {
        if (self::$availabilityoverride !== null) {
            return self::$availabilityoverride;
        }
        if (!class_exists(self::SETTINGS_CLASS)) {
            return false;
        }
        foreach (self::SETTINGS_METHODS as $method) {
            if (!method_exists(self::SETTINGS_CLASS, $method)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether Assign AI may run: the provider is available and enables the plugin.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        if (!self::is_available()) {
            return false;
        }
        $class = self::SETTINGS_CLASS;
        return $class::is_enabled(self::COMPONENT);
    }

    /**
     * Stop the request when Assign AI may not run.
     *
     * With an old provider a site administrator is told to update it and gets a link to the
     * plugins overview; anyone else gets a generic message. Otherwise the provider decides.
     *
     * @return void
     * @throws \moodle_exception providerupdaterequired(_admin), or the provider's plugindisabled(_admin).
     */
    public static function require_enabled(): void {
        if (!self::is_available()) {
            if (has_capability('moodle/site:config', \context_system::instance())) {
                $url = new \moodle_url('/admin/plugins.php', ['contribonly' => 1]);
                throw new \moodle_exception('providerupdaterequired_admin', self::COMPONENT, $url);
            }
            throw new \moodle_exception('providerupdaterequired', self::COMPONENT);
        }
        $class = self::SETTINGS_CLASS;
        $class::require_enabled(self::COMPONENT);
    }

    /**
     * Message a task logs when it skips its run because Assign AI may not run.
     *
     * @return string
     */
    public static function task_skipped_message(): string {
        $key = self::is_available() ? 'task_skipped_plugindisabled' : 'task_skipped_providerupdaterequired';
        return get_string($key, self::COMPONENT);
    }

    /**
     * Force the provider availability in PHPUnit tests, to simulate an older provider.
     *
     * @param bool|null $available Forced availability; null restores the real check.
     * @return void
     * @throws \coding_exception When called outside PHPUnit.
     */
    public static function override_availability_for_testing(?bool $available): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('override_availability_for_testing() is only for PHPUnit tests.');
        }
        self::$availabilityoverride = $available;
    }
}
