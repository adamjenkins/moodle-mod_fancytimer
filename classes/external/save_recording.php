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
use mod_fancytimer\local\sounds;

/**
 * mod_fancytimer_save_recording: store a changeover message recorded in the browser.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_recording extends external_api {
    /**
     * Parameters.
     *
     * The name is PARAM_RAW because validate_parameters() rejects text that PARAM_TEXT
     * cleaning would change (such as "a < b"); recordings::save() cleans it before storing.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'name' => new external_value(PARAM_RAW, 'Name of the recording; may be empty'),
            'mimetype' => new external_value(PARAM_RAW, 'Mime type reported by MediaRecorder'),
            'audio' => new external_value(PARAM_RAW, 'Audio, base64 without a data: prefix'),
        ]);
    }

    /**
     * Save the recording.
     *
     * @param int $cmid
     * @param string $name
     * @param string $mimetype
     * @param string $audio
     * @return array
     */
    public static function execute(int $cmid, string $name, string $mimetype, string $audio): array {
        global $DB, $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'name' => $name, 'mimetype' => $mimetype, 'audio' => $audio]
        );

        [$course, $cm] = get_course_and_cm_from_cmid($params['cmid'], 'fancytimer');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('moodle/course:manageactivities', $context);
        $fancytimer = $DB->get_record('fancytimer', ['id' => $cm->instance], 'id', MUST_EXIST);

        // Refuse oversized uploads before decoding them.
        if (strlen($params['audio']) > (int)ceil(recordings::MAX_BYTES * 4 / 3) + 4) {
            throw new moodle_exception('recordingtoolarge', 'mod_fancytimer', '', display_size(recordings::MAX_BYTES));
        }
        $bytes = base64_decode($params['audio'], true);
        if ($bytes === false) {
            throw new moodle_exception('recordinginvalid', 'mod_fancytimer');
        }

        $record = recordings::save(
            $context,
            (int)$fancytimer->id,
            (int)$USER->id,
            trim($params['name']),
            $params['mimetype'],
            $bytes
        );
        $url = recordings::url($context, (int)$record->id);
        $displayname = trim((string)$record->name) !== '' ? (string)$record->name :
            get_string('recordingunnamed', 'mod_fancytimer', (int)$record->id);

        return [
            'id' => (int)$record->id,
            'name' => $displayname,
            'url' => $url ? $url->out(false) : '',
            'soundkey' => sounds::RECORDING_PREFIX . $record->id,
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Recording id'),
            'name' => new external_value(PARAM_TEXT, 'Name to display (the stored name, or "Recording N" when empty)'),
            'url' => new external_value(PARAM_URL, 'URL of the audio file'),
            'soundkey' => new external_value(PARAM_RAW, 'Sound key for a segment, rec:<id>'),
        ]);
    }
}
