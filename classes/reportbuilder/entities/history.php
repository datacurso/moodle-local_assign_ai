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

defined('MOODLE_INTERNAL') || die();

use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\report\column;
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
                ->add_fields("{$user}.firstname, {$user}.lastname, {$user}.firstnamephonetic, {$user}.lastnamephonetic, {$user}.middlename, {$user}.alternatename")
                ->add_callback(static function($value, \stdClass $row): string {
                    return fullname($row);
                })
        );

        $this->add_column(
            (new column('email', new lang_string('email', 'local_assign_ai'), $this->get_entity_name()))
                ->add_joins($this->get_joins())
                ->add_field("{$user}.email")
                ->add_callback(static function($value): string {
                    return s((string) $value);
                })
        );

        $this->add_column(
            (new column('status', new lang_string('status', 'local_assign_ai'), $this->get_entity_name()))
                ->add_joins($this->get_joins())
                ->add_field("{$pending}.status")
                ->add_callback(static function($value): string {
                    return match ((string) $value) {
                        assign_submission::STATUS_APPROVED => get_string('statusapprove', 'local_assign_ai'),
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
                ->add_callback(static function($value, \stdClass $row): string {
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
                ->add_callback(static function($value): string {
                    return $value !== null ? (string) $value : '-';
                })
        );

        $this->add_column(
            (new column('lastmodified', new lang_string('lastmodified', 'local_assign_ai'), $this->get_entity_name()))
                ->add_joins($this->get_joins())
                ->add_field("{$pending}.timemodified")
                ->add_callback(static function($value): string {
                    return !empty($value) ? userdate((int) $value) : '-';
                })
        );

        return $this;
    }
}
