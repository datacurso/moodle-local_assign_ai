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

// NOTE: no MOODLE_INTERNAL check since this file is required by Behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Behat steps and page resolvers for local_assign_ai.
 *
 * @package     local_assign_ai
 * @category    test
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_assign_ai extends behat_base {

    /**
     * Convert page names to URLs for steps like "When I am on the "X" "local_assign_ai > Y" page".
     *
     * Recognised page types:
     *  - "review": the Review with AI page. Identifier: assign activity idnumber.
     *  - "history": the AI review history page. Identifier: assign activity idnumber.
     *  - "log": the single log view of the latest record. Identifier: "<idnumber> > <username>".
     *
     * @param string $type Identifies the page type.
     * @param string $identifier Identifies the particular page.
     * @return moodle_url The page URL.
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        switch (strtolower($type)) {
            case 'review':
                return new moodle_url('/local/assign_ai/review.php', ['id' => $this->get_assign_cmid($identifier)]);

            case 'history':
                return new moodle_url('/local/assign_ai/history.php', ['id' => $this->get_assign_cmid($identifier)]);

            case 'log':
                [$idnumber, $username] = array_map('trim', explode('>', $identifier));
                $cmid = $this->get_assign_cmid($idnumber);
                return new moodle_url('/local/assign_ai/history.php', [
                    'id' => $cmid,
                    'logid' => $this->get_latest_pending_id($cmid, $username),
                ]);

            default:
                throw new Exception('Unrecognised local_assign_ai page type "' . $type . '".');
        }
    }

    /**
     * Visit a plugin page expecting the access-denied error, then leave the error page.
     *
     * The final navigation to the site home is required because Moodle's Behat hooks fail any
     * step that finishes on a fatal error page, and review.php/history.php throw a
     * moodle_exception when the user lacks the local/assign_ai:review capability.
     *
     * @Then /^the "(?P<identifier_string>(?:[^"]|\\")*)" "(?P<type_string>[^"]*)" page of local_assign_ai should deny access$/
     *
     * @param string $identifier Identifies the particular page (assign activity idnumber).
     * @param string $type Identifies the page type (review or history).
     */
    public function accessing_page_should_be_denied(string $identifier, string $type): void {
        $url = $this->resolve_page_instance_url($type, $identifier);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));

        // Assert directly through Mink (not via execute()) so no chained exception check
        // runs while we are still on the error page.
        $expected = get_string('nopermissions', 'error', get_string('assign_ai:review', 'local_assign_ai'));
        $this->assertSession()->pageTextContains($expected);

        // Leave the error page so the automatic exception check does not flag this scenario.
        $this->getSession()->visit($this->locate_path('/'));
    }

    /**
     * Get the course module id of an assign activity from its idnumber.
     *
     * @param string $idnumber The activity idnumber.
     * @return int The course module id (what local_assign_ai_pending.assignmentid stores).
     */
    protected function get_assign_cmid(string $idnumber): int {
        global $DB;

        $sql = "SELECT cm.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                 WHERE cm.idnumber = :idnumber AND m.name = 'assign'";
        $cmid = $DB->get_field_sql($sql, ['idnumber' => $idnumber]);
        if (!$cmid) {
            throw new Exception('There is no assign activity with idnumber "' . $idnumber . '".');
        }

        return (int) $cmid;
    }

    /**
     * Get the most recent local_assign_ai_pending record id for a user in an assignment.
     *
     * @param int $cmid The assign course module id.
     * @param string $username The student username.
     * @return int The local_assign_ai_pending record id.
     */
    protected function get_latest_pending_id(int $cmid, string $username): int {
        global $DB;

        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $records = $DB->get_records('local_assign_ai_pending', [
            'assignmentid' => $cmid,
            'userid' => $userid,
        ], 'id DESC', 'id', 0, 1);
        if (!$records) {
            throw new Exception('No local_assign_ai_pending record found for user "' . $username . '".');
        }

        return (int) array_key_first($records);
    }
}
