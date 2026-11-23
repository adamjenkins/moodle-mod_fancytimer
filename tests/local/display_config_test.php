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
 * Tests for the timer display configuration.
 *
 * @package    mod_fancytimer
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(display_config::class)]
final class display_config_test extends \advanced_testcase {
    /**
     * A threeperson timer with its cm and context.
     *
     * @param array $fields Extra instance fields.
     * @return array [stdClass instance, \cm_info, \context_module]
     */
    private function make_timer(array $fields = []): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $timer = $this->getDataGenerator()->create_module('fancytimer', ['course' => $course->id] + $fields);
        $cm = get_fast_modinfo($course)->get_cm($timer->cmid);
        $instance = $DB->get_record('fancytimer', ['id' => $timer->id], '*', MUST_EXIST);
        $instance->cmid = $timer->cmid;
        return [$instance, $cm, \context_module::instance($timer->cmid)];
    }

    /**
     * The config has the documented shape; control and mirror differ in mode and polling.
     */
    public function test_build_shape_and_modes(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$timer, $cm, $context] = $this->make_timer(['looping' => 1, 'warningenabled' => 1, 'warningsecs' => 9,
            'warningsound' => 'pulse']);

        $config = display_config::build($timer, $cm, $context, true);

        $this->assertSame(
            ['cmid', 'contextid', 'mode', 'looping', 'warning', 'segments', 'cyclelength', 'pollms'],
            array_keys($config)
        );
        $this->assertSame((int)$cm->id, $config['cmid']);
        $this->assertSame((int)$context->id, $config['contextid']);
        $this->assertSame('control', $config['mode']);
        $this->assertSame(display_config::POLL_CONTROL_MS, $config['pollms']);
        $this->assertTrue($config['looping']);
        $this->assertSame(['enabled' => true, 'secs' => 9, 'sound' => 'pulse'], $config['warning']);
        $this->assertSame(390, $config['cyclelength']);

        $first = $config['segments'][0];
        $this->assertSame(
            ['id', 'label', 'duration', 'bgcolour', 'textcolour', 'isrotation', 'imageurl', 'sound'],
            array_keys($first)
        );
        $this->assertIsInt($first['id']);
        $this->assertSame(get_string('templatelabel_person', 'mod_fancytimer', 1), $first['label']);
        $this->assertSame(120, $first['duration']);
        $this->assertSame(segments::PALETTE['blue'], $first['bgcolour']);
        $this->assertSame(segments::DEFAULT_TEXT, $first['textcolour']);
        $this->assertFalse($first['isrotation']);
        $this->assertNull($first['imageurl']);
        $this->assertSame(['type' => 'builtin', 'key' => 'chime'], $first['sound']);

        $mirror = display_config::build($timer, $cm, $context, false);
        $this->assertSame('mirror', $mirror['mode']);
        $this->assertSame(display_config::POLL_MIRROR_MS, $mirror['pollms']);
        $this->assertSame($config['segments'], $mirror['segments']);

        // The whole thing encodes as JSON for the data attribute.
        $this->assertNotFalse(json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
    }

    /**
     * The rotation segment is the last entry only when rotation is enabled.
     */
    public function test_rotation_only_when_enabled(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$timer, $cm, $context] = $this->make_timer();
        $this->assertEquals(1, $timer->rotationenabled);

        $config = display_config::build($timer, $cm, $context, true);
        $this->assertCount(4, $config['segments']);
        $last = end($config['segments']);
        $this->assertTrue($last['isrotation']);
        $this->assertSame(get_string('templatelabel_changegroups', 'mod_fancytimer'), $last['label']);
        $this->assertSame(['type' => 'builtin', 'key' => 'bell'], $last['sound']);

        $timer->rotationenabled = 0;
        $config = display_config::build($timer, $cm, $context, true);
        $this->assertCount(3, $config['segments']);
        $this->assertSame([false, false, false], array_column($config['segments'], 'isrotation'));
        $this->assertSame(360, $config['cyclelength']);
    }

    /**
     * Labels are plain text (not HTML-escaped: timer.js sets textContent); images and colours are mapped.
     */
    public function test_labels_images_colours(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$timer, $cm, $context] = $this->make_timer();
        $segs = segments::get_segments((int)$timer->id);
        $DB->set_field('fancytimer_segments', 'label', 'Tom & Jerry', ['id' => $segs[0]->id]);
        $DB->set_field('fancytimer_segments', 'bgcolour', 'nocolor', ['id' => $segs[1]->id]);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_fancytimer', 'filearea' => 'image',
            'itemid' => $segs[1]->id, 'filepath' => '/', 'filename' => 'speaker.png',
        ], 'png');

        $config = display_config::build($timer, $cm, $context, false);

        $this->assertSame('Tom & Jerry', $config['segments'][0]['label']);
        $this->assertSame(segments::DEFAULT_BG, $config['segments'][1]['bgcolour']);
        $this->assertNull($config['segments'][0]['imageurl']);
        $this->assertStringContainsString(
            '/' . $context->id . '/mod_fancytimer/image/' . $segs[1]->id . '/speaker.png',
            $config['segments'][1]['imageurl']
        );

        $urls = display_config::image_urls($context);
        $this->assertSame([(int)$segs[1]->id], array_keys($urls));
    }

    /**
     * Sound descriptors: recording URL when the file exists, chime when it is gone, none, builtins.
     */
    public function test_sound_descriptors(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$timer, $cm, $context] = $this->make_timer();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_fancytimer');
        $rec = $generator->create_recording($timer);
        $lost = $generator->create_recording($timer);
        get_file_storage()->delete_area_files($context->id, 'mod_fancytimer', 'recording', $lost->id);

        $segs = segments::get_segments((int)$timer->id);
        $DB->set_field('fancytimer_segments', 'sound', 'rec:' . $rec->id, ['id' => $segs[0]->id]);
        $DB->set_field('fancytimer_segments', 'sound', 'rec:' . $lost->id, ['id' => $segs[1]->id]);
        $DB->set_field('fancytimer_segments', 'sound', 'none', ['id' => $segs[2]->id]);

        $config = display_config::build($timer, $cm, $context, true);
        $sound = $config['segments'][0]['sound'];
        $this->assertSame('recording', $sound['type']);
        $this->assertSame(recordings::url($context, (int)$rec->id)->out(false), $sound['url']);
        $this->assertStringContainsString('/mod_fancytimer/recording/' . $rec->id . '/', $sound['url']);
        $this->assertSame(['type' => 'builtin', 'key' => 'chime'], $config['segments'][1]['sound']);
        $this->assertSame(['type' => 'none'], $config['segments'][2]['sound']);

        $this->assertSame(['type' => 'builtin', 'key' => 'klaxon'], display_config::sound('klaxon', $context));
        $this->assertSame(['type' => 'builtin', 'key' => 'tick'], display_config::sound('tick', $context));
        $this->assertSame(['type' => 'builtin', 'key' => 'chime'], display_config::sound('siren', $context));
        $this->assertSame(['type' => 'builtin', 'key' => 'chime'], display_config::sound('', $context));
    }
}
