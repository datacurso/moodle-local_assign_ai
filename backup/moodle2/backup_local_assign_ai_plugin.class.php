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
 * Backup plugin for local_assign_ai.
 *
 * @package    local_assign_ai
 * @category   backup
 * @copyright  2025 Datacurso
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_local_assign_ai_plugin extends backup_local_plugin {
    /**
     * Define the structure to include in the course backup.
     *
     * @return backup_plugin_element
     */
    protected function define_course_plugin_structure() {
        $plugin = $this->get_plugin_element(null);
        $pluginwrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($pluginwrapper);

        // Container for pending/approved AI feedback and assignment configs.
        $pendings = new backup_nested_element('assign_ai_pendings');
        $configs = new backup_nested_element('assign_ai_configs');
        $pluginwrapper->add_child($pendings);
        $pluginwrapper->add_child($configs);

        // Each record (pending or approved).
        $pending = new backup_nested_element('assign_ai_pending', ['id'], [
            'courseid',
            'assignmentid',
            'title',
            'userid',
            'message',
            'grade',
            'rubric_response',
            'errormessage',
            'status',
            'approval_token',
            'usermodified',
            'timecreated',
            'timemodified',
        ]);
        $pendings->add_child($pending);

        // Get all records (any status) for this course.
        $pending->set_source_sql('
            SELECT p.*
              FROM {local_assign_ai_pending} p
             WHERE p.courseid = ?
        ', [backup::VAR_COURSEID]);

        // Map dependent entities.
        $pending->annotate_ids('assign', 'assignmentid');
        $pending->annotate_ids('user', 'userid');
        $pending->annotate_ids('user', 'usermodified');
        $pending->annotate_ids('course', 'courseid');

        // Container with assignment-level configuration for local_assign_ai.
        $config = new backup_nested_element('assign_ai_config', ['id'], [
            'assignmentid',
            'enableai',
            'autograde',
            'graderid',
            'usedelay',
            'delayminutes',
            'prompt',
            'lang',
            'usermodified',
            'timecreated',
            'timemodified',
        ]);
        $configs->add_child($config);

        // Capture configurations only for assignments that belong to this course.
        $config->set_source_sql('
            SELECT c.*
              FROM {local_assign_ai_config} c
              JOIN {assign} a ON a.id = c.assignmentid
             WHERE a.course = ?
        ', [backup::VAR_COURSEID]);

        $config->annotate_ids('assign', 'assignmentid');
        $config->annotate_ids('user', 'graderid');
        $config->annotate_ids('user', 'usermodified');

        return $plugin;
    }
}
