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
 * Restore structure step for mod_fancytimer.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_fancytimer\local\segments;
use mod_fancytimer\local\sounds;

/**
 * Restores fancytimer.xml: the instance, its segments and its teacher recordings.
 *
 * Segment sound keys "rec:<old recording id>" are kept as read and rewritten to the new
 * recording ids in after_execute(), once every recording has been mapped.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_fancytimer_activity_structure_step extends restore_activity_structure_step {
    /**
     * The paths to process.
     *
     * @return restore_path_element[]
     */
    protected function define_structure() {
        $paths = [];
        $paths[] = new restore_path_element('fancytimer', '/activity/fancytimer');
        $paths[] = new restore_path_element('segment', '/activity/fancytimer/segments/segment');
        $paths[] = new restore_path_element('recording', '/activity/fancytimer/recordings/recording');
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restore the instance, cleaning the settings as the activity form does (a backup file is input).
     *
     * @param array $data Element data.
     * @return void
     */
    protected function process_fancytimer($data) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/fancytimer/lib.php');
        $data = (object)$data;
        $record = fancytimer_settings_from_form($data);
        $record->course = $this->get_courseid();
        $record->name = clean_param(\core_text::substr((string)($data->name ?? ''), 0, 255), PARAM_TEXT);
        $record->intro = (string)($data->intro ?? '');
        $record->introformat = (int)($data->introformat ?? FORMAT_HTML);
        $record->rotationenabled = empty($data->rotationenabled) ? 0 : 1;
        // A recording key keeps its old id until after_execute(), as segment sounds do.
        $oldrecordingid = sounds::recording_id($data->startsound ?? null);
        $record->startsound = $oldrecordingid > 0 ? sounds::RECORDING_PREFIX . $oldrecordingid
            : sounds::clean_start($data->startsound ?? null, 0);
        $record->timecreated = (int)($data->timecreated ?? time());
        $record->timemodified = time();
        $newid = $DB->insert_record('fancytimer', $record);
        $this->apply_activity_instance($newid);
    }

    /**
     * Restore one segment; a recording sound key keeps its old id until after_execute().
     *
     * @param array $data Element data.
     * @return void
     */
    protected function process_segment($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $rawsound = (string)($data->sound ?? '');
        $row = segments::clean($data, 0);
        $oldrecordingid = sounds::recording_id($rawsound);
        if ($oldrecordingid > 0) {
            $row->sound = sounds::RECORDING_PREFIX . $oldrecordingid;
        }
        $row->fancytimerid = $this->get_new_parentid('fancytimer');
        $row->isrotation = empty($data->isrotation) ? 0 : 1;
        $row->sortorder = max(0, (int)($data->sortorder ?? 0));
        $row->timemodified = (int)($data->timemodified ?? time());
        $newid = $DB->insert_record('fancytimer_segments', $row);
        $this->set_mapping('segment', $oldid, $newid, true);
    }

    /**
     * Restore one teacher recording. Without user data the recording belongs to the restoring user.
     *
     * @param array $data Element data.
     * @return void
     */
    protected function process_recording($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $userid = $this->get_mappingid('user', $data->userid ?? 0);
        $record = (object)[
            'fancytimerid' => $this->get_new_parentid('fancytimer'),
            'userid' => $userid ? (int)$userid : (int)$this->task->get_userid(),
            'name' => clean_param(\core_text::substr((string)($data->name ?? ''), 0, 255), PARAM_TEXT),
            'timecreated' => (int)($data->timecreated ?? time()),
        ];
        $newid = $DB->insert_record('fancytimer_recordings', $record);
        $this->set_mapping('recording', $oldid, $newid, true);
    }

    /**
     * Restore the files, then point recording sound keys at the restored recordings.
     *
     * @return void
     */
    protected function after_execute() {
        global $DB;
        $this->add_related_files('mod_fancytimer', 'intro', null);
        $this->add_related_files('mod_fancytimer', 'image', 'segment');
        $this->add_related_files('mod_fancytimer', 'recording', 'recording');

        $fancytimerid = (int)$this->task->get_activityid();
        $rows = $DB->get_records('fancytimer_segments', ['fancytimerid' => $fancytimerid], 'id ASC', 'id, sound');
        foreach ($rows as $row) {
            $oldrecordingid = sounds::recording_id($row->sound);
            if (!$oldrecordingid) {
                continue;
            }
            $newrecordingid = (int)$this->get_mappingid('recording', $oldrecordingid);
            $sound = $newrecordingid ? sounds::RECORDING_PREFIX . $newrecordingid : sounds::DEFAULT_CHANGEOVER;
            if ($sound !== $row->sound) {
                $DB->set_field('fancytimer_segments', 'sound', $sound, ['id' => $row->id]);
            }
        }

        $startsound = (string)$DB->get_field('fancytimer', 'startsound', ['id' => $fancytimerid]);
        $oldrecordingid = sounds::recording_id($startsound);
        if ($oldrecordingid) {
            $newrecordingid = (int)$this->get_mappingid('recording', $oldrecordingid);
            $startsound = $newrecordingid ? sounds::RECORDING_PREFIX . $newrecordingid : sounds::NONE;
            $DB->set_field('fancytimer', 'startsound', $startsound, ['id' => $fancytimerid]);
        }
    }
}
