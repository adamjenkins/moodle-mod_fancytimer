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

namespace mod_fancytimer\form;

use mod_fancytimer\local\preset_format;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * "Import a preset" form on presets.php: one JSON preset file.
 *
 * The file content is validated by the page with preset_format::validate(), the trust boundary.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preset_import_form extends \moodleform {
    /**
     * Define the form.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('filepicker', 'presetfile', get_string('presetfile', 'mod_fancytimer'), null, [
            'accepted_types' => ['.json'],
            'maxbytes' => preset_format::MAX_BYTES,
        ]);
        $mform->addRule('presetfile', null, 'required', null, 'client');

        $mform->addElement('submit', 'importpreset', get_string('importpresetbutton', 'mod_fancytimer'));
    }
}
