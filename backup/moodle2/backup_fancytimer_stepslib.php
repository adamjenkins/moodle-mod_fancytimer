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
 * Backup structure step for mod_fancytimer.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines fancytimer.xml: the instance, its segments and its teacher recordings, with id and file annotations.
 *
 * Recordings are activity content (segments play them), so they are backed up with or without user data.
 * The remote-control state, the command queue and personal presets are never backed up.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_fancytimer_activity_structure_step extends backup_activity_structure_step {
    /**
     * Define the structure.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $fancytimer = new backup_nested_element('fancytimer', ['id'], [
            'name', 'intro', 'introformat', 'looping', 'rotationenabled', 'warningenabled', 'warningsecs',
            'warningsound', 'startsound', 'timecreated', 'timemodified',
        ]);

        $segments = new backup_nested_element('segments');
        $segment = new backup_nested_element('segment', ['id'], [
            'sortorder', 'isrotation', 'label', 'duration', 'bgcolour', 'textcolour', 'sound', 'timemodified',
        ]);

        $recordings = new backup_nested_element('recordings');
        $recording = new backup_nested_element('recording', ['id'], [
            'userid', 'name', 'timecreated',
        ]);

        // Build the tree. Segments refer to recordings by "rec:<id>"; restore rewrites those keys at the end.
        $fancytimer->add_child($segments);
        $segments->add_child($segment);
        $fancytimer->add_child($recordings);
        $recordings->add_child($recording);

        // Sources.
        $fancytimer->set_source_table('fancytimer', ['id' => backup::VAR_ACTIVITYID]);
        $segment->set_source_table(
            'fancytimer_segments',
            ['fancytimerid' => backup::VAR_PARENTID],
            'isrotation ASC, sortorder ASC, id ASC'
        );
        $recording->set_source_table('fancytimer_recordings', ['fancytimerid' => backup::VAR_PARENTID], 'id ASC');

        // Id annotations.
        $recording->annotate_ids('user', 'userid');

        // File annotations.
        $fancytimer->annotate_files('mod_fancytimer', 'intro', null);
        $segment->annotate_files('mod_fancytimer', 'image', 'id');
        $recording->annotate_files('mod_fancytimer', 'recording', 'id');

        return $this->prepare_activity_structure($fancytimer);
    }
}
