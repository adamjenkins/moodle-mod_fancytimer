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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use mod_fancytimer\local\segments;
use mod_fancytimer\local\sounds;

/**
 * The segment editor (edit.php): the ordinary segments, then the rotation break.
 *
 * Custom data: fancytimer (instance record), cm, context, recordings (recordings::for_activity()),
 * maxbytes (optional, the course upload limit).
 *
 * Deleted repeats keep their index (repeat_elements() skips them and posts
 * deletesegment-hidden[i]), so code reading the data must walk the keys of 'label', not 0..n-1.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class segments_form extends \moodleform {
    /** @var string Name of the hidden repeat counter. */
    public const REPEAT_NAME = 'segmentrepeats';

    /** @var string Name of the add button. */
    public const ADD_NAME = 'addsegment';

    /** @var string Name of the per-segment delete button. */
    public const DELETE_NAME = 'deletesegment';

    /** @var int Default rotation break length, seconds. */
    public const ROTATION_DEFAULT_DURATION = 30;

    /** @var string Default rotation break tint. */
    public const ROTATION_DEFAULT_BG = '#fff3cd';

    /** @var string Default rotation break sound. */
    public const ROTATION_DEFAULT_SOUND = 'bell';

    /**
     * File manager options for a segment image.
     *
     * @param int $maxbytes Upload limit, bytes (0 = site limit).
     * @return array
     */
    public static function image_options(int $maxbytes): array {
        return [
            'subdirs' => 0,
            'maxfiles' => 1,
            'accepted_types' => ['web_image'],
            'maxbytes' => $maxbytes,
        ];
    }

    /**
     * Minute options 0-99.
     *
     * @return array
     */
    protected static function minute_options(): array {
        $options = [];
        for ($i = 0; $i <= 99; $i++) {
            $options[$i] = (string)$i;
        }
        return $options;
    }

    /**
     * Second options 0-59, shown as two digits.
     *
     * @return array
     */
    protected static function second_options(): array {
        $options = [];
        for ($i = 0; $i <= 59; $i++) {
            $options[$i] = sprintf('%02d', $i);
        }
        return $options;
    }

    /**
     * Build the duration group's two selects.
     *
     * @param string $name Group name.
     * @return \HTML_QuickForm_group
     */
    protected function create_duration_group(string $name) {
        $mform = $this->_form;
        $elements = [
            $mform->createElement(
                'select',
                'minutes',
                get_string('durationminutes', 'mod_fancytimer'),
                self::minute_options()
            ),
            $mform->createElement('static', 'minutesunit', '', get_string('durationminutesunit', 'mod_fancytimer')),
            $mform->createElement(
                'select',
                'seconds',
                get_string('durationseconds', 'mod_fancytimer'),
                self::second_options()
            ),
            $mform->createElement('static', 'secondsunit', '', get_string('durationsecondsunit', 'mod_fancytimer')),
        ];
        return $mform->createElement('group', $name, get_string('duration', 'mod_fancytimer'), $elements, ' ', true);
    }

    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        $fancytimer = $this->_customdata['fancytimer'];
        $cm = $this->_customdata['cm'];
        $recordings = $this->_customdata['recordings'] ?? [];
        $imageoptions = self::image_options((int)($this->_customdata['maxbytes'] ?? 0));
        $soundoptions = sounds::changeover_options($recordings);

        $mform->addElement('hidden', 'id', $cm->id);
        $mform->setType('id', PARAM_INT);

        // Ordinary segments.
        $repeat = [];
        $repeatoptions = [];
        $repeat[] = $mform->createElement('header', 'segmenthdr', get_string('segmentno', 'mod_fancytimer'));

        $repeat[] = $mform->createElement('hidden', 'segmentid', 0);
        $repeatoptions['segmentid']['type'] = PARAM_INT;

        $repeat[] = $mform->createElement(
            'text',
            'label',
            get_string('segmentlabel', 'mod_fancytimer'),
            ['size' => 48, 'maxlength' => 255]
        );
        $repeatoptions['label']['type'] = PARAM_TEXT;

        $repeat[] = $this->create_duration_group('duration');

        $repeat[] = $mform->createElement(
            'text',
            'bgcolour',
            get_string('bgcolour', 'mod_fancytimer'),
            ['size' => 8, 'maxlength' => 7, 'data-fancytimer-colour' => 'bg', 'autocomplete' => 'off',
            'spellcheck' => 'false']
        );
        $repeatoptions['bgcolour']['type'] = PARAM_RAW;
        $repeatoptions['bgcolour']['default'] = segments::DEFAULT_BG;

        $repeat[] = $mform->createElement(
            'text',
            'textcolour',
            get_string('textcolour', 'mod_fancytimer'),
            ['size' => 8, 'maxlength' => 7, 'data-fancytimer-colour' => 'text', 'autocomplete' => 'off',
            'spellcheck' => 'false']
        );
        $repeatoptions['textcolour']['type'] = PARAM_RAW;
        $repeatoptions['textcolour']['default'] = segments::DEFAULT_TEXT;

        $repeat[] = $mform->createElement(
            'filemanager',
            'image',
            get_string('segmentimage', 'mod_fancytimer'),
            null,
            $imageoptions
        );

        $repeat[] = $mform->createElement(
            'selectgroups',
            'sound',
            get_string('segmentsound', 'mod_fancytimer'),
            $soundoptions,
            ['data-fancytimer-sound' => 'changeover']
        );
        $repeatoptions['sound']['type'] = PARAM_RAW;
        $repeatoptions['sound']['default'] = sounds::DEFAULT_CHANGEOVER;

        $repeat[] = $mform->createElement(
            'submit',
            self::DELETE_NAME,
            get_string('deletesegment', 'mod_fancytimer'),
            [],
            false
        );

        $existing = count(segments::get_segments((int)$fancytimer->id));
        $repeats = min(segments::MAX_SEGMENTS, max(1, $existing));
        $repeats = $this->repeat_elements(
            $repeat,
            $repeats,
            $repeatoptions,
            self::REPEAT_NAME,
            self::ADD_NAME,
            1,
            get_string('addsegment', 'mod_fancytimer'),
            true,
            self::DELETE_NAME
        );

        // Per-index touches repeat_elements() cannot make: colour pair ids, the first label's required rule.
        // Help buttons, rules and header expansion are also set here, not as repeat options: repeat_elements()
        // applies options to deleted repeats too, and addRule()/setExpanded() on a missing element is fatal.
        $live = 0;
        $first = null;
        for ($i = 0; $i < $repeats; $i++) {
            if (!$mform->elementExists("label[$i]")) {
                continue;
            }
            $live++;
            $first = $first ?? $i;
            $mform->setExpanded("segmenthdr[$i]", true);
            $mform->addRule("label[$i]", get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
            $help = ['label' => 'segmentlabel', 'duration' => 'duration', 'bgcolour' => 'bgcolour',
                'image' => 'segmentimage', 'sound' => 'segmentsound'];
            foreach ($help as $field => $identifier) {
                $mform->addHelpButton("{$field}[$i]", $identifier, 'mod_fancytimer');
            }
            foreach (['bgcolour', 'textcolour'] as $field) {
                $mform->getElement("{$field}[$i]")->updateAttributes(['data-fancytimer-pair' => (string)$i]);
            }
        }
        if ($first !== null) {
            $mform->addRule("label[$first]", get_string('err_labelrequired', 'mod_fancytimer'), 'required', null, 'client');
        }
        // A timer keeps at least one segment: the last one left has no delete button.
        if ($live === 1 && $mform->elementExists(self::DELETE_NAME . "[$first]")) {
            $mform->removeElement(self::DELETE_NAME . "[$first]");
        }
        if ($live >= segments::MAX_SEGMENTS && $mform->elementExists(self::ADD_NAME)) {
            $mform->removeElement(self::ADD_NAME);
        }

        // Rotation break.
        $mform->addElement('header', 'rotationhdr', get_string('rotationhdr', 'mod_fancytimer'));
        $mform->setExpanded('rotationhdr', true);
        $mform->addElement(
            'advcheckbox',
            'rotationenabled',
            get_string('rotationenabled', 'mod_fancytimer'),
            get_string('rotationenabled_desc', 'mod_fancytimer')
        );
        $mform->addHelpButton('rotationenabled', 'rotationenabled', 'mod_fancytimer');

        $mform->addElement(
            'text',
            'rotationlabel',
            get_string('segmentlabel', 'mod_fancytimer'),
            ['size' => 48, 'maxlength' => 255]
        );
        $mform->setType('rotationlabel', PARAM_TEXT);
        $mform->addRule('rotationlabel', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement($this->create_duration_group('rotationduration'));

        $mform->addElement(
            'text',
            'rotationbgcolour',
            get_string('bgcolour', 'mod_fancytimer'),
            ['size' => 8, 'maxlength' => 7, 'data-fancytimer-colour' => 'bg', 'data-fancytimer-pair' => 'rotation',
            'autocomplete' => 'off',
            'spellcheck' => 'false']
        );
        $mform->setType('rotationbgcolour', PARAM_RAW);
        $mform->setDefault('rotationbgcolour', self::ROTATION_DEFAULT_BG);

        $mform->addElement(
            'text',
            'rotationtextcolour',
            get_string('textcolour', 'mod_fancytimer'),
            ['size' => 8, 'maxlength' => 7, 'data-fancytimer-colour' => 'text', 'data-fancytimer-pair' => 'rotation',
            'autocomplete' => 'off',
            'spellcheck' => 'false']
        );
        $mform->setType('rotationtextcolour', PARAM_RAW);
        $mform->setDefault('rotationtextcolour', segments::DEFAULT_TEXT);

        $mform->addElement(
            'filemanager',
            'rotationimage',
            get_string('segmentimage', 'mod_fancytimer'),
            null,
            $imageoptions
        );

        $mform->addElement(
            'selectgroups',
            'rotationsound',
            get_string('segmentsound', 'mod_fancytimer'),
            $soundoptions,
            ['data-fancytimer-sound' => 'changeover']
        );
        $mform->setType('rotationsound', PARAM_RAW);
        $mform->setDefault('rotationsound', self::ROTATION_DEFAULT_SOUND);

        foreach (
            ['rotationlabel', 'rotationduration', 'rotationbgcolour', 'rotationtextcolour', 'rotationimage',
                'rotationsound'] as $name
        ) {
            $mform->hideIf($name, 'rotationenabled', 'notchecked');
        }

        $this->add_action_buttons(true, get_string('savesegments', 'mod_fancytimer'));
    }

    /**
     * Seconds from a submitted duration group.
     *
     * @param mixed $group ['minutes' => m, 'seconds' => s] or null.
     * @return int|null Null when the group was not posted.
     */
    public static function duration_from(mixed $group): ?int {
        if (!is_array($group)) {
            return null;
        }
        return (int)($group['minutes'] ?? 0) * 60 + (int)($group['seconds'] ?? 0);
    }

    /**
     * Validation: every kept segment has a label, a length in range and valid colours.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $deleted = $data[self::DELETE_NAME . '-hidden'] ?? [];
        $maxtext = format_time(segments::MAX_DURATION);
        $kept = 0;
        foreach (array_keys($data['label'] ?? []) as $i) {
            if (!empty($deleted[$i])) {
                continue;
            }
            $kept++;
            if (trim((string)$data['label'][$i]) === '') {
                $errors["label[$i]"] = get_string('err_labelrequired', 'mod_fancytimer');
            }
            $duration = self::duration_from($data['duration'][$i] ?? null) ?? 0;
            if ($duration < segments::MIN_DURATION || $duration > segments::MAX_DURATION) {
                $errors["duration[$i]"] = get_string('err_duration', 'mod_fancytimer', $maxtext);
            }
            foreach (['bgcolour', 'textcolour'] as $field) {
                if (!segments::is_colour((string)($data[$field][$i] ?? ''))) {
                    $errors["{$field}[$i]"] = get_string('err_colour', 'mod_fancytimer');
                }
            }
        }
        if ($kept === 0) {
            $errors[self::ADD_NAME] = get_string('err_nosegments', 'mod_fancytimer');
        } else if ($kept > segments::MAX_SEGMENTS) {
            $errors[self::ADD_NAME] = get_string('err_toomanysegments', 'mod_fancytimer', segments::MAX_SEGMENTS);
        }

        // The rotation fields are disabled, so not posted, while the rotation break is off.
        if (!empty($data['rotationenabled'])) {
            if (array_key_exists('rotationlabel', $data) && trim((string)$data['rotationlabel']) === '') {
                $errors['rotationlabel'] = get_string('err_labelrequired', 'mod_fancytimer');
            }
            $duration = self::duration_from($data['rotationduration'] ?? null);
            if ($duration !== null && ($duration < segments::MIN_DURATION || $duration > segments::MAX_DURATION)) {
                $errors['rotationduration'] = get_string('err_duration', 'mod_fancytimer', $maxtext);
            }
            foreach (['rotationbgcolour', 'rotationtextcolour'] as $field) {
                if (array_key_exists($field, $data) && !segments::is_colour((string)$data[$field])) {
                    $errors[$field] = get_string('err_colour', 'mod_fancytimer');
                }
            }
        }
        return $errors;
    }
}
