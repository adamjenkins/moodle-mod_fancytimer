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

namespace mod_fancytimer\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_fancytimer\local\remote;

/**
 * mod_fancytimer_get_updates: queued commands (controllers only) and the last published state.
 *
 * sincecommandid <= 0 is the initial sync: no commands, only lastcommandid. Commands are
 * returned at most 50 at a time (remote::commands_since()); lastcommandid is then the id of
 * the last one returned, so the caller picks up the rest on its next poll.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_updates extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'sincecommandid' => new external_value(PARAM_INT, 'Return commands newer than this id; 0 for none'),
        ]);
    }

    /**
     * Read the updates.
     *
     * @param int $cmid
     * @param int $sincecommandid
     * @return array
     */
    public static function execute(int $cmid, int $sincecommandid): array {
        global $DB;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'sincecommandid' => $sincecommandid]
        );

        [$course, $cm] = get_course_and_cm_from_cmid($params['cmid'], 'fancytimer');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/fancytimer:view', $context);
        $fancytimer = $DB->get_record('fancytimer', ['id' => $cm->instance], 'id', MUST_EXIST);
        $fancytimerid = (int)$fancytimer->id;

        $commands = [];
        if ($params['sincecommandid'] > 0 && has_capability('mod/fancytimer:control', $context)) {
            foreach (remote::commands_since($fancytimerid, $params['sincecommandid']) as $row) {
                $commands[] = [
                    'id' => (int)$row->id,
                    'command' => (string)$row->command,
                    'value' => (int)$row->value,
                ];
            }
        }
        $lastcommandid = $commands ? $commands[count($commands) - 1]['id'] : remote::last_command_id($fancytimerid);

        $state = remote::get_state($fancytimerid);
        return [
            'commands' => $commands,
            'lastcommandid' => $lastcommandid,
            'state' => $state['state'],
            'agems' => $state['agems'],
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'commands' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Command id'),
                'command' => new external_value(PARAM_ALPHA, 'Command'),
                'value' => new external_value(PARAM_INT, 'Command value'),
            ])),
            'lastcommandid' => new external_value(PARAM_INT, 'Id to pass as sincecommandid next time'),
            'state' => new external_value(PARAM_RAW, 'Last published state JSON, or empty'),
            'agems' => new external_value(PARAM_INT, 'Age of the state in ms; -1 when never published'),
        ]);
    }
}
