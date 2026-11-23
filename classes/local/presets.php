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
 * A user's saved timer presets (personal, usable in any course).
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class presets {
    /** @var int Most presets one user may keep. */
    public const MAX_PER_USER = 100;

    /**
     * A user's presets, newest first.
     *
     * @param int $userid
     * @return stdClass[] id => record (id, name, timecreated, timemodified; no config).
     */
    public static function for_user(int $userid): array {
        global $DB;
        return $DB->get_records(
            'fancytimer_presets',
            ['userid' => $userid],
            'timemodified DESC, id DESC',
            'id, name, timecreated, timemodified'
        );
    }

    /**
     * One of the user's presets, with its validated config.
     *
     * @param int $id
     * @param int $userid Owner; another user's preset is not found.
     * @param int $fancytimerid Activity it will be applied to (for recording keys), 0 if none.
     * @return array [stdClass record, array config]
     * @throws moodle_exception presetnotfound / invalidpreset
     */
    public static function get(int $id, int $userid, int $fancytimerid = 0): array {
        global $DB;
        $record = $DB->get_record('fancytimer_presets', ['id' => $id, 'userid' => $userid]);
        if (!$record) {
            throw new moodle_exception('presetnotfound', 'mod_fancytimer');
        }
        return [$record, preset_format::validate((string)$record->config, $fancytimerid)];
    }

    /**
     * Save a config as a new preset.
     *
     * @param int $userid
     * @param string $name
     * @param array $config Valid config (preset_format::validate or ::from_instance).
     * @return int New preset id.
     * @throws moodle_exception toomanypresets
     */
    public static function create(int $userid, string $name, array $config): int {
        global $DB;
        if ($DB->count_records('fancytimer_presets', ['userid' => $userid]) >= self::MAX_PER_USER) {
            throw new moodle_exception('toomanypresets', 'mod_fancytimer', '', self::MAX_PER_USER);
        }
        $name = \core_text::substr(clean_param(trim($name), PARAM_TEXT), 0, 255);
        if ($name === '') {
            $name = get_string('presetunnamed', 'mod_fancytimer');
        }
        $config['name'] = $name;
        $now = time();
        return (int)$DB->insert_record('fancytimer_presets', (object)[
            'userid' => $userid,
            'name' => $name,
            'config' => preset_format::to_json($config),
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Delete one of the user's presets.
     *
     * @param int $id
     * @param int $userid
     * @return bool Whether a row was deleted.
     */
    public static function delete(int $id, int $userid): bool {
        global $DB;
        if (!$DB->record_exists('fancytimer_presets', ['id' => $id, 'userid' => $userid])) {
            return false;
        }
        $DB->delete_records('fancytimer_presets', ['id' => $id, 'userid' => $userid]);
        return true;
    }
}
