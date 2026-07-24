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

/**
 * Behat data generator for local_assign_ai.
 *
 * @package     local_assign_ai
 * @category    test
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Behat data generator class for local_assign_ai.
 *
 * Exposed entities:
 *  - "local_assign_ai > pending records": rows of local_assign_ai_pending. The "assign" column
 *    is the activity idnumber and is stored as the course module id (cmid) in "assignmentid".
 *  - "local_assign_ai > configs": rows of local_assign_ai_config. The "assign" column is the
 *    activity idnumber; it is resolved to a cmid here and to the assign INSTANCE id by the
 *    component generator, because local_assign_ai_config.assignmentid stores {assign}.id.
 *
 * @package     local_assign_ai
 * @category    test
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_assign_ai_generator extends behat_generator_base {
    /**
     * Get a list of the entities that can be created for this component.
     *
     * @return array Entity definitions.
     */
    protected function get_creatable_entities(): array {
        return [
            'pending records' => [
                'singular' => 'pending record',
                'datagenerator' => 'pending',
                'required' => ['assign', 'user'],
                'switchids' => [
                    // The pending table keys records by cmid, so get_assign_id() (cmid) fits directly.
                    'assign' => 'assignmentid',
                    'user' => 'userid',
                ],
            ],
            'configs' => [
                'singular' => 'config',
                'datagenerator' => 'config',
                'required' => ['assign'],
                'switchids' => [
                    // The config table keys records by assign INSTANCE id: pass the cmid through
                    // and let local_assign_ai_generator::create_config() resolve the instance id.
                    'assign' => 'cmid',
                ],
            ],
        ];
    }

    /**
     * Look up the course module id (cmid) of an assign activity from its idnumber.
     *
     * Note this returns the COURSE MODULE id, which is what local_assign_ai_pending.assignmentid
     * stores. local_assign_ai_config.assignmentid stores the assign instance id instead; the
     * component generator converts the cmid for that entity.
     *
     * @param string $idnumber The activity idnumber, for example "assign1".
     * @return int The corresponding course module id.
     */
    protected function get_assign_id(string $idnumber): int {
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
}
