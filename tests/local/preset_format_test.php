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
 * Tests for the preset/template configuration format.
 *
 * @package    mod_fancytimer
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(preset_format::class)]
final class preset_format_test extends \advanced_testcase {
    /**
     * A minimal valid config.
     *
     * @return array
     */
    private function config(): array {
        return [
            'format' => preset_format::FORMAT,
            'version' => preset_format::VERSION,
            'name' => 'Talk',
            'settings' => ['looping' => 1, 'rotationenabled' => 1, 'warningenabled' => 0,
                'warningsecs' => 5, 'warningsound' => 'tick'],
            'segments' => [['label' => 'A', 'duration' => 30, 'bgcolour' => '#cfe2ff',
                'textcolour' => '#212529', 'sound' => 'chime']],
            'rotation' => ['label' => 'Move', 'duration' => 10],
        ];
    }

    /**
     * Expect the invalidpreset error.
     */
    private function expect_invalid(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('invalidpreset', 'mod_fancytimer'));
    }

    /**
     * Data for the rejected inputs.
     *
     * @return array
     */
    public static function invalid_provider(): array {
        return [
            'wrong format' => ['format', 'something-else'],
            'missing format' => ['format', null],
            'version 2' => ['version', 2],
            'version 0' => ['version', 0],
            'empty segments' => ['segments', []],
            'segments not a list' => ['segments', 'abc'],
            'segment not an array' => ['segments', ['oops']],
        ];
    }

    /**
     * Rejected configs, as arrays and as JSON.
     *
     * @param string $key
     * @param mixed $value
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_provider')]
    public function test_validate_rejects(string $key, mixed $value): void {
        $config = $this->config();
        if ($value === null) {
            unset($config[$key]);
        } else {
            $config[$key] = $value;
        }
        try {
            preset_format::validate($config);
            $this->fail('Array input should have been rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidpreset', $e->errorcode);
        }
        $this->expect_invalid();
        preset_format::validate(json_encode($config));
    }

    /**
     * Text that is not JSON is rejected.
     */
    public function test_validate_rejects_non_json(): void {
        $this->expect_invalid();
        preset_format::validate('{"format": "mod_fancytimer-preset", ');
    }

    /**
     * JSON larger than MAX_BYTES is rejected before decoding, even when otherwise valid.
     */
    public function test_validate_rejects_oversized_json(): void {
        $config = $this->config();
        $config['padding'] = str_repeat('x', preset_format::MAX_BYTES);
        $json = json_encode($config);
        $this->assertGreaterThan(preset_format::MAX_BYTES, strlen($json));
        // The same config as an array is fine: only the size is wrong.
        $this->assertCount(1, preset_format::validate($config)['segments']);
        $this->expect_invalid();
        preset_format::validate($json);
    }

    /**
     * Segments beyond MAX_SEGMENTS are dropped; settings are normalised.
     */
    public function test_validate_caps_and_normalises(): void {
        $config = $this->config();
        $config['segments'] = array_fill(0, segments::MAX_SEGMENTS + 8, ['label' => 'S', 'duration' => 0]);
        $config['settings'] = ['looping' => 'yes', 'warningenabled' => 1, 'warningsecs' => 99,
            'warningsound' => 'klaxon', 'rotationenabled' => 1];
        $config['name'] = '  <i>Mine</i> ';
        $config['extra'] = 'dropped';

        $out = preset_format::validate($config);

        $this->assertCount(segments::MAX_SEGMENTS, $out['segments']);
        $this->assertSame(segments::MIN_DURATION, $out['segments'][0]['duration']);
        $this->assertSame(['format', 'version', 'name', 'settings', 'segments', 'rotation'], array_keys($out));
        $this->assertSame('Mine', $out['name']);
        $this->assertSame(1, $out['settings']['looping']);
        $this->assertSame(1, $out['settings']['warningenabled']);
        $this->assertSame(30, $out['settings']['warningsecs']);
        $this->assertSame(sounds::DEFAULT_WARNING, $out['settings']['warningsound']);
        $this->assertSame(1, $out['settings']['rotationenabled']);
        $this->assertSame('Move', $out['rotation']['label']);

        // Rotation enabled without a rotation segment is switched off.
        $config['rotation'] = null;
        $this->assertSame(0, preset_format::validate($config)['settings']['rotationenabled']);
        $config['rotation'] = 'not an array';
        $this->assertNull(preset_format::validate($config)['rotation']);

        // Missing settings get defaults.
        unset($config['settings']);
        $out = preset_format::validate($config);
        $this->assertSame(['looping' => 0, 'rotationenabled' => 0, 'warningenabled' => 0, 'warningsecs' => 5,
            'warningsound' => sounds::DEFAULT_WARNING], $out['settings']);
    }

    /**
     * Recording keys survive validation only for their own activity.
     */
    public function test_validate_recording_keys(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $timer = $this->getDataGenerator()->create_module('fancytimer', ['course' => $course->id]);
        $other = $this->getDataGenerator()->create_module('fancytimer', ['course' => $course->id]);
        $rec = $this->getDataGenerator()->get_plugin_generator('mod_fancytimer')->create_recording($timer);

        $config = $this->config();
        $config['segments'][0]['sound'] = 'rec:' . $rec->id;

        $this->assertSame('rec:' . $rec->id, preset_format::validate($config, (int)$timer->id)['segments'][0]['sound']);
        $this->assertSame('chime', preset_format::validate($config, (int)$other->id)['segments'][0]['sound']);
        $this->assertSame('chime', preset_format::validate($config)['segments'][0]['sound']);
    }

    /**
     * from_instance() strips recording keys unless asked to keep them, and round-trips through JSON.
     */
    public function test_from_instance(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $timer = $this->getDataGenerator()->create_module('fancytimer', ['course' => $course->id,
            'name' => 'Speaking', 'warningenabled' => 1, 'warningsecs' => 7, 'warningsound' => 'pulse']);
        $rec = $this->getDataGenerator()->get_plugin_generator('mod_fancytimer')->create_recording($timer);
        $first = segments::get_segments((int)$timer->id)[0];
        $DB->set_field('fancytimer_segments', 'sound', 'rec:' . $rec->id, ['id' => $first->id]);
        $timer = $DB->get_record('fancytimer', ['id' => $timer->id], '*', MUST_EXIST);

        $export = preset_format::from_instance($timer);
        $this->assertSame('chime', $export['segments'][0]['sound']);
        $this->assertSame('Speaking', $export['name']);
        $this->assertCount(3, $export['segments']);
        $this->assertNotNull($export['rotation']);
        $this->assertSame(['looping' => 0, 'rotationenabled' => 1, 'warningenabled' => 1, 'warningsecs' => 7,
            'warningsound' => 'pulse'], $export['settings']);

        $kept = preset_format::from_instance($timer, true);
        $this->assertSame('rec:' . $rec->id, $kept['segments'][0]['sound']);
        $this->assertSame('chime', $kept['segments'][1]['sound']);

        // Export → JSON → validate gives the same config back.
        $json = preset_format::to_json($export);
        $this->assertSame($export, preset_format::validate($json));
    }

    /**
     * to_json() keeps unicode and slashes readable.
     */
    public function test_to_json(): void {
        $config = preset_format::validate($this->config() + []);
        $config['name'] = '3 × 2 min / 話す';
        $json = preset_format::to_json($config);
        $this->assertStringContainsString('3 × 2 min / 話す', $json);
        $this->assertSame($config, json_decode($json, true));
    }
}
