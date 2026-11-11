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
 * Access tests for the approve_all_pending external function.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai\external;

use local_assign_ai\assign_submission;

/**
 * LAA-SEC-006: approve_all_pending authorizes before looking up the reviews.
 *
 * @coversDefaultClass \local_assign_ai\external\approve_all_pending
 * @group local_assign_ai
 */
final class approve_all_pending_test extends \advanced_testcase {
    /**
     * Skips every test when the Datacurso AI provider is not installed.
     */
    protected function setUp(): void {
        parent::setUp();
        // The CI pipeline does not install declared dependencies; without the Datacurso AI provider these tests cannot run.
        if (!class_exists(\aiprovider_datacurso\local\plugin_settings::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed.');
        }
    }

    /**
     * Runs a call and returns the exception it throws.
     *
     * @param callable $call The call expected to fail.
     * @return \Throwable The thrown exception.
     */
    private function capture(callable $call): \Throwable {
        try {
            $call();
        } catch (\Throwable $e) {
            return $e;
        }
        $this->fail('An exception was expected.');
    }

    /**
     * An unenrolled user gets the same error for an activity with and without pending reviews.
     *
     * @covers ::execute
     * @runInSeparateProcess
     */
    public function test_unenrolled_user_gets_the_same_error_whether_or_not_reviews_exist(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $withreviews = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $withoutreviews = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        [, $cmwith] = get_course_and_cm_from_instance($withreviews->id, 'assign');
        [, $cmwithout] = get_course_and_cm_from_instance($withoutreviews->id, 'assign');
        $pending = $this->getDataGenerator()->get_plugin_generator('local_assign_ai')->create_pending([
            'assignmentid' => $cmwith->id,
            'userid' => $student->id,
            'status' => assign_submission::STATUS_PENDING,
        ]);
        $this->setUser($this->getDataGenerator()->create_user());

        $existing = $this->capture(fn() => approve_all_pending::execute((int) $course->id, (int) $cmwith->id));
        $absent = $this->capture(fn() => approve_all_pending::execute((int) $course->id, (int) $cmwithout->id));

        $this->assertInstanceOf(\require_login_exception::class, $existing);
        $this->assertSame(get_class($existing), get_class($absent));
        $this->assertSame($existing->errorcode, $absent->errorcode);
        $this->assertSame(
            assign_submission::STATUS_PENDING,
            $DB->get_field('local_assign_ai_pending', 'status', ['id' => $pending->id])
        );
    }
}
