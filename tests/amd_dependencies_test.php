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
 * Tests that the AMD modules only depend on core modules that exist.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai;

/**
 * Checks the core AMD dependencies declared by the plugin JavaScript sources.
 *
 * A dependency on a core module that the running Moodle no longer ships (for example
 * core/modal_factory, removed in Moodle 5.2) stops RequireJS from executing the whole
 * plugin module, so none of its event handlers are registered.
 *
 * @group local_assign_ai
 * @coversNothing
 */
final class amd_dependencies_test extends \advanced_testcase {
    /**
     * Every core module imported by amd/src exists in the running Moodle.
     */
    public function test_core_amd_dependencies_exist_in_running_moodle(): void {
        $dependencies = $this->collect_core_dependencies();
        $this->assertNotEmpty($dependencies, 'No core AMD dependency found in amd/src.');

        $missing = [];
        foreach ($dependencies as $module => $files) {
            if (!file_exists($this->resolve_core_module_path($module))) {
                $missing[] = $module . ' (used in ' . implode(', ', $files) . ')';
            }
        }

        $this->assertSame([], $missing, 'Core AMD modules missing in this Moodle version: ' . implode('; ', $missing));
    }

    /**
     * Every direct TinyMCE initialisation in amd/src passes the GPL license key.
     *
     * TinyMCE 8 (shipped with Moodle 5.2) opens the editor in read-only mode when no
     * license_key is given; core only sets it in its own editor_tiny configuration.
     */
    public function test_tinymce_init_sets_gpl_license_key(): void {
        $srcdir = \core_component::get_component_directory('local_assign_ai') . '/amd/src';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcdir, \FilesystemIterator::SKIP_DOTS)
        );

        $checked = 0;
        $missing = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'js') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            preg_match_all('/\.init\(\{(.*?)\}\);/s', $source, $matches);
            foreach ($matches[1] as $config) {
                if (strpos($source, 'getTinyMCE') === false) {
                    continue;
                }
                $checked++;
                if (!preg_match('/\blicense_key\s*:\s*[\'"]gpl[\'"]/', $config)) {
                    $missing[] = substr($file->getPathname(), strlen($srcdir) + 1);
                }
            }
        }

        $this->assertGreaterThan(0, $checked, 'No TinyMCE initialisation found in amd/src.');
        $this->assertSame([], $missing, 'TinyMCE initialised without license_key gpl in: ' . implode(', ', $missing));
    }

    /**
     * Collects the core AMD modules required by every source file under amd/src.
     *
     * Covers ES2015 imports (`import x from 'core/x'`) and AMD define() dependency lists.
     *
     * @return array<string, string[]> Module name => relative source files using it.
     */
    private function collect_core_dependencies(): array {
        $srcdir = \core_component::get_component_directory('local_assign_ai') . '/amd/src';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcdir, \FilesystemIterator::SKIP_DOTS)
        );

        $pattern = '/(?:\bfrom\s*|\bimport\s*|[\[,]\s*)[\'"](core(?:_[a-z0-9]+)?\/[a-zA-Z0-9_\/-]+)[\'"]/';
        $dependencies = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'js') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($srcdir) + 1);
            preg_match_all($pattern, file_get_contents($file->getPathname()), $matches);
            foreach (array_unique($matches[1]) as $module) {
                $dependencies[$module][] = $relative;
            }
        }
        ksort($dependencies);
        return $dependencies;
    }

    /**
     * Resolves the source path of a core AMD module in the running Moodle.
     *
     * @param string $module Module name such as core/modal or core_user/repository.
     * @return string Absolute path of the expected source file.
     */
    private function resolve_core_module_path(string $module): string {
        global $CFG;

        [$component, $name] = explode('/', $module, 2);
        if ($component === 'core') {
            return $CFG->libdir . '/amd/src/' . $name . '.js';
        }

        $subsystemdir = \core_component::get_subsystem_directory(substr($component, strlen('core_')));
        return ($subsystemdir ?? '/nonexistent/' . $component) . '/amd/src/' . $name . '.js';
    }
}
