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

namespace local_assign_ai\output;

/**
 * Tests for the tenant selector of the tenant settings page.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_assign_ai\output\tenant_selector
 * @group     local_assign_ai
 */
final class tenant_selector_test extends \advanced_testcase {
    /**
     * The selector is a url_select (it navigates on change) with one entry per tenant, current one selected.
     */
    public function test_selector_navigates_to_the_chosen_tenant(): void {
        $this->resetAfterTest();

        $select = tenant_selector::create([3 => 'Alpha', 8 => 'Beta'], 8);

        $this->assertInstanceOf(\url_select::class, $select);
        $base = (new \moodle_url('/local/assign_ai/tenant_settings.php'))->out(false);
        $this->assertSame(['Alpha', 'Beta'], array_values($select->urls));
        $this->assertSame($base . '?tenantid=3', array_key_first($select->urls));
        $this->assertSame($base . '?tenantid=8', $select->selected);
    }

    /**
     * The select is rendered with the auto submit behaviour registered for its form.
     */
    public function test_selector_renders_with_autosubmit(): void {
        global $PAGE;

        $this->resetAfterTest();
        $PAGE->set_url('/local/assign_ai/tenant_settings.php');

        $html = $PAGE->get_renderer('core')->render(tenant_selector::create([3 => 'Alpha', 8 => 'Beta'], 3));

        $this->assertStringContainsString('Alpha', $html);
        $this->assertStringContainsString('Beta', $html);
        $this->assertStringContainsString('local_assign_ai_tenantselector', $html);
        $this->assertStringContainsString('accessibleChange', $PAGE->requires->get_end_code());
    }

    /**
     * The page and menu title names the plugin so the page is recognisable.
     */
    public function test_page_title_names_the_plugin(): void {
        $this->assertSame(
            'Assign AI tenant settings',
            get_string_manager()->get_string('tenantsettings', 'local_assign_ai', null, 'en')
        );
    }
}
