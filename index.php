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
 * Lists the Fancy Timer activities in a course.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$course = get_course($id);
require_course_login($course);

$coursecontext = context_course::instance($course->id);
$event = \mod_fancytimer\event\course_module_instance_list_viewed::create(['context' => $coursecontext]);
$event->add_record_snapshot('course', $course);
$event->trigger();

$PAGE->set_url('/mod/fancytimer/index.php', ['id' => $id]);
$PAGE->set_title(format_string($course->shortname) . ': ' . get_string('modulenameplural', 'mod_fancytimer'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');
$PAGE->navbar->add(get_string('modulenameplural', 'mod_fancytimer'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_fancytimer'));

$timers = get_all_instances_in_course('fancytimer', $course);
if (!$timers) {
    notice(
        get_string('thereareno', 'moodle', get_string('modulenameplural', 'mod_fancytimer')),
        new moodle_url('/course/view.php', ['id' => $course->id])
    );
}

$table = new html_table();
$table->head = [get_string('name'), get_string('moduleintro')];
foreach ($timers as $timer) {
    $cmcontext = context_module::instance($timer->coursemodule);
    $attributes = $timer->visible ? [] : ['class' => 'dimmed'];
    $link = html_writer::link(
        new moodle_url('/mod/fancytimer/view.php', ['id' => $timer->coursemodule]),
        format_string($timer->name, true, ['context' => $cmcontext]),
        $attributes
    );
    $intro = format_module_intro('fancytimer', $timer, $timer->coursemodule);
    $table->data[] = [$link, $intro];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
