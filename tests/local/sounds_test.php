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
 * Tests for the sound keys and option lists.
 *
 * @package    mod_fancytimer
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(sounds::class)]
final class sounds_test extends \advanced_testcase {
    /**
     * A recording key is kept only for a recording of the same activity.
     */
    public function test_clean_changeover(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $timer = $this->getDataGenerator()->create_module('fancytimer', ['course' => $course->id]);
        $other = $this->getDataGenerator()->create_module('fancytimer', ['course' => $course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_fancytimer');
        $rec = $generator->create_recording($timer);
        $otherrec = $generator->create_recording($other);

        $key = 'rec:' . $rec->id;
        $this->assertSame($key, sounds::clean_changeover($key, (int)$timer->id));
        $this->assertSame('chime', sounds::clean_changeover($key, (int)$other->id));
        $this->assertSame('chime', sounds::clean_changeover($key, 0));
        $this->assertSame('chime', sounds::clean_changeover('rec:' . $otherrec->id, (int)$timer->id));
        $this->assertSame('chime', sounds::clean_changeover('rec:' . ($otherrec->id + 1000), (int)$timer->id));
        $this->assertSame('chime', sounds::clean_changeover('rec:0', (int)$timer->id));
        $this->assertSame('chime', sounds::clean_changeover('rec:abc', (int)$timer->id));
        // A leading-zero or suffixed id is normalised to the canonical key.
        $this->assertSame($key, sounds::clean_changeover('rec:0' . $rec->id, (int)$timer->id));

        foreach (array_keys(sounds::CHANGEOVER) as $builtin) {
            $this->assertSame($builtin, sounds::clean_changeover($builtin, (int)$timer->id));
        }
        $this->assertSame('none', sounds::clean_changeover('none', 0));
        $this->assertSame('chime', sounds::clean_changeover('tick', 0));
        $this->assertSame('chime', sounds::clean_changeover('KLAXON', 0));
        $this->assertSame('chime', sounds::clean_changeover(null, 0));
        $this->assertSame('chime', sounds::clean_changeover('', 0));
    }

    /**
     * Warning cue keys.
     */
    public function test_clean_warning(): void {
        foreach (sounds::WARNING as $key) {
            $this->assertSame($key, sounds::clean_warning($key));
        }
        $this->assertSame(sounds::DEFAULT_WARNING, sounds::clean_warning('klaxon'));
        $this->assertSame(sounds::DEFAULT_WARNING, sounds::clean_warning(null));
        $this->assertSame(sounds::DEFAULT_WARNING, sounds::clean_warning('rec:1'));
    }

    /**
     * Built-in keys and recording ids.
     */
    public function test_is_builtin_and_recording_id(): void {
        $this->assertTrue(sounds::is_builtin('chime'));
        $this->assertTrue(sounds::is_builtin('klaxon'));
        $this->assertTrue(sounds::is_builtin('tick'));
        $this->assertTrue(sounds::is_builtin('pulse'));
        $this->assertFalse(sounds::is_builtin('none'));
        $this->assertFalse(sounds::is_builtin('rec:4'));
        $this->assertFalse(sounds::is_builtin('siren'));

        $this->assertSame(42, sounds::recording_id('rec:42'));
        $this->assertSame(0, sounds::recording_id('chime'));
        $this->assertSame(0, sounds::recording_id(null));
        $this->assertSame(0, sounds::recording_id('rec:'));
    }

    /**
     * The changeover menu: gentle, alarming, recordings (only when there are some), other.
     */
    public function test_changeover_options(): void {
        $options = sounds::changeover_options();
        $gentle = get_string('soundgroup_gentle', 'mod_fancytimer');
        $alarming = get_string('soundgroup_alarming', 'mod_fancytimer');
        $recordings = get_string('soundgroup_recordings', 'mod_fancytimer');
        $other = get_string('soundgroup_other', 'mod_fancytimer');

        $this->assertSame([$gentle, $alarming, $other], array_keys($options));
        $this->assertSame(['chime', 'bell', 'gong'], array_keys($options[$gentle]));
        $this->assertSame(['buzzer', 'alarm', 'klaxon'], array_keys($options[$alarming]));
        $this->assertSame(['none' => get_string('sound_none', 'mod_fancytimer')], $options[$other]);
        $this->assertSame(get_string('sound_chime', 'mod_fancytimer'), $options[$gentle]['chime']);

        $options = sounds::changeover_options([
            (object)['id' => 7, 'name' => 'Swap now'],
            (object)['id' => 9, 'name' => '  '],
        ]);
        $this->assertSame([$gentle, $alarming, $recordings, $other], array_keys($options));
        $this->assertSame([
            'rec:7' => 'Swap now',
            'rec:9' => get_string('recordingunnamed', 'mod_fancytimer', 9),
        ], $options[$recordings]);
    }

    /**
     * The warning menu has every cue, with lang strings.
     */
    public function test_warning_options(): void {
        $options = sounds::warning_options();
        $this->assertSame(sounds::WARNING, array_keys($options));
        foreach ($options as $key => $label) {
            $this->assertSame(get_string('sound_' . $key, 'mod_fancytimer'), $label);
        }
    }
}
