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

use cm_info;
use context_module;
use moodle_url;
use stdClass;

/**
 * Builds the configuration object the timer display (amd/src/timer.js) runs from.
 *
 * The object is JSON-encoded into a data attribute of templates/view.mustache
 * (data-config), because it can exceed js_call_amd()'s argument limit.
 *
 * Shape:
 *
 *     {
 *       cmid: int, contextid: int,
 *       mode: "control" | "mirror",          // control = may run the timer; mirror = shows the published state
 *       looping: bool,
 *       warning: {enabled: bool, secs: int, sound: "tick"},
 *       startsound: {as a segment's sound},  // played when the timer starts from the beginning
 *       segments: [{
 *         id: int, label: string (plain text), duration: int (s),
 *         bgcolour: "#rrggbb", textcolour: "#rrggbb", isrotation: bool,
 *         imageurl: string|null,
 *         sound: {type: "builtin", key: "chime"} | {type: "recording", url: string} | {type: "none"}
 *       }],
 *       cyclelength: int (s),
 *       pollms: int                          // remote/mirror polling interval
 *     }
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class display_config {
    /** @var int Polling interval for the controlling page (commands) and remotes (state), ms. */
    public const POLL_CONTROL_MS = 1500;

    /** @var int Polling interval for student mirrors, ms. */
    public const POLL_MIRROR_MS = 3000;

    /**
     * Build the config.
     *
     * @param stdClass $fancytimer Instance record.
     * @param cm_info|stdClass $cm
     * @param context_module $context
     * @param bool $cancontrol Whether the viewer holds mod/fancytimer:control.
     * @return array
     */
    public static function build(stdClass $fancytimer, $cm, context_module $context, bool $cancontrol): array {
        $playlist = segments::get_playlist($fancytimer);
        $images = self::image_urls($context);
        $out = [];
        foreach ($playlist as $segment) {
            $out[] = [
                'id' => (int)$segment->id,
                'label' => format_string((string)$segment->label, true, ['context' => $context, 'escape' => false]),
                'duration' => (int)$segment->duration,
                'bgcolour' => segments::clean_colour($segment->bgcolour, segments::DEFAULT_BG),
                'textcolour' => segments::clean_colour($segment->textcolour, segments::DEFAULT_TEXT),
                'isrotation' => (bool)$segment->isrotation,
                'imageurl' => $images[(int)$segment->id] ?? null,
                'sound' => self::sound((string)$segment->sound, $context),
            ];
        }
        return [
            'cmid' => (int)$cm->id,
            'contextid' => (int)$context->id,
            'mode' => $cancontrol ? 'control' : 'mirror',
            'looping' => !empty($fancytimer->looping),
            'warning' => [
                'enabled' => !empty($fancytimer->warningenabled),
                'secs' => (int)$fancytimer->warningsecs,
                'sound' => sounds::clean_warning($fancytimer->warningsound),
            ],
            'startsound' => self::sound(sounds::clean_start($fancytimer->startsound ?? null, (int)$fancytimer->id), $context),
            'segments' => $out,
            'cyclelength' => segments::cycle_length($playlist),
            'pollms' => $cancontrol ? self::POLL_CONTROL_MS : self::POLL_MIRROR_MS,
        ];
    }

    /**
     * The sound descriptor of a segment.
     *
     * @param string $key
     * @param context_module $context
     * @return array
     */
    public static function sound(string $key, context_module $context): array {
        if ($key === sounds::NONE) {
            return ['type' => 'none'];
        }
        $recid = sounds::recording_id($key);
        if ($recid) {
            $url = recordings::url($context, $recid);
            return $url ? ['type' => 'recording', 'url' => $url->out(false)] :
                ['type' => 'builtin', 'key' => sounds::DEFAULT_CHANGEOVER];
        }
        return ['type' => 'builtin', 'key' => sounds::is_builtin($key) ? $key : sounds::DEFAULT_CHANGEOVER];
    }

    /**
     * Headline image URL per segment id.
     *
     * @param context_module $context
     * @return array segment id => url string.
     */
    public static function image_urls(context_module $context): array {
        $urls = [];
        $files = get_file_storage()->get_area_files($context->id, 'mod_fancytimer', 'image', false, 'itemid, id', false);
        foreach ($files as $file) {
            $itemid = (int)$file->get_itemid();
            if (!isset($urls[$itemid])) {
                $urls[$itemid] = moodle_url::make_pluginfile_url(
                    $context->id,
                    'mod_fancytimer',
                    'image',
                    $itemid,
                    $file->get_filepath(),
                    $file->get_filename()
                )->out(false);
            }
        }
        return $urls;
    }
}
