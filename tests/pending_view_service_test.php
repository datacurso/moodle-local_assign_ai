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
 * Tests for the pending view service.
 *
 * @package   local_assign_ai
 * @category  test
 * @copyright  2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assign_ai;

use local_assign_ai\assign_submission;
use local_assign_ai\local\service\pending_view_service;

/**
 * Unit tests for the pending view service.
 *
 * @coversDefaultClass \local_assign_ai\local\service\pending_view_service
 * @group local_assign_ai
 */
final class pending_view_service_test extends \advanced_testcase {
    /**
     * Review state mappings return the expected flags per status.
     *
     * @dataProvider review_state_provider
     * @covers ::get_review_state
     * @param string $status Pending record status.
     * @param string $expectedstatekey Expected state key.
     * @param bool $canrequestai Whether AI can be requested.
     * @param bool $canapproveai Whether AI can be approved.
     * @param string $badgeclass Expected badge CSS class.
     * @param bool $inprogress Whether it is in progress.
     */
    public function test_get_review_state(
        string $status,
        string $expectedstatekey,
        bool $canrequestai,
        bool $canapproveai,
        string $badgeclass,
        bool $inprogress
    ): void {
        $state = pending_view_service::get_review_state($status);

        $this->assertSame($expectedstatekey, $state['statekey']);
        $this->assertSame($canrequestai, $state['canrequestai']);
        $this->assertSame($canapproveai, $state['canapproveai']);
        $this->assertSame($badgeclass, $state['statebadgeclass']);
        $this->assertSame($inprogress, $state['inprogress']);
    }

    /**
     * Data provider for review state mappings.
     *
     * @return array
     */
    public static function review_state_provider(): array {
        return [
            'initial' => [
                assign_submission::STATUS_INITIAL, assign_submission::STATUS_INITIAL,
                true, false, 'badge bg-secondary', false,
            ],
            'queued' => [
                assign_submission::STATUS_QUEUED, assign_submission::STATUS_QUEUED,
                false, false, 'badge bg-warning', false,
            ],
            'processing' => [
                assign_submission::STATUS_PROCESSING, assign_submission::STATUS_PROCESSING,
                false, false, 'badge bg-warning', true,
            ],
            'pending' => [
                assign_submission::STATUS_PENDING, assign_submission::STATUS_PENDING,
                false, true, 'badge bg-info', false,
            ],
        ];
    }
}
