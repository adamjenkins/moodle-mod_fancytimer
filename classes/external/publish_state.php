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
use core_external\external_single_structure;
use core_external\external_value;
use invalid_parameter_exception;
use mod_fancytimer\local\remote;

/**
 * mod_fancytimer_publish_state: store the state of the timer for remotes and mirrors.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class publish_state extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'state' => new external_value(PARAM_RAW, 'State JSON'),
        ]);
    }

    /**
     * Publish the state.
     *
     * @param int $cmid
     * @param string $state
     * @return array{ok: bool}
     */
    public static function execute(int $cmid, string $state): array {
        global $DB, $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'state' => $state]);

        [$course, $cm] = get_course_and_cm_from_cmid($params['cmid'], 'fancytimer');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/fancytimer:control', $context);
        $fancytimer = $DB->get_record('fancytimer', ['id' => $cm->instance], 'id', MUST_EXIST);

        $clean = remote::clean_state($params['state']);
        if ($clean === null) {
            throw new invalid_parameter_exception('Invalid state');
        }
        remote::publish_state((int)$fancytimer->id, (int)$USER->id, $clean);
        return ['ok' => true];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Whether the state was stored'),
        ]);
    }
}
