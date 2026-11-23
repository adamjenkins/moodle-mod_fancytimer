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

use context_module;
use stdClass;

/**
 * Reading and writing a timer's segments, plus colour and contrast helpers.
 *
 * A timer has 1..MAX_SEGMENTS ordinary segments (isrotation = 0, ordered by
 * sortorder) and at most one rotation segment (isrotation = 1). Whether the
 * rotation segment plays is the instance's rotationenabled flag, so turning it
 * off keeps its settings.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class segments {
    /** @var int Most ordinary segments a timer may have. */
    public const MAX_SEGMENTS = 12;

    /** @var int Shortest segment, seconds. */
    public const MIN_DURATION = 1;

    /** @var int Longest segment, seconds (99 min 59 s). */
    public const MAX_DURATION = 5999;

    /** @var string Default countdown text colour. */
    public const DEFAULT_TEXT = '#212529';

    /** @var string Default background tint. */
    public const DEFAULT_BG = '#cfe2ff';

    /** @var float WCAG AA minimum for large text (the countdown and label are large text). */
    public const MIN_CONTRAST = 3.0;

    /** @var float WCAG AA minimum for normal text; below it the form warns too. */
    public const GOOD_CONTRAST = 4.5;

    /** @var array Named tints offered in the editor. Lang key suffix => hex. */
    public const PALETTE = [
        'blue' => '#cfe2ff',
        'purple' => '#e2d9f3',
        'yellow' => '#fff3cd',
        'green' => '#d1e7dd',
        'orange' => '#ffe5d0',
        'pink' => '#f7d6e6',
        'red' => '#f8d7da',
        'teal' => '#d2f4ea',
        'grey' => '#e9ecef',
        'white' => '#ffffff',
        'navy' => '#0b2545',
        'black' => '#000000',
    ];

    /**
     * Ordinary segments of a timer, in play order.
     *
     * @param int $fancytimerid
     * @return stdClass[] Indexed 0..n-1.
     */
    public static function get_segments(int $fancytimerid): array {
        global $DB;
        return array_values($DB->get_records(
            'fancytimer_segments',
            ['fancytimerid' => $fancytimerid, 'isrotation' => 0],
            'sortorder ASC, id ASC'
        ));
    }

    /**
     * The rotation segment of a timer, if one was ever saved.
     *
     * @param int $fancytimerid
     * @return stdClass|null
     */
    public static function get_rotation(int $fancytimerid): ?stdClass {
        global $DB;
        $records = $DB->get_records(
            'fancytimer_segments',
            ['fancytimerid' => $fancytimerid, 'isrotation' => 1],
            'id ASC',
            '*',
            0,
            1
        );
        return $records ? reset($records) : null;
    }

    /**
     * The segments that play, in order: ordinary segments, then the rotation segment if enabled.
     *
     * @param stdClass $fancytimer Instance record.
     * @return stdClass[]
     */
    public static function get_playlist(stdClass $fancytimer): array {
        $list = self::get_segments((int)$fancytimer->id);
        if (!empty($fancytimer->rotationenabled)) {
            $rotation = self::get_rotation((int)$fancytimer->id);
            if ($rotation) {
                $list[] = $rotation;
            }
        }
        return $list;
    }

    /**
     * Clean one segment's fields into storable form.
     *
     * @param array|stdClass $data label, duration, bgcolour, textcolour, sound (any may be missing).
     * @param int $fancytimerid For validating recording sound keys; 0 drops them.
     * @return stdClass label, duration, bgcolour, textcolour, sound.
     */
    public static function clean(array|stdClass $data, int $fancytimerid): stdClass {
        $data = (object)$data;
        $out = new stdClass();
        $out->label = \core_text::substr(clean_param(trim((string)($data->label ?? '')), PARAM_TEXT), 0, 255);
        $duration = (int)($data->duration ?? 60);
        $out->duration = max(self::MIN_DURATION, min(self::MAX_DURATION, $duration));
        $out->bgcolour = self::clean_colour($data->bgcolour ?? null, self::DEFAULT_BG);
        $out->textcolour = self::clean_colour($data->textcolour ?? null, self::DEFAULT_TEXT);
        $out->sound = sounds::clean_changeover($data->sound ?? null, $fancytimerid);
        return $out;
    }

    /**
     * Save the ordinary segments of a timer, keeping ids of segments that still exist.
     *
     * Each item may carry an 'id' of an existing segment of this timer; such rows are
     * updated in place (so their image files stay attached). Rows not in the list are
     * deleted with their files. Items without a valid id are inserted.
     *
     * @param int $fancytimerid
     * @param array $items Arrays/objects as accepted by clean(), optionally with id.
     * @param context_module|null $context Needed to delete the image files of removed segments.
     * @return int[] Segment ids in the order given.
     */
    public static function save_segments(int $fancytimerid, array $items, ?context_module $context): array {
        global $DB;
        $existing = $DB->get_records('fancytimer_segments', ['fancytimerid' => $fancytimerid, 'isrotation' => 0]);
        $ids = [];
        $order = 0;
        $now = time();
        foreach (array_slice(array_values($items), 0, self::MAX_SEGMENTS) as $item) {
            $item = (object)$item;
            $row = self::clean($item, $fancytimerid);
            $row->fancytimerid = $fancytimerid;
            $row->isrotation = 0;
            $row->sortorder = $order++;
            $row->timemodified = $now;
            $id = (int)($item->id ?? 0);
            if ($id && isset($existing[$id])) {
                $row->id = $id;
                $DB->update_record('fancytimer_segments', $row);
                unset($existing[$id]);
            } else {
                $row->id = $DB->insert_record('fancytimer_segments', $row);
            }
            $ids[] = (int)$row->id;
        }
        foreach ($existing as $old) {
            self::delete_segment($old, $context);
        }
        return $ids;
    }

    /**
     * Save (insert or update) the rotation segment of a timer.
     *
     * @param int $fancytimerid
     * @param array|stdClass|null $item Fields as accepted by clean(); null deletes the rotation segment.
     * @param context_module|null $context
     * @return int Rotation segment id, or 0 when deleted.
     */
    public static function save_rotation(int $fancytimerid, array|stdClass|null $item, ?context_module $context): int {
        global $DB;
        $existing = self::get_rotation($fancytimerid);
        if ($item === null) {
            if ($existing) {
                self::delete_segment($existing, $context);
            }
            return 0;
        }
        $row = self::clean($item, $fancytimerid);
        $row->fancytimerid = $fancytimerid;
        $row->isrotation = 1;
        $row->sortorder = self::MAX_SEGMENTS + 1;
        $row->timemodified = time();
        if ($existing) {
            $row->id = $existing->id;
            $DB->update_record('fancytimer_segments', $row);
            return (int)$row->id;
        }
        return (int)$DB->insert_record('fancytimer_segments', $row);
    }

    /**
     * Delete one segment row and its image file.
     *
     * @param stdClass $segment
     * @param context_module|null $context
     */
    public static function delete_segment(stdClass $segment, ?context_module $context): void {
        global $DB;
        if ($context) {
            get_file_storage()->delete_area_files($context->id, 'mod_fancytimer', 'image', $segment->id);
        }
        $DB->delete_records('fancytimer_segments', ['id' => $segment->id]);
    }

    /**
     * Replace a timer's whole configuration from a preset/template config (see preset_format).
     *
     * Updates the instance settings (looping, rotationenabled, warning*), replaces every
     * segment (dropping images) and the rotation segment.
     *
     * @param stdClass $fancytimer Instance record (updated in place and in the DB).
     * @param array $config A config already passed through preset_format::validate().
     * @param context_module|null $context
     */
    public static function apply_config(stdClass $fancytimer, array $config, ?context_module $context): void {
        global $DB;
        $id = (int)$fancytimer->id;
        foreach (self::get_segments($id) as $old) {
            self::delete_segment($old, $context);
        }
        self::save_segments($id, $config['segments'], $context);
        if ($config['rotation'] !== null) {
            $rotationid = self::save_rotation($id, $config['rotation'], $context);
            if ($context && $rotationid) {
                get_file_storage()->delete_area_files($context->id, 'mod_fancytimer', 'image', $rotationid);
            }
        }
        $settings = $config['settings'];
        $fancytimer->looping = (int)$settings['looping'];
        $fancytimer->rotationenabled = ($config['rotation'] !== null) ? (int)$settings['rotationenabled'] : 0;
        $fancytimer->warningenabled = (int)$settings['warningenabled'];
        $fancytimer->warningsecs = (int)$settings['warningsecs'];
        $fancytimer->warningsound = sounds::clean_warning($settings['warningsound']);
        $fancytimer->timemodified = time();
        $DB->update_record('fancytimer', (object)[
            'id' => $id,
            'looping' => $fancytimer->looping,
            'rotationenabled' => $fancytimer->rotationenabled,
            'warningenabled' => $fancytimer->warningenabled,
            'warningsecs' => $fancytimer->warningsecs,
            'warningsound' => $fancytimer->warningsound,
            'timemodified' => $fancytimer->timemodified,
        ]);
    }

    /**
     * Normalise a colour to lower-case #rrggbb.
     *
     * @param string|null $value '#abc', '#aabbcc' or without '#'.
     * @param string $default Returned when the value is not a colour.
     * @return string
     */
    public static function clean_colour(?string $value, string $default): string {
        $value = strtolower(trim((string)$value));
        if (preg_match('/^#?([0-9a-f]{3})$/', $value, $m)) {
            $value = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        } else if (preg_match('/^#?([0-9a-f]{6})$/', $value, $m)) {
            $value = '#' . $m[1];
        } else {
            return $default;
        }
        return $value;
    }

    /**
     * Is this a valid colour value (the editor's text field)?
     *
     * @param string|null $value
     * @return bool
     */
    public static function is_colour(?string $value): bool {
        return (bool)preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', trim((string)$value));
    }

    /**
     * WCAG 2.x contrast ratio of two colours.
     *
     * Same formula as amd/src/contrast.js; keep them in step.
     *
     * @param string $a #rrggbb
     * @param string $b #rrggbb
     * @return float 1..21
     */
    public static function contrast_ratio(string $a, string $b): float {
        $la = self::luminance(self::clean_colour($a, '#000000'));
        $lb = self::luminance(self::clean_colour($b, '#000000'));
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * Relative luminance of a #rrggbb colour.
     *
     * @param string $hex
     * @return float
     */
    private static function luminance(string $hex): float {
        $channels = [];
        foreach ([1, 3, 5] as $pos) {
            $c = hexdec(substr($hex, $pos, 2)) / 255;
            $channels[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * Total length of one cycle of the playlist, seconds.
     *
     * @param stdClass[] $playlist
     * @return int
     */
    public static function cycle_length(array $playlist): int {
        return (int)array_sum(array_map(fn($s) => (int)$s->duration, $playlist));
    }

    /**
     * Format seconds as m:ss (or h:mm:ss).
     *
     * @param int $seconds
     * @return string
     */
    public static function format_duration(int $seconds): string {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return $h ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }
}
