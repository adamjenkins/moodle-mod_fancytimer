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

namespace mod_fancytimer\local;

use context_module;
use moodle_exception;
use moodle_url;
use stdClass;

/**
 * Teacher recordings: a per-activity library of changeover messages.
 *
 * Each recording is a fancytimer_recordings row plus exactly one file in the
 * module context, file area 'recording', itemid = recording id, filepath '/'.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recordings {
    /** @var int Largest recording accepted, bytes (about 2 minutes of Opus speech). */
    public const MAX_BYTES = 2097152;

    /** @var int Most recordings per activity. */
    public const MAX_PER_ACTIVITY = 30;

    /** @var array Accepted container types => file extension. MediaRecorder output differs per browser. */
    public const MIMETYPES = [
        'audio/webm' => 'webm',
        'audio/ogg' => 'ogg',
        'audio/mp4' => 'm4a',
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
    ];

    /**
     * Recordings of an activity, oldest first.
     *
     * @param int $fancytimerid
     * @return stdClass[] id => record.
     */
    public static function for_activity(int $fancytimerid): array {
        global $DB;
        return $DB->get_records('fancytimer_recordings', ['fancytimerid' => $fancytimerid], 'id ASC');
    }

    /**
     * Store a new recording.
     *
     * @param context_module $context
     * @param int $fancytimerid
     * @param int $userid
     * @param string $name
     * @param string $mimetype As reported by MediaRecorder; parameters (";codecs=opus") are ignored.
     * @param string $bytes Raw audio.
     * @return stdClass The new record.
     * @throws moodle_exception recordinginvalid / recordingtoolarge / toomanyrecordings
     */
    public static function save(
        context_module $context,
        int $fancytimerid,
        int $userid,
        string $name,
        string $mimetype,
        string $bytes
    ): stdClass {
        global $DB;
        $type = strtolower(trim(explode(';', $mimetype)[0]));
        if (!isset(self::MIMETYPES[$type]) || $bytes === '') {
            throw new moodle_exception('recordinginvalid', 'mod_fancytimer');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new moodle_exception('recordingtoolarge', 'mod_fancytimer', '', display_size(self::MAX_BYTES));
        }
        if ($DB->count_records('fancytimer_recordings', ['fancytimerid' => $fancytimerid]) >= self::MAX_PER_ACTIVITY) {
            throw new moodle_exception('toomanyrecordings', 'mod_fancytimer', '', self::MAX_PER_ACTIVITY);
        }
        $record = (object)[
            'fancytimerid' => $fancytimerid,
            'userid' => $userid,
            'name' => \core_text::substr(clean_param(trim($name), PARAM_TEXT), 0, 255),
            'timecreated' => time(),
        ];
        $record->id = $DB->insert_record('fancytimer_recordings', $record);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_fancytimer',
            'filearea' => 'recording',
            'itemid' => $record->id,
            'filepath' => '/',
            'filename' => 'recording-' . $record->id . '.' . self::MIMETYPES[$type],
            'mimetype' => $type,
            'userid' => $userid,
        ], $bytes);
        return $record;
    }

    /**
     * Delete a recording; segments that used it fall back to the default sound.
     *
     * @param context_module $context
     * @param stdClass $recording
     */
    public static function delete(context_module $context, stdClass $recording): void {
        global $DB;
        $DB->set_field(
            'fancytimer_segments',
            'sound',
            sounds::DEFAULT_CHANGEOVER,
            ['fancytimerid' => $recording->fancytimerid, 'sound' => sounds::RECORDING_PREFIX . $recording->id]
        );
        get_file_storage()->delete_area_files($context->id, 'mod_fancytimer', 'recording', $recording->id);
        $DB->delete_records('fancytimer_recordings', ['id' => $recording->id]);
    }

    /**
     * URL of a recording's audio file, or null if the file is missing.
     *
     * @param context_module $context
     * @param int $recordingid
     * @return moodle_url|null
     */
    public static function url(context_module $context, int $recordingid): ?moodle_url {
        $files = get_file_storage()->get_area_files(
            $context->id,
            'mod_fancytimer',
            'recording',
            $recordingid,
            'id',
            false
        );
        if (!$files) {
            return null;
        }
        $file = reset($files);
        return moodle_url::make_pluginfile_url(
            $context->id,
            'mod_fancytimer',
            'recording',
            $recordingid,
            $file->get_filepath(),
            $file->get_filename()
        );
    }
}
