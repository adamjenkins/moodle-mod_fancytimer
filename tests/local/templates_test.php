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
 * Tests for the bundled templates.
 *
 * @package    mod_fancytimer
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(templates::class)]
final class templates_test extends \advanced_testcase {
    /**
     * Expected shape of each template: segment durations, rotation duration (null = none), looping.
     *
     * @return array
     */
    public static function template_provider(): array {
        return [
            'threeperson' => ['threeperson', [120, 120, 120], 30, 1],
            'pairs' => ['pairs', [180, 180], 30, 1],
            'thinkpairshare' => ['thinkpairshare', [60, 120, 120], null, 0],
            'single' => ['single', [300], null, 0],
        ];
    }

    /**
     * Every template is a valid config with the expected segments and rotation.
     *
     * @param string $key
     * @param int[] $durations
     * @param int|null $rotation
     * @param int $looping
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('template_provider')]
    public function test_template(string $key, array $durations, ?int $rotation, int $looping): void {
        $config = templates::get($key);
        $this->assertIsArray($config);

        // Already normalised: validating again changes nothing, also through JSON.
        $this->assertSame($config, preset_format::validate($config));
        $this->assertSame($config, preset_format::validate(preset_format::to_json($config)));

        $this->assertSame(get_string('template_' . $key, 'mod_fancytimer'), $config['name']);
        $this->assertSame($durations, array_column($config['segments'], 'duration'));
        $this->assertSame($looping, $config['settings']['looping']);
        $this->assertSame(0, $config['settings']['warningenabled']);
        if ($rotation === null) {
            $this->assertNull($config['rotation']);
            $this->assertSame(0, $config['settings']['rotationenabled']);
        } else {
            $this->assertIsArray($config['rotation']);
            $this->assertSame($rotation, $config['rotation']['duration']);
            $this->assertSame(1, $config['settings']['rotationenabled']);
            $this->assertNotSame('', $config['rotation']['label']);
        }
        foreach ($config['segments'] as $segment) {
            $this->assertNotSame('', $segment['label']);
            $this->assertStringNotContainsString('[[', $segment['label']);
            $this->assertGreaterThanOrEqual(
                segments::MIN_CONTRAST,
                segments::contrast_ratio($segment['bgcolour'], $segment['textcolour'])
            );
            $this->assertTrue(sounds::is_builtin($segment['sound']));
        }
    }

    /**
     * Labels are lang strings of the current language.
     */
    public function test_threeperson_labels(): void {
        $config = templates::get('threeperson');
        $this->assertSame(get_string('templatelabel_person', 'mod_fancytimer', 2), $config['segments'][1]['label']);
        $this->assertSame(get_string('templatelabel_changegroups', 'mod_fancytimer'), $config['rotation']['label']);
        $this->assertSame('bell', $config['rotation']['sound']);
        $this->assertSame(segments::PALETTE['purple'], $config['segments'][1]['bgcolour']);
    }

    /**
     * keys(), menu() and the default agree; unknown keys give null.
     */
    public function test_keys_menu_default(): void {
        $this->assertSame(templates::keys(), array_keys(templates::menu()));
        $this->assertContains(templates::DEFAULT, templates::keys());
        $this->assertSame(array_keys(self::template_provider()), templates::keys());
        foreach (templates::menu() as $key => $name) {
            $this->assertSame(get_string('template_' . $key, 'mod_fancytimer'), $name);
        }
        $this->assertNull(templates::get('nosuchtemplate'));
        $this->assertNull(templates::get(''));
    }
}
