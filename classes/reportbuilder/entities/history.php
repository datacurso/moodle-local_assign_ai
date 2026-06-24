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

namespace local_assign_ai\reportbuilder\entities;

use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use html_writer;
use lang_string;
use local_assign_ai\assign_submission;

/**
 * AI history report entity.
 *
 * @package    local_assign_ai
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class history extends base {
    /**
     * {@inheritDoc}
     */
    protected function get_default_tables(): array {
        return ['local_assign_ai_pending', 'user'];
    }

    /**
     * {@inheritDoc}
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('reviewhistory', 'local_assign_ai');
    }

    /**
     * {@inheritDoc}
     */
    public function initialise(): base {
        $pending = $this->get_table_alias('local_assign_ai_pending');
        $user = $this->get_table_alias('user');

        $this->add_join("JOIN {user} {$user} ON {$user}.id = {$pending}.userid");

        $this->add_column(
            (new column('fullname', new lang_string('fullname', 'local_assign_ai'), $this->get_entity_name()))
                ->add_joins($this->get_joins())
                ->add_fields(
                    "{$user}.firstname, {$user}.lastname, {$user}.firstnamephonetic, " .
                    "{$user}.lastnamephonetic, {$user}.middlename, {$user}.alternatename"
                )
                ->add_callback(static function ($value, \stdClass $row): string {
                    return fullname($row);
                })
        );

        $this->add_column(
            (new column('email', new lang_string('email', 'local_assign_ai'), $this->get_entity_name()))
                ->add_joins($this->get_joins())
                ->add_field("{$user}.email")
                ->add_callback(static function ($value): string {
                    return s((string) $value);
                })
        );

        // SQL flag: 1 when a more recent visible evaluation (approve/failed) exists for the same
        // student in the same assignment, i.e. this row is a superseded (previous) evaluation.
        $statusin = "'" . assign_submission::STATUS_APPROVED . "','" . assign_submission::STATUS_FAILED . "'";
        $supersededsql = "(CASE WHEN EXISTS (
            SELECT 1 FROM {local_assign_ai_pending} hp2
             WHERE hp2.assignmentid = {$pending}.assignmentid
               AND hp2.userid = {$pending}.userid
               AND hp2.status IN ({$statusin})
               AND hp2.id > {$pending}.id
        ) THEN 1 ELSE 0 END)";

        $this->add_column(
            (new column('attempt', new lang_string('attemptnumber', 'local_assign_ai'), $this->get_entity_name()))
                ->add_joins($this->get_joins())
                ->add_field("{$pending}.attemptnumber", 'attemptnumber')
                ->add_field($supersededsql, 'issuperseded')
                ->set_is_sortable(true, ["{$pending}.attemptnumber"])
                ->add_callback(static function ($value, \stdClass $row): string {
                    $label = (string) ((int) $row->attemptnumber + 1);
                    if (!empty($row->issuperseded)) {
                        return $label . ' ' . html_writer::span(
                            get_string('superseded', 'local_assign_ai'),
                            'badge bg-secondary'
                        );
                    }
                    return $label . ' ' . html_writer::span(
                        get_string('current', 'local_assign_ai'),
                        'badge bg-success'
                    );
                })
        );

        $this->add_column(
            (new column('status', new lang_string('status', 'local_assign_ai'), $this->get_entity_name()))
                ->add_joins($this->get_joins())
                ->add_field("{$pending}.status")
                ->add_callback(static function ($value): string {
                    return match ((string) $value) {
                        assign_submission::STATUS_APPROVED => get_string('statusapprove', 'local_assign_ai'),
                        assign_submission::STATUS_SUPERSEDED => get_string('statussuperseded', 'local_assign_ai'),
                        assign_submission::STATUS_FAILED,
                        assign_submission::STATUS_REJECTED => get_string('statuserror', 'local_assign_ai'),
                        default => get_string('statuspending', 'local_assign_ai'),
                    };
                })
        );

        $this->add_column(
            (new column('log', new lang_string('log', 'local_assign_ai'), $this->get_entity_name()))
                ->add_joins($this->get_joins())
                ->add_fields("{$pending}.status, {$pending}.errormessage")
                ->add_callback(static function ($value, \stdClass $row): string {
                    if ((string) ($row->status ?? '') === assign_submission::STATUS_FAILED) {
                        $output = html_writer::span(
                            get_string('logfailed', 'local_assign_ai'),
                            'badge bg-danger'
                        );
                        $reason = format_text((string) ($row->errormessage ?? ''), FORMAT_PLAIN);
                        if ($reason !== '') {
                            $output .= html_writer::div($reason, 'text-danger small');
                        }
                        return $output;
                    }

                    return html_writer::span(
                        get_string('logsuccess', 'local_assign_ai'),
                        'badge bg-success'
                    );
                })
        );

        $this->add_column(
            (new column('grade', new lang_string('grade', 'local_assign_ai'), $this->get_entity_name()))
                ->add_joins($this->get_joins())
                ->add_field("{$pending}.grade")
                ->add_callback(static function ($value): string {
                    return $value !== null ? (string) $value : '-';
                })
        );

        $this->add_column(
            (new column('lastmodified', new lang_string('lastmodified', 'local_assign_ai'), $this->get_entity_name()))
                ->add_joins($this->get_joins())
                ->add_field("{$pending}.timemodified")
                ->add_callback(static function ($value): string {
                    return !empty($value) ? userdate((int) $value) : '-';
                })
        );

        // Filters (enable the report's "Filters" UI).
        global $DB;

        $this->add_filter(
            (new filter(
                text::class,
                'fullname',
                new lang_string('fullname', 'local_assign_ai'),
                $this->get_entity_name(),
                $DB->sql_fullname("{$user}.firstname", "{$user}.lastname")
            ))->add_joins($this->get_joins())
        );

        $this->add_filter(
            (new filter(
                text::class,
                'email',
                new lang_string('email', 'local_assign_ai'),
                $this->get_entity_name(),
                "{$user}.email"
            ))->add_joins($this->get_joins())
        );

        $this->add_filter(
            (new filter(
                select::class,
                'validity',
                new lang_string('validity', 'local_assign_ai'),
                $this->get_entity_name(),
                $supersededsql
            ))
                ->add_joins($this->get_joins())
                ->set_options([
                    0 => get_string('current', 'local_assign_ai'),
                    1 => get_string('superseded', 'local_assign_ai'),
                ])
        );

        $this->add_filter(
            (new filter(
                date::class,
                'lastmodified',
                new lang_string('lastmodified', 'local_assign_ai'),
                $this->get_entity_name(),
                "{$pending}.timemodified"
            ))->add_joins($this->get_joins())
        );

        // Registro (log): success vs failed, derived from the record status.
        $this->add_filter(
            (new filter(
                select::class,
                'log',
                new lang_string('log', 'local_assign_ai'),
                $this->get_entity_name(),
                "{$pending}.status"
            ))
                ->add_joins($this->get_joins())
                ->set_options([
                    assign_submission::STATUS_APPROVED => get_string('logsuccess', 'local_assign_ai'),
                    assign_submission::STATUS_FAILED => get_string('logfailed', 'local_assign_ai'),
                ])
        );

        $this->add_filter(
            (new filter(
                number::class,
                'grade',
                new lang_string('grade', 'local_assign_ai'),
                $this->get_entity_name(),
                "{$pending}.grade"
            ))->add_joins($this->get_joins())
        );

        return $this;
    }
}
