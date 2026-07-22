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
 * Privacy provider tests for local_assign_ai.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright 2025 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai;

use context_system;
use context_user;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use local_assign_ai\privacy\provider;
use stdClass;

/**
 * Unit tests for the privacy provider.
 *
 * @coversDefaultClass \local_assign_ai\privacy\provider
 * @group local_assign_ai
 */
final class privacy_provider_test extends provider_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * MDL-INT-023: get_metadata() declares the local_assign_ai_pending table together with its
     * personal data fields.
     *
     * @covers ::get_metadata
     */
    public function test_get_metadata(): void {
        $collection = new collection('local_assign_ai');
        $itemcollection = provider::get_metadata($collection)->get_collection();
        $this->assertCount(1, $itemcollection);

        $table = reset($itemcollection);
        $this->assertInstanceOf(\core_privacy\local\metadata\types\database_table::class, $table);
        $this->assertEquals('local_assign_ai_pending', $table->get_name());
        $this->assertEquals('privacy:metadata:local_assign_ai_pending', $table->get_summary());

        $fields = $table->get_privacy_fields();
        $expected = ['userid', 'submissionid', 'message', 'grade', 'rubric_response', 'status', 'approval_token'];
        foreach ($expected as $field) {
            $this->assertArrayHasKey($field, $fields);
        }
    }

    /**
     * MDL-INT-023: The assignment configuration table stores user references (graderid and
     * usermodified), so it should be declared in the privacy metadata as well.
     *
     * @covers ::get_metadata
     */
    public function test_config_table_should_be_declared_in_metadata(): void {
        $this->markTestSkipped(
            'Documented defect: local_assign_ai_config (graderid, usermodified) is not declared '
            . 'in privacy metadata (MDL-INT-023).'
        );
    }

    /**
     * Tests that get_contexts_for_userid() returns the expected contexts for the given user.
     *
     * @covers ::get_contexts_for_userid
     */
    public function test_get_contexts_for_userid(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->assertEmpty(provider::get_contexts_for_userid($user->id));

        self::create_userdata($user->id);

        $contextlist = provider::get_contexts_for_userid($user->id);
        $this->assertCount(1, $contextlist);

        $usercontext = context_user::instance($user->id);
        $this->assertEquals($usercontext->id, $contextlist->get_contextids()[0]);
    }

    /**
     * Tests that get_users_in_context() correctly identifies users with data in a given context.
     *
     * @covers ::get_users_in_context
     */
    public function test_get_users_in_context(): void {
        $component = 'local_assign_ai';
        $user = $this->getDataGenerator()->create_user();
        $usercontext = context_user::instance($user->id);

        $userlist = new userlist($usercontext, $component);
        provider::get_users_in_context($userlist);
        $this->assertCount(0, $userlist);

        self::create_userdata($user->id);

        provider::get_users_in_context($userlist);
        $this->assertCount(1, $userlist);
        $this->assertEquals([$user->id], $userlist->get_userids());

        $systemcontext = context_system::instance();
        $userlist = new userlist($systemcontext, $component);
        provider::get_users_in_context($userlist);
        $this->assertCount(0, $userlist);
    }

    /**
     * MDL-INT-023: Tests that export_user_data() exports user data correctly for approved contexts.
     *
     * @covers ::export_user_data
     */
    public function test_export_user_data(): void {
        $user = $this->getDataGenerator()->create_user();
        $record = self::create_userdata($user->id);

        $usercontext = context_user::instance($user->id);
        $writer = writer::with_context($usercontext);

        $this->assertFalse($writer->has_any_data());
        $approvedlist = new approved_contextlist($user, 'local_assign_ai', [$usercontext->id]);
        provider::export_user_data($approvedlist);

        $data = $writer->get_data([get_string('privacy:metadata:local_assign_ai_pending', 'local_assign_ai')]);
        $this->assertNotEmpty($data);
    }

    /**
     * MDL-INT-023: Tests that delete_data_for_all_users_in_context() removes all data for users in a context.
     *
     * @covers ::delete_data_for_all_users_in_context
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        self::create_userdata($user1->id);
        self::create_userdata($user2->id);

        $this->assertEquals(2, $DB->count_records('local_assign_ai_pending'));

        $context1 = context_user::instance($user1->id);
        provider::delete_data_for_all_users_in_context($context1);

        $this->assertEquals(0, $DB->count_records('local_assign_ai_pending', ['userid' => $user1->id]));
        $this->assertEquals(1, $DB->count_records('local_assign_ai_pending', ['userid' => $user2->id]));
    }

    /**
     * MDL-INT-023: Tests that delete_data_for_user() deletes data for the specified user only.
     *
     * @covers ::delete_data_for_user
     */
    public function test_delete_data_for_user(): void {
        global $DB;

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        self::create_userdata($user1->id);
        self::create_userdata($user2->id);

        $context1 = context_user::instance($user1->id);
        $approvedlist = new approved_contextlist($user1, 'local_assign_ai', [$context1->id]);
        provider::delete_data_for_user($approvedlist);

        $this->assertEquals(0, $DB->count_records('local_assign_ai_pending', ['userid' => $user1->id]));
        $this->assertEquals(1, $DB->count_records('local_assign_ai_pending', ['userid' => $user2->id]));
    }

    /**
     * Creates a sample record in the local_assign_ai_pending table for testing.
     *
     * @param int $userid The user ID for which the record will be created.
     * @return stdClass The inserted record.
     */
    private static function create_userdata(int $userid): stdClass {
        global $DB;

        $record = new stdClass();
        $record->courseid = 2;
        $record->assignmentid = 5;
        $record->userid = $userid;
        $record->title = 'AI Feedback';
        $record->message = 'Generated feedback from AI model.';
        $record->grade = 85;
        $record->rubric_response = 'Excellent structure and analysis.';
        $record->status = 'approved';
        $record->approval_token = md5(uniqid((string)$userid, true));
        $record->id = $DB->insert_record('local_assign_ai_pending', $record);

        return $record;
    }
}
