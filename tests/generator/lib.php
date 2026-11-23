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

/**
 * Data generator for mod_fancytimer.
 *
 * @package    mod_fancytimer
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Fancy Timer data generator.
 *
 * create_instance() goes through add_moduleinfo(), so fancytimer_add_instance() gets
 * $data->coursemodule and builds the segments from the chosen template or preset.
 *
 * @package    mod_fancytimer
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_fancytimer_generator extends testing_module_generator {
    /**
     * Create a timer.
     *
     * @param array|stdClass|null $record Any instance/form field; template is 'tpl:<key>' or 'preset:<id>'.
     * @param array|null $options
     * @return stdClass Instance record with cmid.
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (array)$record + [
            'template' => 'tpl:threeperson',
            'looping' => 0,
            'warningenabled' => 0,
            'warningsecs' => 5,
            'warningsound' => 'tick',
        ];
        return parent::create_instance($record, $options);
    }

    /**
     * Store a teacher recording (row and file) for a timer.
     *
     * @param stdClass $fancytimer Instance record with cmid (as returned by create_instance()).
     * @param array $record Optional userid, name, mimetype, bytes.
     * @return stdClass The fancytimer_recordings record.
     */
    public function create_recording(stdClass $fancytimer, array $record = []): stdClass {
        global $USER;
        $record += [
            'userid' => (int)$USER->id,
            'name' => 'Next person, please',
            'mimetype' => 'audio/webm',
            'bytes' => 'fake-audio-bytes',
        ];
        return \mod_fancytimer\local\recordings::save(
            \context_module::instance($fancytimer->cmid),
            (int)$fancytimer->id,
            (int)$record['userid'],
            (string)$record['name'],
            (string)$record['mimetype'],
            (string)$record['bytes']
        );
    }

    /**
     * Save a user's preset from a template config.
     *
     * @param int $userid
     * @param string $name
     * @param string $templatekey
     * @return int Preset id.
     */
    public function create_preset(int $userid, string $name = 'My preset', string $templatekey = 'pairs'): int {
        return \mod_fancytimer\local\presets::create(
            $userid,
            $name,
            \mod_fancytimer\local\templates::get($templatekey)
        );
    }
}
