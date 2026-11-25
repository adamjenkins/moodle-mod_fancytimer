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

/**
 * The segment editor: segments, rotation break and the teacher recordings library.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use mod_fancytimer\form\segments_form;
use mod_fancytimer\local\recordings;
use mod_fancytimer\local\segments;
use mod_fancytimer\local\sounds;

$id = required_param('id', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($id, 'fancytimer');
$fancytimer = $DB->get_record('fancytimer', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('moodle/course:manageactivities', $context);

$url = new moodle_url('/mod/fancytimer/edit.php', ['id' => $cm->id]);
$PAGE->set_url($url);
$PAGE->set_title(format_string($fancytimer->name) . ': ' . get_string('editsegments', 'mod_fancytimer'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');
$PAGE->add_body_class('mod-fancytimer-editpage');

$timerid = (int)$fancytimer->id;
$recordings = recordings::for_activity($timerid);
$maxbytes = get_user_max_upload_file_size($context, $CFG->maxbytes, $course->maxbytes);
$imageoptions = segments_form::image_options((int)$maxbytes);

$form = new segments_form($url, [
    'fancytimer' => $fancytimer,
    'cm' => $cm,
    'context' => $context,
    'recordings' => $recordings,
    'maxbytes' => (int)$maxbytes,
]);

// Prefill from the stored segments. Set before get_data() too: fields that hideIf() disabled
// are not posted, so their exported values fall back to these stored ones.
$segmentlist = segments::get_segments($timerid);
$rotation = segments::get_rotation($timerid);
$prefill = [
    'id' => $cm->id,
    'segmentid' => [],
    'label' => [],
    'duration' => [],
    'bgcolour' => [],
    'textcolour' => [],
    'sound' => [],
    'image' => [],
    'rotationenabled' => (int)$fancytimer->rotationenabled,
    'startsound' => sounds::clean_start($fancytimer->startsound, $timerid),
];
foreach ($segmentlist as $i => $segment) {
    $draftid = 0;
    file_prepare_draft_area($draftid, $context->id, 'mod_fancytimer', 'image', $segment->id, $imageoptions);
    $prefill['segmentid'][$i] = (int)$segment->id;
    $prefill['label'][$i] = $segment->label;
    $prefill['duration'][$i] = ['minutes' => intdiv((int)$segment->duration, 60), 'seconds' => (int)$segment->duration % 60];
    $prefill['bgcolour'][$i] = $segment->bgcolour;
    $prefill['textcolour'][$i] = $segment->textcolour;
    $prefill['sound'][$i] = $segment->sound;
    $prefill['image'][$i] = $draftid;
}
if ($rotation) {
    $draftid = 0;
    file_prepare_draft_area($draftid, $context->id, 'mod_fancytimer', 'image', $rotation->id, $imageoptions);
    $prefill['rotationlabel'] = $rotation->label;
    $prefill['rotationduration'] = ['minutes' => intdiv((int)$rotation->duration, 60),
        'seconds' => (int)$rotation->duration % 60];
    $prefill['rotationbgcolour'] = $rotation->bgcolour;
    $prefill['rotationtextcolour'] = $rotation->textcolour;
    $prefill['rotationsound'] = $rotation->sound;
    $prefill['rotationimage'] = $draftid;
} else {
    $prefill['rotationlabel'] = get_string('templatelabel_changegroups', 'mod_fancytimer');
    $prefill['rotationduration'] = ['minutes' => 0, 'seconds' => segments_form::ROTATION_DEFAULT_DURATION];
}
$form->set_data($prefill);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/mod/fancytimer/view.php', ['id' => $cm->id]));
}

if ($data = $form->get_data()) {
    $deleted = $data->{segments_form::DELETE_NAME . '-hidden'} ?? [];
    $labels = $data->label ?? [];
    ksort($labels);
    $items = [];
    $drafts = [];
    foreach ($labels as $i => $label) {
        if (!empty($deleted[$i])) {
            continue;
        }
        $items[] = [
            'id' => (int)($data->segmentid[$i] ?? 0),
            'label' => $label,
            'duration' => segments_form::duration_from($data->duration[$i] ?? null) ?? 60,
            'bgcolour' => $data->bgcolour[$i] ?? null,
            'textcolour' => $data->textcolour[$i] ?? null,
            'sound' => $data->sound[$i] ?? null,
        ];
        $drafts[] = (int)($data->image[$i] ?? 0);
    }

    $transaction = $DB->start_delegated_transaction();
    // The submitted segment ids are re-checked against this timer by save_segments().
    $ids = segments::save_segments($timerid, $items, $context);
    foreach ($ids as $k => $segmentid) {
        if (!empty($drafts[$k])) {
            file_save_draft_area_files($drafts[$k], $context->id, 'mod_fancytimer', 'image', $segmentid, $imageoptions);
        }
    }

    // Always save the rotation break, so its settings survive while it is switched off.
    $rotationenabled = empty($data->rotationenabled) ? 0 : 1;
    $rotationitem = [
        'label' => $data->rotationlabel ?? ($rotation->label ?? get_string('templatelabel_changegroups', 'mod_fancytimer')),
        'duration' => segments_form::duration_from($data->rotationduration ?? null)
            ?? (int)($rotation->duration ?? segments_form::ROTATION_DEFAULT_DURATION),
        'bgcolour' => $data->rotationbgcolour ?? ($rotation->bgcolour ?? segments_form::ROTATION_DEFAULT_BG),
        'textcolour' => $data->rotationtextcolour ?? ($rotation->textcolour ?? segments::DEFAULT_TEXT),
        'sound' => $data->rotationsound ?? ($rotation->sound ?? segments_form::ROTATION_DEFAULT_SOUND),
    ];
    $rotationid = segments::save_rotation($timerid, $rotationitem, $context);
    if ($rotationid && !empty($data->rotationimage)) {
        file_save_draft_area_files(
            (int)$data->rotationimage,
            $context->id,
            'mod_fancytimer',
            'image',
            $rotationid,
            $imageoptions
        );
    }

    $DB->update_record('fancytimer', (object)[
        'id' => $timerid,
        'rotationenabled' => $rotationenabled,
        'startsound' => sounds::clean_start($data->startsound ?? null, $timerid),
        'timemodified' => time(),
    ]);
    $transaction->allow_commit();

    // Warn about tints the countdown text is hard to read on.
    $low = [];
    foreach (segments::get_segments($timerid) as $position => $segment) {
        if (segments::contrast_ratio($segment->bgcolour, $segment->textcolour) < segments::GOOD_CONTRAST) {
            $low[] = get_string('segmentname', 'mod_fancytimer', (object)[
                'no' => $position + 1,
                'label' => s($segment->label),
            ]);
        }
    }
    if ($rotationenabled) {
        $saved = segments::get_rotation($timerid);
        if ($saved && segments::contrast_ratio($saved->bgcolour, $saved->textcolour) < segments::GOOD_CONTRAST) {
            $low[] = get_string('rotationname', 'mod_fancytimer', s($saved->label));
        }
    }
    if ($low) {
        \core\notification::warning(get_string('lowcontrastsegments', 'mod_fancytimer', implode(', ', $low)));
    }

    redirect($url, get_string('segmentssaved', 'mod_fancytimer'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// The recordings library below the form.
$recorderitems = [];
$recordingurls = [];
foreach ($recordings as $recording) {
    $fileurl = recordings::url($context, (int)$recording->id);
    $name = trim((string)$recording->name) !== '' ? $recording->name
        : get_string('recordingunnamed', 'mod_fancytimer', $recording->id);
    $recorderitems[] = [
        'id' => (int)$recording->id,
        'name' => $name,
        'url' => $fileurl ? $fileurl->out(false) : '',
        'hasurl' => (bool)$fileurl,
    ];
    if ($fileurl) {
        $recordingurls[(string)$recording->id] = $fileurl->out(false);
    }
}
$rootid = html_writer::random_id('mod-fancytimer-recorder-');
$recordercontext = [
    'rootid' => $rootid,
    'cmid' => (int)$cm->id,
    'recordings' => $recorderitems,
    'hasrecordings' => !empty($recorderitems),
    'recordingurls' => json_encode((object)$recordingurls, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
    'maxseconds' => 60,
    'maxbytes' => recordings::MAX_BYTES,
    'maxsize' => display_size(recordings::MAX_BYTES),
    'maxrecordings' => recordings::MAX_PER_ACTIVITY,
    'defaultname' => get_string('recordingdefaultname', 'mod_fancytimer'),
    'canaddmore' => count($recordings) < recordings::MAX_PER_ACTIVITY,
];

$palette = [];
foreach (segments::PALETTE as $key => $hex) {
    $palette[] = ['key' => $key, 'hex' => $hex, 'name' => get_string('colour_' . $key, 'mod_fancytimer')];
}
$PAGE->requires->js_call_amd('mod_fancytimer/editor', 'init', [$palette]);
$PAGE->requires->js_call_amd('mod_fancytimer/sound_preview', 'init', []);
$PAGE->requires->js_call_amd('mod_fancytimer/recorder', 'init', [$rootid]);

echo $OUTPUT->header();
$form->display();
echo $OUTPUT->render_from_template('mod_fancytimer/recorder', $recordercontext);
echo $OUTPUT->footer();
