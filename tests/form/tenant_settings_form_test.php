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

namespace local_assign_ai\form;

use local_assign_ai\config\assignment_config;
use local_assign_ai\local\tenant_config;

/**
 * Tests for the tenant settings form and the way its data is stored.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_assign_ai\form\tenant_settings_form
 * @covers    \local_assign_ai\local\tenant_config::save_form_data
 * @group     local_assign_ai
 */
final class tenant_settings_form_test extends \advanced_testcase {
    /**
     * Builds the form as if the browser had submitted the given fields.
     *
     * Dependent controls hidden by hideIf are disabled in the browser and therefore are not posted,
     * so the tests omit them exactly like a real request.
     *
     * @param array $posted Submitted fields (without tenantid).
     * @return tenant_settings_form
     */
    private function submit(array $posted): tenant_settings_form {
        $posted['tenantid'] = 5;
        tenant_settings_form::mock_submit($posted);

        return new tenant_settings_form(
            new \moodle_url('/local/assign_ai/tenant_settings.php'),
            ['tenants' => [5 => 'Tenant five'], 'tenantid' => 5]
        );
    }

    /**
     * Every combination of the dependent switches is a valid submission.
     *
     * @dataProvider valid_submission_provider
     * @param array $posted Submitted fields.
     */
    public function test_valid_submissions_are_accepted(array $posted): void {
        $this->resetAfterTest();

        $form = $this->submit($posted);

        $this->assertNotNull($form->get_data(), 'The form must validate without an error on a hidden field.');
    }

    /**
     * Valid submissions as the browser posts them (hidden controls are missing).
     *
     * @return array
     */
    public static function valid_submission_provider(): array {
        return [
            'everything off' => [['enableassignai' => 0, 'defaultenableai' => 0]],
            'ai off, dependants hidden' => [['enableassignai' => 1, 'defaultenableai' => 0]],
            'ai on, autograde off' => [['enableassignai' => 1, 'defaultenableai' => 1, 'defaultautograde' => 0,
                'defaultprompt' => 'p']],
            'autograde on, delay off' => [['enableassignai' => 1, 'defaultenableai' => 1, 'defaultautograde' => 1,
                'defaultusedelay' => 0, 'defaultprompt' => 'p']],
            'autograde on, delay on' => [['enableassignai' => 1, 'defaultenableai' => 1, 'defaultautograde' => 1,
                'defaultusedelay' => 1, 'defaultdelayminutes' => 15, 'defaultprompt' => 'p']],
        ];
    }

    /**
     * The delay is mandatory (and must be positive) only while it is visible.
     */
    public function test_delay_is_required_when_the_delay_is_in_use(): void {
        $this->resetAfterTest();

        $form = $this->submit([
            'enableassignai' => 1, 'defaultenableai' => 1, 'defaultautograde' => 1,
            'defaultusedelay' => 1, 'defaultdelayminutes' => 0, 'defaultprompt' => 'p',
        ]);

        $this->assertNull($form->get_data());
        $this->assertArrayHasKey('defaultdelayminutes', $form->validation(['defaultenableai' => 1,
            'defaultautograde' => 1, 'defaultusedelay' => 1, 'defaultdelayminutes' => 0], []));
    }

    /**
     * Switching the AI off is stored and the hidden delay keeps its stored value.
     */
    public function test_ai_switch_off_is_saved_and_keeps_hidden_delay(): void {
        $this->resetAfterTest();

        tenant_config::set('defaultdelayminutes', 5, 20);

        $form = $this->submit(['enableassignai' => 1, 'defaultenableai' => 0]);
        $data = $form->get_data();
        $this->assertNotNull($data);
        tenant_config::save_form_data(5, $data);

        $this->assertFalse(assignment_config::is_global_ai_enabled(5));
        $this->assertEquals(20, tenant_config::get('defaultdelayminutes', 5));
    }

    /**
     * A full submission stores every value, including a master switch turned off.
     */
    public function test_full_submission_is_stored(): void {
        $this->resetAfterTest();

        $form = $this->submit([
            'enableassignai' => 0, 'defaultenableai' => 1, 'defaultautograde' => 1,
            'defaultusedelay' => 1, 'defaultdelayminutes' => 15, 'defaultprompt' => 'Tenant prompt',
        ]);
        tenant_config::save_form_data(5, $form->get_data());

        $this->assertFalse(assignment_config::is_feature_enabled(5));
        $defaults = assignment_config::get_defaults(5);
        $this->assertSame(1, $defaults->autograde);
        $this->assertSame(1, $defaults->usedelay);
        $this->assertSame(15, $defaults->delayminutes);
        $this->assertSame('Tenant prompt', $defaults->prompt);
    }
}
