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
 * Plugin upgrade steps are defined here.
 *
 * @package     local_assign_ai
 * @category    upgrade
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Execute local_assign_ai upgrade from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_assign_ai_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    // For further information please read {@link https://docs.moodle.org/dev/Upgrade_API}.
    //
    // You will also have to create the db/install.xml file by using the XMLDB Editor.
    // Documentation for the XMLDB Editor can be found at {@link https://docs.moodle.org/dev/XMLDB_editor}.

    if ($oldversion < 2025092506) {
        // Define table local_assign_ai_pending to be created.
        $table = new xmldb_table('local_assign_ai_pending');

        // Adding fields to table local_assign_ai_pending.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('assignmentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('message', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('approval_token', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table local_assign_ai_pending.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Conditionally launch create table for local_assign_ai_pending.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Assign_ai savepoint reached.
        upgrade_plugin_savepoint(true, 2025092506, 'local', 'assign_ai');
    }

    if ($oldversion < 2025092600) {
        $table = new xmldb_table('local_assign_ai_pending');

        // Agregar campo grade si no existe.
        $field = new xmldb_field('grade', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'message');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Agregar campo rubric_response si no existe.
        $field = new xmldb_field('rubric_response', XMLDB_TYPE_TEXT, null, null, null, null, null, 'grade');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2025092600, 'local', 'assign_ai');
    }

    if ($oldversion < 2025111305) {
        // Define field usermodified to be added to local_assign_ai_pending.
        $table = new xmldb_table('local_assign_ai_pending');
        $field = new xmldb_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'approval_token');

        // Conditionally launch add field usermodified.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field timecreated to be added to local_assign_ai_pending.
        $table = new xmldb_table('local_assign_ai_pending');
        $field = new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'usermodified');

        // Conditionally launch add field timecreated.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field timemodified to be added to local_assign_ai_pending.
        $table = new xmldb_table('local_assign_ai_pending');
        $field = new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'timecreated');

        // Conditionally launch add field timemodified.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $now = time();

        // Ensure timemodified always has a value.
        $DB->execute(
            "UPDATE {local_assign_ai_pending}
                SET timemodified = :now
              WHERE timemodified IS NULL OR timemodified = 0",
            ['now' => $now]
        );

        // Use timemodified when timecreated was empty.
        $DB->execute(
            "UPDATE {local_assign_ai_pending}
                SET timecreated = timemodified
              WHERE (timecreated IS NULL OR timecreated = 0)
                AND (timemodified IS NOT NULL AND timemodified > 0)"
        );

        // Fallback value for timecreated.
        $DB->execute(
            "UPDATE {local_assign_ai_pending}
                SET timecreated = :now
              WHERE timecreated IS NULL OR timecreated = 0",
            ['now' => $now]
        );

        // Populate usermodified with the originating userid when missing.
        $DB->execute(
            "UPDATE {local_assign_ai_pending}
                SET usermodified = userid
              WHERE (usermodified IS NULL OR usermodified = 0)
                AND userid IS NOT NULL"
        );

        // Assign_ai savepoint reached.
        upgrade_plugin_savepoint(true, 2025111305, 'local', 'assign_ai');
    }

    if ($oldversion < 2025111306) {
        $table = new xmldb_table('local_assign_ai_config');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('assignmentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('autograde', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('assignmentid_uniq', XMLDB_KEY_UNIQUE, ['assignmentid']);
        $table->add_key('config_user_fk', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025111306, 'local', 'assign_ai');
    }

    if ($oldversion < 2025111404) {
        // Define field autograde to be added to local_assign_ai_config.
        $table = new xmldb_table('local_assign_ai_config');
        $field = new xmldb_field('autograde', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'assignmentid');

        // Conditionally launch add field autograde.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field graderid to be added to local_assign_ai_config.
        $field = new xmldb_field('graderid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'autograde');

        // Conditionally launch add field graderid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define key config_grader_fk (foreign) to be added to local_assign_ai_config.
        $key = new xmldb_key('config_grader_fk', XMLDB_KEY_FOREIGN, ['graderid'], 'user', ['id']);

        // Launch add key config_grader_fk.
        $dbman->add_key($table, $key);

        // Assign_ai savepoint reached.
        upgrade_plugin_savepoint(true, 2025111404, 'local', 'assign_ai');
    }

    if ($oldversion < 2025120501) {
        // Define table local_assign_ai_queue to be created.
        $table = new xmldb_table('local_assign_ai_queue');

        // Adding fields to table local_assign_ai_queue.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('type', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('payload', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timetoprocess', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('processed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table local_assign_ai_queue.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Conditionally launch create table for local_assign_ai_queue.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Assign_ai savepoint reached.
        upgrade_plugin_savepoint(true, 2025120501, 'local', 'assign_ai');
    }

    if ($oldversion < 2025120504) {
        // Define field usedelay to be added to local_assign_ai_config.
        $table = new xmldb_table('local_assign_ai_config');
        $field = new xmldb_field('usedelay', XMLDB_TYPE_INTEGER, '1', null, null, null, '0', 'usermodified');

        // Conditionally launch add field usedelay.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Assign_ai savepoint reached.
        upgrade_plugin_savepoint(true, 2025120504, 'local', 'assign_ai');
    }

    if ($oldversion < 2025120505) {
        // Define field delayminutes to be added to local_assign_ai_config.
        $table = new xmldb_table('local_assign_ai_config');
        $field = new xmldb_field('delayminutes', XMLDB_TYPE_INTEGER, '6', null, null, null, '0', 'usedelay');

        // Conditionally launch add field delayminutes.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Assign_ai savepoint reached.
        upgrade_plugin_savepoint(true, 2025120505, 'local', 'assign_ai');
    }

    if ($oldversion < 2025120803) {
        $table = new xmldb_table('local_assign_ai_pending');
        $field = new xmldb_field('assessment_guide_response', XMLDB_TYPE_TEXT, null, null, null, null, null, 'rubric_response');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2025120803, 'local', 'assign_ai');
    }

    if ($oldversion < 2026110201) {
        // Define field prompt to be added to local_assign_ai_config.
        $table = new xmldb_table('local_assign_ai_config');
        $field = new xmldb_field('prompt', XMLDB_TYPE_TEXT, null, null, null, null, null, 'delayminutes');

        // Conditionally launch add field prompt.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026110201, 'local', 'assign_ai');
    }

    if ($oldversion < 2026110202) {
        // Force the default auto-approval setting to disabled by default.
        set_config('defaultautograde', 0, 'local_assign_ai');

        upgrade_plugin_savepoint(true, 2026110202, 'local', 'assign_ai');
    }

    if ($oldversion < 2026110203) {
        // Define field enableai to be added to local_assign_ai_config.
        $table = new xmldb_table('local_assign_ai_config');
        $field = new xmldb_field('enableai', XMLDB_TYPE_INTEGER, '1', null, null, null, null, 'assignmentid');

        // Conditionally launch add field enableai.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Ensure default value for the new global setting.
        set_config('defaultenableai', 1, 'local_assign_ai');

        upgrade_plugin_savepoint(true, 2026110203, 'local', 'assign_ai');
    }

    if ($oldversion < 2026110302) {
        // Define field lang to be added to local_assign_ai_config.
        $table = new xmldb_table('local_assign_ai_config');
        $field = new xmldb_field('lang', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'prompt');

        // Conditionally launch add field lang.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026110302, 'local', 'assign_ai');
    }

    if ($oldversion < 2026110308) {
        // Define field errormessage to be added to local_assign_ai_pending.
        $table = new xmldb_table('local_assign_ai_pending');
        $field = new xmldb_field('errormessage', XMLDB_TYPE_TEXT, null, null, null, null, null, 'assessment_guide_response');

        // Conditionally launch add field errormessage.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Assign_ai savepoint reached.
        upgrade_plugin_savepoint(true, 2026110308, 'local', 'assign_ai');
    }

    if ($oldversion < 2026110310) {
        // Define field retries to be added to local_assign_ai_pending.
        $table = new xmldb_table('local_assign_ai_pending');
        $field = new xmldb_field('retries', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'errormessage');

        // Conditionally launch add field retries.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Assign_ai savepoint reached.
        upgrade_plugin_savepoint(true, 2026110310, 'local', 'assign_ai');
    }

    if ($oldversion < 2026110313) {
        // Per-attempt tracking for AI feedback: add submissionid and attemptnumber.
        $table = new xmldb_table('local_assign_ai_pending');

        $submissionid = new xmldb_field('submissionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'userid');
        if (!$dbman->field_exists($table, $submissionid)) {
            $dbman->add_field($table, $submissionid);
        }

        $attemptnumber = new xmldb_field('attemptnumber', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'submissionid');
        if (!$dbman->field_exists($table, $attemptnumber)) {
            $dbman->add_field($table, $attemptnumber);
        }

        $index = new xmldb_index('submissionid_ix', XMLDB_INDEX_NOTUNIQUE, ['submissionid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Assign_ai savepoint reached.
        upgrade_plugin_savepoint(true, 2026110313, 'local', 'assign_ai');
    }

    if ($oldversion < 2026110315) {
        // Track which submission version each AI evaluation was generated for, to flag student edits.
        $table = new xmldb_table('local_assign_ai_pending');

        $submissionmodified = new xmldb_field(
            'submissionmodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'attemptnumber');
        if (!$dbman->field_exists($table, $submissionmodified)) {
            $dbman->add_field($table, $submissionmodified);
        }

        $edited = new xmldb_field('edited', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'submissionmodified');
        if (!$dbman->field_exists($table, $edited)) {
            $dbman->add_field($table, $edited);
        }

        // Assign_ai savepoint reached.
        upgrade_plugin_savepoint(true, 2026110315, 'local', 'assign_ai');
    }

    return true;
}
