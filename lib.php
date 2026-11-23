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
 * Library of functions for mod_fancytimer.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_fancytimer\local\presets;
use mod_fancytimer\local\segments;
use mod_fancytimer\local\sounds;
use mod_fancytimer\local\templates;

/**
 * Features supported by this module.
 *
 * @param string $feature FEATURE_xx constant.
 * @return mixed True/false for known features, null otherwise.
 */
function fancytimer_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
        case FEATURE_BACKUP_MOODLE2:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_GRADE_OUTCOMES:
        case FEATURE_GROUPS:
        case FEATURE_GROUPINGS:
            return false;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_INTERACTIVECONTENT;
        default:
            return null;
    }
}

/**
 * Copy the instance settings from the form data onto a record.
 *
 * @param stdClass $data Form data.
 * @return stdClass
 */
function fancytimer_settings_from_form(stdClass $data): stdClass {
    return (object)[
        'looping' => empty($data->looping) ? 0 : 1,
        'warningenabled' => empty($data->warningenabled) ? 0 : 1,
        'warningsecs' => max(1, min(30, (int)($data->warningsecs ?? 5))),
        'warningsound' => sounds::clean_warning($data->warningsound ?? null),
    ];
}

/**
 * Create a new instance, with segments from the chosen template or preset.
 *
 * @param stdClass $data Form data (template: 'tpl:<key>' or 'preset:<id>').
 * @param mod_fancytimer_mod_form|null $mform
 * @return int New instance id.
 */
function fancytimer_add_instance($data, $mform = null) {
    global $DB, $USER;
    $now = time();
    $record = (object)[
        'course' => $data->course,
        'name' => $data->name,
        'intro' => $data->intro ?? '',
        'introformat' => $data->introformat ?? FORMAT_HTML,
        'rotationenabled' => 0,
        'timecreated' => $now,
        'timemodified' => $now,
    ];
    foreach ((array)fancytimer_settings_from_form($data) as $key => $value) {
        $record->$key = $value;
    }
    $record->id = $DB->insert_record('fancytimer', $record);

    $choice = (string)($data->template ?? ('tpl:' . templates::DEFAULT));
    $config = null;
    if (str_starts_with($choice, 'preset:')) {
        try {
            [, $config] = presets::get((int)substr($choice, 7), (int)$USER->id, 0);
        } catch (moodle_exception $e) {
            $config = null;
        }
    } else if (str_starts_with($choice, 'tpl:')) {
        $config = templates::get(substr($choice, 4));
    }
    $config = $config ?? templates::get(templates::DEFAULT);
    $context = context_module::instance($data->coursemodule);
    // The template decides segments and rotation; the form's own settings win over the template's.
    $formsettings = (array)fancytimer_settings_from_form($data);
    $config['settings'] = $formsettings + ['rotationenabled' => $config['settings']['rotationenabled']];
    segments::apply_config($record, $config, $context);
    return (int)$record->id;
}

/**
 * Update an instance's settings (segments are edited on edit.php).
 *
 * @param stdClass $data Form data.
 * @param mod_fancytimer_mod_form|null $mform
 * @return bool
 */
function fancytimer_update_instance($data, $mform = null) {
    global $DB;
    $record = fancytimer_settings_from_form($data);
    $record->id = $data->instance;
    $record->name = $data->name;
    $record->intro = $data->intro ?? '';
    $record->introformat = $data->introformat ?? FORMAT_HTML;
    $record->timemodified = time();
    return $DB->update_record('fancytimer', $record);
}

/**
 * Delete an instance and everything that belongs to it.
 *
 * Files in the module context are removed by core when the course module goes.
 *
 * @param int $id Instance id.
 * @return bool
 */
function fancytimer_delete_instance($id) {
    global $DB;
    if (!$DB->record_exists('fancytimer', ['id' => $id])) {
        return false;
    }
    foreach (['fancytimer_segments', 'fancytimer_recordings', 'fancytimer_state', 'fancytimer_commands'] as $table) {
        $DB->delete_records($table, ['fancytimerid' => $id]);
    }
    $DB->delete_records('fancytimer', ['id' => $id]);
    return true;
}

/**
 * Mark the activity viewed (completion + event).
 *
 * @param stdClass $fancytimer
 * @param stdClass $course
 * @param stdClass|cm_info $cm
 * @param context_module $context
 */
function fancytimer_view($fancytimer, $course, $cm, $context) {
    global $CFG;
    require_once($CFG->libdir . '/completionlib.php');
    $event = \mod_fancytimer\event\course_module_viewed::create([
        'objectid' => $fancytimer->id,
        'context' => $context,
    ]);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('fancytimer', $fancytimer);
    $event->trigger();
    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}

/**
 * Serve the plugin's files: segment images and teacher recordings.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool False if not found; otherwise does not return.
 */
function fancytimer_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_MODULE || !in_array($filearea, ['image', 'recording'], true)) {
        return false;
    }
    require_course_login($course, true, $cm);
    require_capability('mod/fancytimer:view', $context);

    $itemid = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
    $file = get_file_storage()->get_file($context->id, 'mod_fancytimer', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    // Serve inline only what the area is for; anything else is sent as a download.
    $mimetype = (string)$file->get_mimetype();
    $expected = $filearea === 'image' ? 'image/' : 'audio/';
    if (!str_starts_with($mimetype, $expected) || $mimetype === 'image/svg+xml') {
        $forcedownload = true;
    }
    send_stored_file($file, DAYSECS, 0, $forcedownload, $options);
}

/**
 * File areas for the file browser.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @return array
 */
function fancytimer_get_file_areas($course, $cm, $context) {
    return [
        'image' => get_string('area_image', 'mod_fancytimer'),
        'recording' => get_string('area_recording', 'mod_fancytimer'),
    ];
}

/**
 * Add the plugin's pages to the activity's secondary navigation.
 *
 * @param settings_navigation $settingsnav
 * @param navigation_node $node The module's settings node.
 */
function fancytimer_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $node) {
    $cm = $settingsnav->get_page()->cm;
    if (!$cm) {
        return;
    }
    $context = context_module::instance($cm->id);
    if (has_capability('moodle/course:manageactivities', $context)) {
        $node->add(
            get_string('editsegments', 'mod_fancytimer'),
            new moodle_url('/mod/fancytimer/edit.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'mod_fancytimer_segments',
            new pix_icon('i/settings', '')
        );
        $node->add(
            get_string('presets', 'mod_fancytimer'),
            new moodle_url('/mod/fancytimer/presets.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'mod_fancytimer_presets',
            new pix_icon('i/settings', '')
        );
    }
    if (has_capability('mod/fancytimer:control', $context)) {
        $node->add(
            get_string('remote', 'mod_fancytimer'),
            new moodle_url('/mod/fancytimer/remote.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'mod_fancytimer_remote',
            new pix_icon('i/mobile', '')
        );
    }
}

/**
 * Course reset form elements.
 *
 * @param MoodleQuickForm $mform
 */
function fancytimer_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'fancytimerheader', get_string('modulenameplural', 'mod_fancytimer'));
    $mform->addElement('advcheckbox', 'reset_fancytimer_remote', get_string('resetremote', 'mod_fancytimer'));
}

/**
 * Course reset form defaults.
 *
 * @param stdClass $course
 * @return array
 */
function fancytimer_reset_course_form_defaults($course) {
    return ['reset_fancytimer_remote' => 1];
}

/**
 * Reset user data: the remote-control state and command queue of every timer in the course.
 *
 * Segments, images and recordings are activity content and are kept.
 *
 * @param stdClass $data
 * @return array Status items.
 */
function fancytimer_reset_userdata($data) {
    global $DB;
    $status = [];
    if (!empty($data->reset_fancytimer_remote)) {
        $ids = $DB->get_fieldset_select('fancytimer', 'id', 'course = ?', [$data->courseid]);
        if ($ids) {
            [$insql, $params] = $DB->get_in_or_equal($ids);
            $DB->delete_records_select('fancytimer_state', "fancytimerid $insql", $params);
            $DB->delete_records_select('fancytimer_commands', "fancytimerid $insql", $params);
        }
        $status[] = [
            'component' => get_string('modulenameplural', 'mod_fancytimer'),
            'item' => get_string('resetremote', 'mod_fancytimer'),
            'error' => false,
        ];
    }
    return $status;
}
