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
 * Presets page: bundled templates, the user's own presets, saving, exporting and importing.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_fancytimer\form\preset_import_form;
use mod_fancytimer\form\preset_save_form;
use mod_fancytimer\local\preset_format;
use mod_fancytimer\local\presets;
use mod_fancytimer\local\segments;
use mod_fancytimer\local\templates;

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once($CFG->libdir . '/filelib.php');

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$key = optional_param('key', '', PARAM_ALPHANUMEXT);
$presetid = optional_param('presetid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'fancytimer');
$fancytimer = $DB->get_record('fancytimer', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('moodle/course:manageactivities', $context);

$baseurl = new moodle_url('/mod/fancytimer/presets.php', ['id' => $cm->id]);
$PAGE->set_url($baseurl);
$PAGE->set_title(format_string($fancytimer->name) . ': ' . get_string('presets', 'mod_fancytimer'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->disable();

$userid = (int)$USER->id;

// Downloads (read-only): this timer, or one of the user's presets.
if ($action === 'exporttimer') {
    $json = preset_format::to_json(preset_format::from_instance($fancytimer, false));
    $filename = clean_filename($fancytimer->name);
    send_file($json, ($filename !== '' ? $filename : 'fancytimer') . '.json', 0, 0, true, true, 'application/json');
    die();
}
if ($action === 'exportpreset') {
    [$record, $config] = presets::get($presetid, $userid, 0);
    $config['name'] = (string)$record->name;
    $filename = clean_filename($record->name);
    send_file(
        preset_format::to_json($config),
        ($filename !== '' ? $filename : 'preset') . '.json',
        0,
        0,
        true,
        true,
        'application/json'
    );
    die();
}

// Apply a template or preset: confirm, then replace the segments and settings.
if ($action === 'applytemplate' || $action === 'applypreset') {
    if ($action === 'applytemplate') {
        $config = templates::get($key);
        if ($config === null) {
            throw new moodle_exception('invalidpreset', 'mod_fancytimer');
        }
        $name = format_string($config['name'], true, ['context' => $context]);
        $params = ['action' => $action, 'key' => $key];
    } else {
        [$record, $config] = presets::get($presetid, $userid, (int)$fancytimer->id);
        $name = format_string($record->name, true, ['context' => $context]);
        $params = ['action' => $action, 'presetid' => $presetid];
    }
    if ($confirm) {
        require_sesskey();
        segments::apply_config($fancytimer, $config, $context);
        redirect(
            new moodle_url('/mod/fancytimer/edit.php', ['id' => $cm->id]),
            get_string('presetapplied', 'mod_fancytimer', $name),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    $continue = new single_button(
        new moodle_url($baseurl, $params + ['confirm' => 1]),
        get_string('applytotimer', 'mod_fancytimer'),
        'post',
        single_button::BUTTON_PRIMARY
    );
    $cancel = new single_button($baseurl, get_string('cancel'), 'get');
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('applypresetheading', 'mod_fancytimer'));
    echo $OUTPUT->confirm(get_string('applypresetconfirm', 'mod_fancytimer', $name), $continue, $cancel);
    echo $OUTPUT->footer();
    die();
}

// Delete one of the user's presets: confirm, then delete.
if ($action === 'deletepreset') {
    [$record] = presets::get($presetid, $userid, 0);
    $name = format_string($record->name, true, ['context' => $context]);
    if ($confirm) {
        require_sesskey();
        presets::delete($presetid, $userid);
        redirect(
            $baseurl,
            get_string('presetdeleted', 'mod_fancytimer', $name),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    $continue = new single_button(
        new moodle_url($baseurl, ['action' => $action, 'presetid' => $presetid, 'confirm' => 1]),
        get_string('delete'),
        'post',
        single_button::BUTTON_DANGER
    );
    $cancel = new single_button($baseurl, get_string('cancel'), 'get');
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('deletepresetheading', 'mod_fancytimer'));
    echo $OUTPUT->confirm(get_string('deletepresetconfirm', 'mod_fancytimer', $name), $continue, $cancel);
    echo $OUTPUT->footer();
    die();
}

// Save this timer as a preset.
$saveform = new preset_save_form($baseurl);
$saveform->set_data(['id' => $cm->id, 'name' => $fancytimer->name]);
if ($data = $saveform->get_data()) {
    try {
        $newid = presets::create($userid, (string)$data->name, preset_format::from_instance($fancytimer, true));
    } catch (moodle_exception $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    $saved = $DB->get_field('fancytimer_presets', 'name', ['id' => $newid]);
    redirect(
        $baseurl,
        get_string('presetsaved', 'mod_fancytimer', format_string($saved, true, ['context' => $context])),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Import a preset file as a new preset of the user.
$importform = new preset_import_form($baseurl);
$importform->set_data(['id' => $cm->id]);
if ($importform->get_data()) {
    $content = $importform->get_file_content('presetfile');
    try {
        if ($content === false || $content === '') {
            throw new moodle_exception('invalidpreset', 'mod_fancytimer');
        }
        $config = preset_format::validate($content, 0);
    } catch (moodle_exception $e) {
        redirect($baseurl, get_string('invalidpreset', 'mod_fancytimer'), null, \core\output\notification::NOTIFY_ERROR);
    }
    $name = $config['name'];
    if ($name === '') {
        $name = pathinfo((string)$importform->get_new_filename('presetfile'), PATHINFO_FILENAME);
    }
    try {
        $newid = presets::create($userid, $name, $config);
    } catch (moodle_exception $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    $saved = $DB->get_field('fancytimer_presets', 'name', ['id' => $newid]);
    redirect(
        $baseurl,
        get_string('presetimported', 'mod_fancytimer', format_string($saved, true, ['context' => $context])),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Template context for the segment summary of a validated config: segments (label, duration) and one cycle's length.
$summary = function (array $config): array {
    $items = [];
    $total = 0;
    foreach ($config['segments'] as $segment) {
        $items[] = ['label' => $segment['label'], 'duration' => segments::format_duration((int)$segment['duration'])];
        $total += (int)$segment['duration'];
    }
    if ($config['rotation'] !== null && !empty($config['settings']['rotationenabled'])) {
        $items[] = [
            'label' => get_string('presetsummaryrotation', 'mod_fancytimer', $config['rotation']['label']),
            'duration' => segments::format_duration((int)$config['rotation']['duration']),
        ];
        $total += (int)$config['rotation']['duration'];
    }
    return ['segments' => $items, 'cycle' => segments::format_duration($total)];
};

$templatesctx = [];
foreach (templates::keys() as $templatekey) {
    $config = templates::get($templatekey);
    if ($config === null) {
        continue;
    }
    $templatesctx[] = [
        'key' => $templatekey,
        'name' => format_string($config['name'], true, ['context' => $context]),
        'plainname' => format_string($config['name'], true, ['context' => $context, 'escape' => false]),
    ] + $summary($config);
}

$presetsctx = [];
foreach (presets::for_user($userid) as $preset) {
    $presetsctx[] = [
        'presetid' => (int)$preset->id,
        'name' => format_string($preset->name, true, ['context' => $context]),
        'plainname' => format_string($preset->name, true, ['context' => $context, 'escape' => false]),
        'timemodified' => userdate($preset->timemodified, get_string('strftimedatetimeshort', 'core_langconfig')),
    ];
}

$templatecontext = [
    'cmid' => (int)$cm->id,
    'actionurl' => (new moodle_url('/mod/fancytimer/presets.php'))->out(false),
    'sesskey' => sesskey(),
    'templates' => $templatesctx,
    'presets' => $presetsctx,
    'haspresets' => !empty($presetsctx),
    'saveform' => $saveform->render(),
    'importform' => $importform->render(),
];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('presets', 'mod_fancytimer'));
echo $OUTPUT->render_from_template('mod_fancytimer/presets', $templatecontext);
echo $OUTPUT->footer();
