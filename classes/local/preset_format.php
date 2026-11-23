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

use moodle_exception;
use stdClass;

/**
 * The timer configuration format shared by presets, templates, import and export.
 *
 * JSON shape (version 1):
 *
 *     {
 *       "format": "mod_fancytimer-preset",
 *       "version": 1,
 *       "name": "Three-person talk",
 *       "settings": {"looping": 1, "rotationenabled": 1, "warningenabled": 0,
 *                    "warningsecs": 5, "warningsound": "tick"},
 *       "segments": [{"label": "Person 1 is speaking", "duration": 120,
 *                     "bgcolour": "#cfe2ff", "textcolour": "#212529", "sound": "chime"}],
 *       "rotation": {"label": "Change groups!", "duration": 30, ...} | null
 *     }
 *
 * Images and recordings are not part of the format: recording sounds become the
 * default chime when the config leaves its activity. Everything read from a file
 * or the database goes through validate(), which is the trust boundary.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preset_format {
    /** @var string Format marker. */
    public const FORMAT = 'mod_fancytimer-preset';

    /** @var int Current format version. */
    public const VERSION = 1;

    /** @var int Largest accepted JSON, bytes. */
    public const MAX_BYTES = 65536;

    /**
     * Validate and normalise a config.
     *
     * @param string|array $input JSON text or decoded array.
     * @param int $fancytimerid Activity the config is applied to (keeps its own recording keys); 0 drops recordings.
     * @return array Normalised config: format, version, name, settings, segments (list of arrays), rotation (array|null).
     * @throws moodle_exception invalidpreset when it is not a usable config.
     */
    public static function validate(string|array $input, int $fancytimerid = 0): array {
        if (is_string($input)) {
            if (strlen($input) > self::MAX_BYTES) {
                throw new moodle_exception('invalidpreset', 'mod_fancytimer');
            }
            $input = json_decode($input, true);
        }
        if (
            !is_array($input) || ($input['format'] ?? null) !== self::FORMAT
                || (int)($input['version'] ?? 0) < 1 || (int)$input['version'] > self::VERSION
                || empty($input['segments']) || !is_array($input['segments'])
        ) {
            throw new moodle_exception('invalidpreset', 'mod_fancytimer');
        }
        $segments = [];
        foreach (array_slice(array_values($input['segments']), 0, segments::MAX_SEGMENTS) as $segment) {
            if (!is_array($segment)) {
                throw new moodle_exception('invalidpreset', 'mod_fancytimer');
            }
            $segments[] = (array)segments::clean($segment, $fancytimerid);
        }
        $rotation = null;
        if (isset($input['rotation']) && is_array($input['rotation'])) {
            $rotation = (array)segments::clean($input['rotation'], $fancytimerid);
        }
        $settings = is_array($input['settings'] ?? null) ? $input['settings'] : [];
        $name = clean_param(trim((string)($input['name'] ?? '')), PARAM_TEXT);
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'name' => \core_text::substr($name, 0, 255),
            'settings' => [
                'looping' => empty($settings['looping']) ? 0 : 1,
                'rotationenabled' => ($rotation !== null && !empty($settings['rotationenabled'])) ? 1 : 0,
                'warningenabled' => empty($settings['warningenabled']) ? 0 : 1,
                'warningsecs' => max(1, min(30, (int)($settings['warningsecs'] ?? 5))),
                'warningsound' => sounds::clean_warning($settings['warningsound'] ?? null),
            ],
            'segments' => $segments,
            'rotation' => $rotation,
        ];
    }

    /**
     * Build the config of an existing activity.
     *
     * @param stdClass $fancytimer Instance record.
     * @param bool $keeprecordings Keep 'rec:ID' keys (saving a preset); false turns them into the default (export).
     * @return array Config as returned by validate().
     */
    public static function from_instance(stdClass $fancytimer, bool $keeprecordings = false): array {
        $strip = function (stdClass $segment) use ($keeprecordings): array {
            $sound = (string)$segment->sound;
            if (!$keeprecordings && sounds::recording_id($sound)) {
                $sound = sounds::DEFAULT_CHANGEOVER;
            }
            return [
                'label' => (string)$segment->label,
                'duration' => (int)$segment->duration,
                'bgcolour' => (string)$segment->bgcolour,
                'textcolour' => (string)$segment->textcolour,
                'sound' => $sound,
            ];
        };
        $rotation = segments::get_rotation((int)$fancytimer->id);
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'name' => (string)$fancytimer->name,
            'settings' => [
                'looping' => (int)$fancytimer->looping,
                'rotationenabled' => (int)$fancytimer->rotationenabled,
                'warningenabled' => (int)$fancytimer->warningenabled,
                'warningsecs' => (int)$fancytimer->warningsecs,
                'warningsound' => sounds::clean_warning($fancytimer->warningsound),
            ],
            'segments' => array_map($strip, segments::get_segments((int)$fancytimer->id)),
            'rotation' => $rotation ? $strip($rotation) : null,
        ];
    }

    /**
     * Encode a config as pretty JSON for download.
     *
     * @param array $config
     * @return string
     */
    public static function to_json(array $config): string {
        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
