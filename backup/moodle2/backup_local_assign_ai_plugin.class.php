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

        // Container for pending/approved AI feedback.
        $pendings = new backup_nested_element('assign_ai_pendings');
        $pluginwrapper->add_child($pendings);

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

        return $plugin;
    }

    /**
     * Define the per-activity structure to include in the backup.
     *
     * The assignment AI configuration is activity-scoped (keyed by the assign instance id),
     * so it is backed up at the module level. This way it travels with the activity in both
     * whole-course copies and single-activity duplications.
     *
     * @return backup_plugin_element
     */
    protected function define_module_plugin_structure() {
        $plugin = $this->get_plugin_element(null);

        // Only assignments carry AI configuration.
        if ($this->task->get_modulename() !== 'assign') {
            return $plugin;
        }

        $pluginwrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($pluginwrapper);

        $configs = new backup_nested_element('assign_ai_configs');
        $pluginwrapper->add_child($configs);

        // Assignment-level configuration for local_assign_ai.
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

        // Capture the configuration for this specific assignment instance.
        $config->set_source_sql('
            SELECT c.*
              FROM {local_assign_ai_config} c
             WHERE c.assignmentid = ?
        ', [backup::VAR_ACTIVITYID]);

        $config->annotate_ids('user', 'graderid');
        $config->annotate_ids('user', 'usermodified');

        return $plugin;
    }
}
