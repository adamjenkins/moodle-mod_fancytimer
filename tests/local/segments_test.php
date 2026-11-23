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
 * Tests for the segments helper.
 *
 * @package    mod_fancytimer
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(segments::class)]
final class segments_test extends \advanced_testcase {
    /**
     * A throwaway timer (threeperson template: 3 segments + rotation).
     *
     * @return array [stdClass instance, \context_module]
     */
    private function make_timer(): array {
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $timer = $this->getDataGenerator()->create_module('fancytimer', ['course' => $course->id]);
        return [$timer, \context_module::instance($timer->cmid)];
    }

    /**
     * Attach an image file to a segment.
     *
     * @param \context_module $context
     * @param int $segmentid
     */
    private function add_image(\context_module $context, int $segmentid): void {
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_fancytimer',
            'filearea' => 'image',
            'itemid' => $segmentid,
            'filepath' => '/',
            'filename' => 'headline.png',
        ], 'not-really-a-png');
    }

    /**
     * Does a segment have an image file?
     *
     * @param \context_module $context
     * @param int $segmentid
     * @return bool
     */
    private function has_image(\context_module $context, int $segmentid): bool {
        return !get_file_storage()->is_area_empty($context->id, 'mod_fancytimer', 'image', $segmentid);
    }

    /**
     * Saving keeps the ids of kept segments, inserts new ones and deletes removed rows with their images.
     */
    public function test_save_segments_keeps_ids_and_deletes_removed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$timer, $context] = $this->make_timer();

        $before = segments::get_segments((int)$timer->id);
        $this->assertCount(3, $before);
        [$a, $b, $c] = array_map(fn($s) => (int)$s->id, $before);
        $this->add_image($context, $a);
        $this->add_image($context, $c);

        $ids = segments::save_segments((int)$timer->id, [
            ['id' => $b, 'label' => 'Second first', 'duration' => 45],
            ['id' => $a, 'label' => 'First second', 'duration' => 90],
            ['label' => 'Brand new', 'duration' => 10],
        ], $context);

        $this->assertCount(3, $ids);
        $this->assertSame($b, $ids[0]);
        $this->assertSame($a, $ids[1]);
        $this->assertNotContains($c, $ids);
        $this->assertFalse($DB->record_exists('fancytimer_segments', ['id' => $c]));
        $this->assertFalse($this->has_image($context, $c));
        $this->assertTrue($this->has_image($context, $a));

        $after = segments::get_segments((int)$timer->id);
        $this->assertSame(['Second first', 'First second', 'Brand new'], array_map(fn($s) => $s->label, $after));
        $this->assertEquals([0, 1, 2], array_map(fn($s) => (int)$s->sortorder, $after));
        $this->assertEquals(45, $after[0]->duration);

        // The rotation segment is not an ordinary segment and is untouched.
        $this->assertNotNull(segments::get_rotation((int)$timer->id));
    }

    /**
     * An id of another timer's segment is not taken over: a new row is inserted instead.
     */
    public function test_save_segments_ignores_foreign_ids(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$timer, $context] = $this->make_timer();
        [$other] = $this->make_timer();
        $foreign = segments::get_segments((int)$other->id)[0];

        $ids = segments::save_segments((int)$timer->id, [['id' => $foreign->id, 'label' => 'Hijack']], $context);

        $this->assertCount(1, $ids);
        $this->assertNotEquals((int)$foreign->id, $ids[0]);
        $stored = $DB->get_record('fancytimer_segments', ['id' => $foreign->id], '*', MUST_EXIST);
        $this->assertEquals($other->id, $stored->fancytimerid);
        $this->assertNotSame('Hijack', $stored->label);
        $this->assertCount(1, segments::get_segments((int)$timer->id));
    }

    /**
     * No more than MAX_SEGMENTS ordinary segments are stored.
     */
    public function test_save_segments_caps_count(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$timer, $context] = $this->make_timer();
        $items = array_fill(0, segments::MAX_SEGMENTS + 5, ['label' => 'x', 'duration' => 5]);

        $ids = segments::save_segments((int)$timer->id, $items, $context);

        $this->assertCount(segments::MAX_SEGMENTS, $ids);
        $this->assertCount(segments::MAX_SEGMENTS, segments::get_segments((int)$timer->id));
    }

    /**
     * clean() clamps the duration, normalises colours, cleans the label and the sound.
     */
    public function test_clean(): void {
        $this->resetAfterTest();

        $out = segments::clean(['duration' => 0, 'bgcolour' => '#ABC', 'textcolour' => 'FFAA00',
            'label' => '  <b>Hello</b>  ', 'sound' => 'klaxon'], 0);
        $this->assertSame(segments::MIN_DURATION, $out->duration);
        $this->assertSame('#aabbcc', $out->bgcolour);
        $this->assertSame('#ffaa00', $out->textcolour);
        $this->assertSame('Hello', $out->label);
        $this->assertSame('klaxon', $out->sound);

        $out = segments::clean((object)['duration' => 999999, 'bgcolour' => 'red', 'textcolour' => '#12345',
            'sound' => 'rec:5'], 0);
        $this->assertSame(segments::MAX_DURATION, $out->duration);
        $this->assertSame(segments::DEFAULT_BG, $out->bgcolour);
        $this->assertSame(segments::DEFAULT_TEXT, $out->textcolour);
        $this->assertSame(sounds::DEFAULT_CHANGEOVER, $out->sound);

        $out = segments::clean([], 0);
        $this->assertSame(60, $out->duration);
        $this->assertSame('', $out->label);
        $this->assertSame(segments::DEFAULT_BG, $out->bgcolour);
        $this->assertSame(segments::DEFAULT_TEXT, $out->textcolour);
        $this->assertSame(sounds::DEFAULT_CHANGEOVER, $out->sound);

        $this->assertSame(segments::MIN_DURATION, segments::clean(['duration' => -30], 0)->duration);
        $this->assertSame(255, \core_text::strlen(segments::clean(['label' => str_repeat('é', 400)], 0)->label));
    }

    /**
     * Colour helpers.
     */
    public function test_clean_colour_and_is_colour(): void {
        $this->assertSame('#ffffff', segments::clean_colour('#FFF', '#000000'));
        $this->assertSame('#0b2545', segments::clean_colour(' 0B2545 ', '#000000'));
        $this->assertSame('#000000', segments::clean_colour('#ggg', '#000000'));
        $this->assertSame('#123456', segments::clean_colour(null, '#123456'));
        $this->assertSame('#123456', segments::clean_colour('#1234567', '#123456'));
        $this->assertTrue(segments::is_colour('#abc'));
        $this->assertTrue(segments::is_colour('AABBCC'));
        $this->assertFalse(segments::is_colour('#abcd'));
        $this->assertFalse(segments::is_colour('blue'));
        $this->assertFalse(segments::is_colour(null));
    }

    /**
     * Contrast ratios against values computed independently.
     *
     * Expected values: python3 WCAG 2.x formula (same as luminance()) gave
     * 21.0 for #000000/#ffffff and 11.7354524 for #212529/#cfe2ff.
     */
    public function test_contrast_ratio(): void {
        $this->assertEqualsWithDelta(21.0, segments::contrast_ratio('#000000', '#ffffff'), 0.0001);
        $this->assertEqualsWithDelta(21.0, segments::contrast_ratio('#ffffff', '#000000'), 0.0001);
        $this->assertEqualsWithDelta(11.7354524, segments::contrast_ratio('#212529', '#cfe2ff'), 0.0001);
        $this->assertEqualsWithDelta(1.0, segments::contrast_ratio('#cfe2ff', '#cfe2ff'), 0.0001);
        $this->assertEqualsWithDelta(1.0, segments::contrast_ratio('#FFF', '#ffffff'), 0.0001);
        // Every palette tint passes the large-text minimum against its natural text colour.
        foreach (segments::PALETTE as $name => $hex) {
            $best = max(segments::contrast_ratio($hex, '#000000'), segments::contrast_ratio($hex, '#ffffff'));
            $this->assertGreaterThanOrEqual(segments::MIN_CONTRAST, $best, $name);
        }
    }

    /**
     * The playlist adds the rotation segment only when rotation is enabled.
     */
    public function test_get_playlist_and_cycle_length(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$timer] = $this->make_timer();

        $timer->rotationenabled = 1;
        $playlist = segments::get_playlist($timer);
        $this->assertCount(4, $playlist);
        $this->assertEquals(1, end($playlist)->isrotation);
        $this->assertSame(3 * 120 + 30, segments::cycle_length($playlist));

        $timer->rotationenabled = 0;
        $playlist = segments::get_playlist($timer);
        $this->assertCount(3, $playlist);
        $this->assertSame(360, segments::cycle_length($playlist));
        $this->assertSame(0, segments::cycle_length([]));
    }

    /**
     * The rotation segment is updated in place and can be deleted with its image.
     */
    public function test_save_rotation(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$timer, $context] = $this->make_timer();
        $existing = segments::get_rotation((int)$timer->id);
        $this->assertNotNull($existing);

        $id = segments::save_rotation((int)$timer->id, ['label' => 'Swap seats', 'duration' => 20], $context);
        $this->assertSame((int)$existing->id, $id);
        $stored = segments::get_rotation((int)$timer->id);
        $this->assertSame('Swap seats', $stored->label);
        $this->assertEquals(20, $stored->duration);
        $this->assertEquals(1, $stored->isrotation);
        $this->assertSame(1, $DB->count_records('fancytimer_segments', ['fancytimerid' => $timer->id, 'isrotation' => 1]));

        $this->add_image($context, $id);
        $this->assertSame(0, segments::save_rotation((int)$timer->id, null, $context));
        $this->assertNull(segments::get_rotation((int)$timer->id));
        $this->assertFalse($this->has_image($context, $id));

        $newid = segments::save_rotation((int)$timer->id, ['label' => 'Again'], $context);
        $this->assertGreaterThan(0, $newid);
    }

    /**
     * apply_config() replaces segments, drops images and stores the settings.
     */
    public function test_apply_config(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$timer, $context] = $this->make_timer();
        $old = segments::get_segments((int)$timer->id);
        $this->add_image($context, (int)$old[0]->id);

        $config = templates::get('pairs');
        $config['settings']['looping'] = 0;
        $config['settings']['warningenabled'] = 1;
        $config['settings']['warningsecs'] = 8;
        $config['settings']['warningsound'] = 'pulse';
        segments::apply_config($timer, $config, $context);

        $new = segments::get_segments((int)$timer->id);
        $this->assertCount(2, $new);
        $this->assertSame([180, 180], array_map(fn($s) => (int)$s->duration, $new));
        $this->assertFalse($DB->record_exists('fancytimer_segments', ['id' => $old[0]->id]));
        $this->assertFalse($this->has_image($context, (int)$old[0]->id));

        $stored = $DB->get_record('fancytimer', ['id' => $timer->id], '*', MUST_EXIST);
        $this->assertEquals(0, $stored->looping);
        $this->assertEquals(1, $stored->rotationenabled);
        $this->assertEquals(1, $stored->warningenabled);
        $this->assertEquals(8, $stored->warningsecs);
        $this->assertSame('pulse', $stored->warningsound);
        $this->assertSame(
            get_string('templatelabel_changepartners', 'mod_fancytimer'),
            segments::get_rotation((int)$timer->id)->label
        );

        // A config without a rotation segment switches rotation off.
        segments::apply_config($timer, templates::get('single'), $context);
        $this->assertEquals(0, $DB->get_field('fancytimer', 'rotationenabled', ['id' => $timer->id]));
        $this->assertCount(1, segments::get_playlist($timer));
    }

    /**
     * Duration formatting.
     */
    public function test_format_duration(): void {
        $this->assertSame('0:00', segments::format_duration(0));
        $this->assertSame('1:05', segments::format_duration(65));
        $this->assertSame('1:39:59', segments::format_duration(5999));
        $this->assertSame('1:01:01', segments::format_duration(3661));
    }
}
