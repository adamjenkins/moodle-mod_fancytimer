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
 * Activity settings form for mod_fancytimer.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

use mod_fancytimer\local\presets;
use mod_fancytimer\local\sounds;
use mod_fancytimer\local\templates;

/**
 * Activity settings form: name, description, template (new activities), looping and warning cue.
 *
 * Segments are edited on their own page (edit.php).
 */
class mod_fancytimer_mod_form extends moodleform_mod {
    /**
     * Form definition.
     */
    public function definition() {
        global $USER, $PAGE;
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $this->standard_intro_elements();

        $mform->addElement('header', 'timerhdr', get_string('timersettings', 'mod_fancytimer'));
        if (empty($this->_instance)) {
            $options = [get_string('templates', 'mod_fancytimer') => []];
            foreach (templates::menu() as $key => $label) {
                $options[get_string('templates', 'mod_fancytimer')]['tpl:' . $key] = $label;
            }
            $mine = presets::for_user((int)$USER->id);
            if ($mine) {
                $group = get_string('mypresets', 'mod_fancytimer');
                foreach ($mine as $preset) {
                    $options[$group]['preset:' . $preset->id] = format_string($preset->name);
                }
            }
            $mform->addElement('selectgroups', 'template', get_string('startfrom', 'mod_fancytimer'), $options);
            $mform->setDefault('template', 'tpl:' . templates::DEFAULT);
            $mform->addHelpButton('template', 'startfrom', 'mod_fancytimer');
        } else {
            $url = new moodle_url('/mod/fancytimer/edit.php', ['id' => $this->_cm->id]);
            $mform->addElement(
                'static',
                'segmentslink',
                get_string('segments', 'mod_fancytimer'),
                html_writer::link($url, get_string('editsegments', 'mod_fancytimer'))
            );
        }

        $mform->addElement(
            'advcheckbox',
            'looping',
            get_string('looping', 'mod_fancytimer'),
            get_string('looping_desc', 'mod_fancytimer')
        );
        $mform->addHelpButton('looping', 'looping', 'mod_fancytimer');

        $mform->addElement(
            'advcheckbox',
            'warningenabled',
            get_string('warningenabled', 'mod_fancytimer'),
            get_string('warningenabled_desc', 'mod_fancytimer')
        );
        $mform->setDefault('warningenabled', 0);
        $mform->addHelpButton('warningenabled', 'warningenabled', 'mod_fancytimer');

        $secs = [];
        foreach ([1, 2, 3, 5, 10, 15, 20, 30] as $s) {
            $secs[$s] = get_string('numseconds', 'moodle', $s);
        }
        $mform->addElement('select', 'warningsecs', get_string('warningsecs', 'mod_fancytimer'), $secs);
        $mform->setDefault('warningsecs', 5);
        $mform->hideIf('warningsecs', 'warningenabled', 'notchecked');

        $mform->addElement(
            'select',
            'warningsound',
            get_string('warningsound', 'mod_fancytimer'),
            sounds::warning_options(),
            ['data-fancytimer-sound' => 'warning']
        );
        $mform->setDefault('warningsound', sounds::DEFAULT_WARNING);
        $mform->hideIf('warningsound', 'warningenabled', 'notchecked');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();

        // Preview buttons for the warning cue select.
        $PAGE->requires->js_call_amd('mod_fancytimer/sound_preview', 'init', []);
    }
}
