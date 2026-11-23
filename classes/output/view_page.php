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

namespace mod_fancytimer\output;

use context_module;
use mod_fancytimer\local\display_config;
use mod_fancytimer\local\remote;
use mod_fancytimer\local\segments;
use moodle_url;
use renderer_base;
use stdClass;

/**
 * The timer display page (templates/view.mustache, driven by amd/src/timer.js).
 *
 * Users holding mod/fancytimer:control run the timer; everyone else sees a mirror
 * of the state the controlling page publishes.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view_page implements \core\output\named_templatable, \renderable {
    /** @var stdClass The fancytimer instance record. */
    protected stdClass $fancytimer;

    /** @var \cm_info|stdClass The course module. */
    protected $cm;

    /** @var context_module The module context. */
    protected context_module $context;

    /** @var bool Whether the viewer may control the timer. */
    protected bool $cancontrol;

    /**
     * Constructor.
     *
     * @param stdClass $fancytimer Instance record.
     * @param \cm_info|stdClass $cm Course module.
     * @param context_module $context Module context.
     * @param bool $cancontrol Whether the viewer holds mod/fancytimer:control.
     */
    public function __construct(stdClass $fancytimer, $cm, context_module $context, bool $cancontrol) {
        $this->fancytimer = $fancytimer;
        $this->cm = $cm;
        $this->context = $context;
        $this->cancontrol = $cancontrol;
    }

    /**
     * Data for templates/view.mustache.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $config = display_config::build($this->fancytimer, $this->cm, $this->context, $this->cancontrol);
        $segments = $config['segments'];
        $count = count($segments);

        $order = [];
        foreach ($segments as $i => $segment) {
            if (trim((string)$segment['label']) === '') {
                $segment['label'] = get_string('segmentunnamed', 'mod_fancytimer', $i + 1);
                $config['segments'][$i]['label'] = $segment['label'];
            }
            $order[] = [
                'position' => $i + 1,
                'label' => $segment['label'],
                'duration' => segments::format_duration((int)$segment['duration']),
                'isrotation' => !empty($segment['isrotation']),
                'bgcolour' => $segment['bgcolour'],
                'textcolour' => $segment['textcolour'],
                'iscurrent' => $i === 0,
            ];
        }

        $first = $config['segments'][0] ?? null;
        $cmid = (int)$this->cm->id;
        $data = [
            'rootid' => 'mod-fancytimer-' . $cmid,
            'configjson' => json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'name' => format_string($this->fancytimer->name, true, ['context' => $this->context, 'escape' => false]),
            'cancontrol' => $this->cancontrol,
            'ismirror' => !$this->cancontrol,
            'hassegments' => $count > 0,
            'segmentcount' => $count,
            'segmentsorder' => $order,
            'firstlabel' => $first ? $first['label'] : '',
            'firstbgcolour' => $first ? $first['bgcolour'] : segments::DEFAULT_BG,
            'firsttextcolour' => $first ? $first['textcolour'] : segments::DEFAULT_TEXT,
            'firstduration' => $first ? segments::format_duration((int)$first['duration']) : '0:00',
            'firstimageurl' => $first ? (string)($first['imageurl'] ?? '') : '',
            'firstposition' => get_string('segmentposition', 'mod_fancytimer', (object)['index' => 1, 'count' => $count]),
            'cyclelength' => segments::format_duration((int)$config['cyclelength']),
            'mirrornotice' => get_string('mirrornotice', 'mod_fancytimer'),
            'mirrorwaiting' => get_string('mirrorwaiting', 'mod_fancytimer'),
            'canedit' => false,
            'editurl' => '',
            'remoteurl' => '',
            'qrsvg' => '',
        ];

        if (has_capability('moodle/course:manageactivities', $this->context)) {
            $data['canedit'] = true;
            $data['editurl'] = (new moodle_url('/mod/fancytimer/edit.php', ['id' => $cmid]))->out(false);
        }

        if ($this->cancontrol) {
            $remoteurl = remote::remote_url($cmid);
            $data['remoteurl'] = $remoteurl->out(false);
            $data['qrsvg'] = remote::qr_svg($remoteurl);
        }

        return $data;
    }

    /**
     * The template this renderable is rendered with.
     *
     * @param renderer_base $renderer
     * @return string
     */
    public function get_template_name(renderer_base $renderer): string {
        return 'mod_fancytimer/view';
    }
}
