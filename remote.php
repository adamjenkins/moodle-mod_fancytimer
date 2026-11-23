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
 * Phone remote: controls a timer that runs on the classroom screen.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_fancytimer\local\display_config;
use mod_fancytimer\local\remote;
use mod_fancytimer\local\segments;

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($id, 'fancytimer');
$fancytimer = $DB->get_record('fancytimer', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/fancytimer:control', $context);

$name = format_string($fancytimer->name, true, ['context' => $context]);

$PAGE->set_url(remote::remote_url($cm->id));
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('remote', 'mod_fancytimer') . ': ' . $name);
$PAGE->set_heading(format_string($course->fullname));
$PAGE->add_body_class('mod-fancytimer-remotepage');
$PAGE->activityheader->disable();

$segmentsout = [];
foreach (segments::get_playlist($fancytimer) as $segment) {
    $segmentsout[] = [
        'label' => format_string((string)$segment->label, true, ['context' => $context, 'escape' => false]),
        'duration' => (int)$segment->duration,
    ];
}
$config = [
    'cmid' => (int)$cm->id,
    'pollms' => display_config::POLL_CONTROL_MS,
    'segments' => $segmentsout,
];

$rootid = html_writer::random_id('mod-fancytimer-remote-');
$templatecontext = [
    'rootid' => $rootid,
    'name' => $name,
    'config' => json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
    'viewurl' => (new moodle_url('/mod/fancytimer/view.php', ['id' => $cm->id]))->out(false),
];

$PAGE->requires->js_call_amd('mod_fancytimer/remote', 'init', [$rootid]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_fancytimer/remote', $templatecontext);
echo $OUTPUT->footer();
