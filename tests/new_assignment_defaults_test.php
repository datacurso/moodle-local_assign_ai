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

namespace local_assign_ai;

use local_assign_ai\config\assignment_config;
use local_assign_ai\local\tenant_config;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/assign_ai/lib.php');
require_once($CFG->libdir . '/formslib.php');

/**
 * Tests for the defaults that pre-fill and seed a new assignment (no instance exists yet).
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_assign_ai\config\assignment_config::get_effective_for_course
 * @covers    ::local_assign_ai_coursemodule_standard_elements
 * @covers    ::local_assign_ai_coursemodule_edit_post_actions
 * @group     local_assign_ai
 */
final class new_assignment_defaults_test extends \advanced_testcase {
    /**
     * Skips the test when Moodle Workplace tenancy is not installed.
     */
    private function require_tenancy(): void {
        if (!class_exists('\tool_tenant\tenancy')) {
            $this->markTestSkipped('Moodle Workplace (tool_tenant) is not installed.');
        }
    }

    /**
     * Creates a tenant with its own category.
     *
     * @param string $name Tenant name.
     * @return \stdClass
     */
    private function create_tenant(string $name): \stdClass {
        $category = $this->getDataGenerator()->create_category(['name' => $name . ' category']);
        return $this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_tenant([
            'name' => $name,
            'categoryid' => $category->id,
        ]);
    }

    /**
     * Builds the "add assignment" form elements for a course and returns the form.
     *
     * @param \stdClass $course Course being edited.
     * @return \MoodleQuickForm
     */
    private function build_new_assignment_form(\stdClass $course): \MoodleQuickForm {
        $mform = new \MoodleQuickForm('newassign', 'post', '');
        $wrapper = new class ($course) {
            /** @var \stdClass */
            private $course;

            /**
             * Constructor.
             *
             * @param \stdClass $course Course.
             */
            public function __construct($course) {
                $this->course = $course;
            }

            /**
             * Course of the form.
             *
             * @return \stdClass
             */
            public function get_course() {
                return $this->course;
            }

            /**
             * Current module data of a module that does not exist yet.
             *
             * @return \stdClass
             */
            public function get_current() {
                return (object) ['modulename' => 'assign', 'instance' => 0, 'course' => $this->course->id];
            }
        };
        local_assign_ai_coursemodule_standard_elements($wrapper, $mform);

        return $mform;
    }

    /**
     * Without tenancy a new assignment still gets the site defaults.
     */
    public function test_new_assignment_uses_site_defaults_without_tenant(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('defaultautograde', 1, 'local_assign_ai');
        set_config('defaultprompt', 'Site prompt', 'local_assign_ai');
        $course = $this->getDataGenerator()->create_course();

        $effective = assignment_config::get_effective_for_course((int) $course->id);
        $this->assertSame(1, $effective->autograde);
        $this->assertSame('Site prompt', $effective->prompt);

        $mform = $this->build_new_assignment_form($course);
        $this->assertEquals('Site prompt', $mform->getElement('local_assign_ai_prompt')->getValue());
    }

    /**
     * The course tenant defaults are used for a new assignment, not the site values.
     */
    public function test_new_assignment_uses_the_course_tenant_defaults(): void {
        $this->require_tenancy();
        $this->resetAfterTest();
        $this->setAdminUser();

        $tenant = $this->create_tenant('Tenant defaults');
        set_config('defaultprompt', 'Site prompt', 'local_assign_ai');
        set_config('defaultautograde', 0, 'local_assign_ai');
        tenant_config::set('defaultprompt', (int) $tenant->id, 'Tenant prompt');
        tenant_config::set('defaultautograde', (int) $tenant->id, 1);
        tenant_config::set('defaultusedelay', (int) $tenant->id, 1);
        tenant_config::set('defaultdelayminutes', (int) $tenant->id, 25);
        $course = $this->getDataGenerator()->create_course(['category' => $tenant->categoryid]);

        $effective = assignment_config::get_effective_for_course((int) $course->id);
        $this->assertSame(1, $effective->autograde);
        $this->assertSame(25, $effective->delayminutes);
        $this->assertSame('Tenant prompt', $effective->prompt);

        $mform = $this->build_new_assignment_form($course);
        $this->assertEquals('Tenant prompt', $mform->getElement('local_assign_ai_prompt')->getValue());
        $this->assertEquals([1], $mform->getElement('local_assign_ai_autograde')->getValue());
        $this->assertEquals([25], (array) $mform->getElement('local_assign_ai_delayminutes')->getValue());
    }

    /**
     * A tenant that paused the AI by default starts new assignments with the AI off.
     */
    public function test_new_assignment_follows_the_tenant_ai_default(): void {
        $this->require_tenancy();
        $this->resetAfterTest();

        $tenant = $this->create_tenant('Tenant paused');
        set_config('defaultenableai', 1, 'local_assign_ai');
        tenant_config::set('defaultenableai', (int) $tenant->id, 0);
        $course = $this->getDataGenerator()->create_course(['category' => $tenant->categoryid]);

        $this->assertSame(0, assignment_config::get_effective_for_course((int) $course->id)->enableai);
    }

    /**
     * The row stored when the assignment is created takes the course tenant defaults for missing form fields.
     */
    public function test_created_assignment_row_is_seeded_with_the_tenant_defaults(): void {
        global $DB;

        $this->require_tenancy();
        $this->resetAfterTest();
        $this->setAdminUser();

        $tenant = $this->create_tenant('Tenant seeded');
        tenant_config::set('defaultprompt', (int) $tenant->id, 'Tenant prompt');
        tenant_config::set('defaultautograde', (int) $tenant->id, 1);
        $course = $this->getDataGenerator()->create_course(['category' => $tenant->categoryid]);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $DB->delete_records('local_assign_ai_config', ['assignmentid' => $assign->id]);

        $data = (object) ['modulename' => 'assign', 'instance' => $assign->id];
        local_assign_ai_coursemodule_edit_post_actions($data, $course);

        $row = $DB->get_record('local_assign_ai_config', ['assignmentid' => $assign->id], '*', MUST_EXIST);
        $this->assertSame('Tenant prompt', $row->prompt);
        $this->assertEquals(1, $row->autograde);
    }
}
