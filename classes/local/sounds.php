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

/**
 * The built-in sound library and the sound keys stored on segments.
 *
 * Built-in sounds are synthesised in the browser with the Web Audio API
 * (amd/src/audio.js), so the plugin ships no audio files. A segment's sound is
 * one of the keys below, 'rec:<recordingid>' for a teacher recording of the
 * same activity, or 'none'.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sounds {
    /** @var string Sound key meaning silence. */
    public const NONE = 'none';

    /** @var string Default changeover sound. */
    public const DEFAULT_CHANGEOVER = 'chime';

    /** @var string Default warning cue. */
    public const DEFAULT_WARNING = 'tick';

    /** @var string Prefix of a recording sound key. */
    public const RECORDING_PREFIX = 'rec:';

    /** @var array Changeover sounds, gentle first. Key => group. */
    public const CHANGEOVER = [
        'chime' => 'gentle',
        'bell' => 'gentle',
        'gong' => 'gentle',
        'buzzer' => 'alarming',
        'alarm' => 'alarming',
        'klaxon' => 'alarming',
    ];

    /** @var array Warning cues. */
    public const WARNING = ['tick', 'pulse', 'chime'];

    /**
     * Is this a built-in changeover sound key?
     *
     * @param string $key
     * @return bool
     */
    public static function is_builtin(string $key): bool {
        return array_key_exists($key, self::CHANGEOVER) || in_array($key, self::WARNING, true);
    }

    /**
     * Options for a changeover sound select, grouped by gentle/alarming, with the activity's recordings.
     *
     * @param array $recordings Records from fancytimer_recordings (id, name).
     * @return array Selectgroups-style array: group label => [key => label].
     */
    public static function changeover_options(array $recordings = []): array {
        $groups = [];
        foreach (self::CHANGEOVER as $key => $group) {
            $groups[get_string('soundgroup_' . $group, 'mod_fancytimer')][$key] = get_string('sound_' . $key, 'mod_fancytimer');
        }
        if ($recordings) {
            $label = get_string('soundgroup_recordings', 'mod_fancytimer');
            foreach ($recordings as $rec) {
                $name = trim((string)$rec->name) !== '' ? $rec->name : get_string('recordingunnamed', 'mod_fancytimer', $rec->id);
                $groups[$label][self::RECORDING_PREFIX . $rec->id] = $name;
            }
        }
        $groups[get_string('soundgroup_other', 'mod_fancytimer')][self::NONE] = get_string('sound_none', 'mod_fancytimer');
        return $groups;
    }

    /**
     * Options for the warning cue select.
     *
     * @return array key => label.
     */
    public static function warning_options(): array {
        $options = [];
        foreach (self::WARNING as $key) {
            $options[$key] = get_string('sound_' . $key, 'mod_fancytimer');
        }
        return $options;
    }

    /**
     * Clean a stored or submitted changeover sound key.
     *
     * A recording key survives only if the recording belongs to this activity.
     *
     * @param string|null $key
     * @param int $fancytimerid 0 when there is no activity (presets, templates): recordings are then dropped.
     * @return string
     */
    public static function clean_changeover(?string $key, int $fancytimerid): string {
        global $DB;
        $key = (string)$key;
        if ($key === self::NONE || array_key_exists($key, self::CHANGEOVER)) {
            return $key;
        }
        if ($fancytimerid && str_starts_with($key, self::RECORDING_PREFIX)) {
            $id = (int)substr($key, strlen(self::RECORDING_PREFIX));
            if ($id > 0 && $DB->record_exists('fancytimer_recordings', ['id' => $id, 'fancytimerid' => $fancytimerid])) {
                return self::RECORDING_PREFIX . $id;
            }
        }
        return self::DEFAULT_CHANGEOVER;
    }

    /**
     * Clean a warning cue key.
     *
     * @param string|null $key
     * @return string
     */
    public static function clean_warning(?string $key): string {
        return in_array((string)$key, self::WARNING, true) ? (string)$key : self::DEFAULT_WARNING;
    }

    /**
     * The recording id a sound key points at, or 0.
     *
     * @param string|null $key
     * @return int
     */
    public static function recording_id(?string $key): int {
        $key = (string)$key;
        return str_starts_with($key, self::RECORDING_PREFIX) ? (int)substr($key, strlen(self::RECORDING_PREFIX)) : 0;
    }
}
