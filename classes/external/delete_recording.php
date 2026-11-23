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
use moodle_exception;
use mod_fancytimer\local\recordings;

/**
 * mod_fancytimer_delete_recording: delete a teacher recording of this timer.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_recording extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'recordingid' => new external_value(PARAM_INT, 'Recording id'),
        ]);
    }

    /**
     * Delete the recording.
     *
     * @param int $cmid
     * @param int $recordingid
     * @return array{ok: bool}
     */
    public static function execute(int $cmid, int $recordingid): array {
        global $DB;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'recordingid' => $recordingid]
        );

        [$course, $cm] = get_course_and_cm_from_cmid($params['cmid'], 'fancytimer');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('moodle/course:manageactivities', $context);
        $DB->get_record('fancytimer', ['id' => $cm->instance], 'id', MUST_EXIST);

        $recording = $DB->get_record(
            'fancytimer_recordings',
            ['id' => $params['recordingid'], 'fancytimerid' => $cm->instance]
        );
        if (!$recording) {
            throw new moodle_exception('recordingnotfound', 'mod_fancytimer');
        }
        recordings::delete($context, $recording);
        return ['ok' => true];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Whether the recording was deleted'),
        ]);
    }
}
