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

namespace mod_fancytimer;

use mod_fancytimer\local\presets;
use mod_fancytimer\local\remote;
use mod_fancytimer\local\segments;
use mod_fancytimer\local\templates;

/**
 * Tests for the lib.php callbacks of mod_fancytimer.
 *
 * @package    mod_fancytimer
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('fancytimer_supports')]
#[\PHPUnit\Framework\Attributes\CoversFunction('fancytimer_settings_from_form')]
#[\PHPUnit\Framework\Attributes\CoversFunction('fancytimer_add_instance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('fancytimer_update_instance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('fancytimer_delete_instance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('fancytimer_view')]
#[\PHPUnit\Framework\Attributes\CoversFunction('fancytimer_get_file_areas')]
#[\PHPUnit\Framework\Attributes\CoversFunction('fancytimer_reset_course_form_defaults')]
#[\PHPUnit\Framework\Attributes\CoversFunction('fancytimer_reset_userdata')]
final class lib_test extends \advanced_testcase {
    /**
     * Load lib.php.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->dirroot . '/mod/fancytimer/lib.php');
    }

    /**
     * Supported features.
     */
    public function test_supports(): void {
        $this->assertTrue(fancytimer_supports(FEATURE_MOD_INTRO));
        $this->assertTrue(fancytimer_supports(FEATURE_SHOW_DESCRIPTION));
        $this->assertTrue(fancytimer_supports(FEATURE_BACKUP_MOODLE2));
        $this->assertTrue(fancytimer_supports(FEATURE_COMPLETION_TRACKS_VIEWS));
        $this->assertFalse(fancytimer_supports(FEATURE_GRADE_HAS_GRADE));
        $this->assertFalse(fancytimer_supports(FEATURE_GROUPS));
        $this->assertSame(MOD_PURPOSE_INTERACTIVECONTENT, fancytimer_supports(FEATURE_MOD_PURPOSE));
        $this->assertNull(fancytimer_supports('nosuchfeature'));
    }

    /**
     * Expected segment durations and rotation per template.
     *
     * @return array
     */
    public static function template_provider(): array {
        return [
            'threeperson' => ['threeperson', [120, 120, 120], 30],
            'pairs' => ['pairs', [180, 180], 30],
            'thinkpairshare' => ['thinkpairshare', [60, 120, 120], null],
            'single' => ['single', [300], null],
        ];
    }

    /**
     * A new activity gets the segments and rotation of its template; the form's settings win.
     *
     * @param string $key
     * @param int[] $durations
     * @param int|null $rotation
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('template_provider')]
    public function test_add_instance_from_template(string $key, array $durations, ?int $rotation): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);

        $timer = $this->getDataGenerator()->create_module('fancytimer', ['course' => $course->id, 'name' => 'Speaking',
            'template' => 'tpl:' . $key, 'looping' => 1, 'warningenabled' => 1, 'warningsecs' => 12,
            'warningsound' => 'pulse']);

        $record = $DB->get_record('fancytimer', ['id' => $timer->id], '*', MUST_EXIST);
        $this->assertSame('Speaking', $record->name);
        $this->assertEquals($course->id, $record->course);
        $this->assertEquals(1, $record->looping);
        $this->assertEquals(1, $record->warningenabled);
        $this->assertEquals(12, $record->warningsecs);
        $this->assertSame('pulse', $record->warningsound);
        $this->assertGreaterThan(0, $record->timecreated);

        $segments = segments::get_segments((int)$timer->id);
        $this->assertSame($durations, array_map(fn($s) => (int)$s->duration, $segments));
        $this->assertSame(templates::get($key)['segments'][0]['label'], $segments[0]->label);
        if ($rotation === null) {
            $this->assertNull(segments::get_rotation((int)$timer->id));
            $this->assertEquals(0, $record->rotationenabled);
        } else {
            $this->assertEquals($rotation, segments::get_rotation((int)$timer->id)->duration);
            $this->assertEquals(1, $record->rotationenabled);
        }
    }

    /**
     * Out-of-range form settings are clamped; an unknown template gives the default one.
     */
    public function test_add_instance_cleans_settings_and_falls_back(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);

        $timer = $this->getDataGenerator()->create_module('fancytimer', ['course' => $course->id,
            'template' => 'tpl:nosuchtemplate', 'warningsecs' => 500, 'warningsound' => 'klaxon']);

        $record = $DB->get_record('fancytimer', ['id' => $timer->id], '*', MUST_EXIST);
        $this->assertEquals(30, $record->warningsecs);
        $this->assertSame('tick', $record->warningsound);
        $this->assertEquals(0, $record->looping);
        $this->assertCount(3, segments::get_segments((int)$timer->id));
        $this->assertEquals(1, $record->rotationenabled);

        $timer = $this->getDataGenerator()->create_module('fancytimer', ['course' => $course->id,
            'template' => 'garbage', 'warningsecs' => 0]);
        $this->assertEquals(1, $DB->get_field('fancytimer', 'warningsecs', ['id' => $timer->id]));
        $this->assertCount(3, segments::get_segments((int)$timer->id));
    }

    /**
     * A new activity can start from the creating user's own preset, never from another user's.
     */
    public function test_add_instance_from_preset(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $config = templates::get('pairs');
        $config['segments'][0]['label'] = 'Speaker A';
        $config['segments'][0]['duration'] = 75;
        $presetid = presets::create((int)$teacher->id, 'My pairs', $config);

        $this->setUser($teacher);
        $timer = $this->getDataGenerator()->create_module('fancytimer', ['course' => $course->id,
            'template' => 'preset:' . $presetid]);
        $segments = segments::get_segments((int)$timer->id);
        $this->assertSame([75, 180], array_map(fn($s) => (int)$s->duration, $segments));
        $this->assertSame('Speaker A', $segments[0]->label);
        $this->assertEquals(30, segments::get_rotation((int)$timer->id)->duration);
        $this->assertEquals(1, $DB->get_field('fancytimer', 'rotationenabled', ['id' => $timer->id]));

        // Another user cannot use it: the default template is used instead.
        $this->setUser($other);
        $timer = $this->getDataGenerator()->create_module('fancytimer', ['course' => $course->id,
            'template' => 'preset:' . $presetid]);
        $this->assertSame([120, 120, 120], array_map(
            fn($s) => (int)$s->duration,
            segments::get_segments((int)$timer->id)
        ));
    }

    /**
     * Updating settings keeps the segments.
     */
    public function test_update_instance(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $timer = $this->getDataGenerator()->create_module('fancytimer', ['course' => $course->id]);
        $before = segments::get_segments((int)$timer->id);

        $this->assertTrue(fancytimer_update_instance((object)[
            'instance' => $timer->id, 'name' => 'Renamed', 'intro' => 'Hi', 'introformat' => FORMAT_HTML,
            'looping' => 1, 'warningenabled' => 1, 'warningsecs' => 3, 'warningsound' => 'chime',
        ]));

        $record = $DB->get_record('fancytimer', ['id' => $timer->id], '*', MUST_EXIST);
        $this->assertSame('Renamed', $record->name);
        $this->assertEquals(1, $record->looping);
        $this->assertEquals(1, $record->warningenabled);
        $this->assertEquals(3, $record->warningsecs);
        $this->assertSame('chime', $record->warningsound);
        $this->assertEquals(1, $record->rotationenabled);
        $this->assertEquals(array_column($before, 'id'), array_column(segments::get_segments((int)$timer->id), 'id'));
    }

    /**
     * Deleting an instance removes its child rows and leaves other timers alone.
     */
    public function test_delete_instance(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_fancytimer');
        $timer = $this->getDataGenerator()->create_module('fancytimer', ['course' => $course->id]);
        $keep = $this->getDataGenerator()->create_module('fancytimer', ['course' => $course->id]);
        foreach ([$timer, $keep] as $t) {
            $generator->create_recording($t);
            remote::queue_command((int)$t->id, 2, 'start', 0);
            remote::publish_state((int)$t->id, 2, '{"status":"running"}');
        }
        $presetid = $generator->create_preset((int)get_admin()->id);

        $this->assertTrue(fancytimer_delete_instance($timer->id));

        $this->assertFalse($DB->record_exists('fancytimer', ['id' => $timer->id]));
        foreach (['fancytimer_segments', 'fancytimer_recordings', 'fancytimer_state', 'fancytimer_commands'] as $table) {
            $this->assertSame(0, $DB->count_records($table, ['fancytimerid' => $timer->id]), $table);
            $this->assertGreaterThan(0, $DB->count_records($table, ['fancytimerid' => $keep->id]), $table);
        }
        // Presets belong to the user, not the activity.
        $this->assertTrue($DB->record_exists('fancytimer_presets', ['id' => $presetid]));

        $this->assertFalse(fancytimer_delete_instance($timer->id));
    }

    /**
     * Course reset clears the remote state and commands of the course's timers only.
     */
    public function test_reset_userdata(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_fancytimer');
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $othercourse = $this->getDataGenerator()->create_course(['summary' => '']);
        $timer = $this->getDataGenerator()->create_module('fancytimer', ['course' => $course->id]);
        $other = $this->getDataGenerator()->create_module('fancytimer', ['course' => $othercourse->id]);
        foreach ([$timer, $other] as $t) {
            $generator->create_recording($t);
            remote::queue_command((int)$t->id, 2, 'next', 0);
            remote::publish_state((int)$t->id, 2, '{"status":"paused"}');
        }

        $this->assertSame(['reset_fancytimer_remote' => 1], fancytimer_reset_course_form_defaults($course));

        // With the option off nothing is cleared.
        $this->assertSame([], fancytimer_reset_userdata((object)['courseid' => $course->id,
            'reset_fancytimer_remote' => 0]));
        $this->assertSame(1, $DB->count_records('fancytimer_state', ['fancytimerid' => $timer->id]));

        $status = fancytimer_reset_userdata((object)['courseid' => $course->id, 'reset_fancytimer_remote' => 1]);

        $this->assertCount(1, $status);
        $this->assertFalse($status[0]['error']);
        $this->assertSame(get_string('resetremote', 'mod_fancytimer'), $status[0]['item']);
        $this->assertSame(0, $DB->count_records('fancytimer_state', ['fancytimerid' => $timer->id]));
        $this->assertSame(0, $DB->count_records('fancytimer_commands', ['fancytimerid' => $timer->id]));
        // Activity content stays.
        $this->assertCount(3, segments::get_segments((int)$timer->id));
        $this->assertSame(1, $DB->count_records('fancytimer_recordings', ['fancytimerid' => $timer->id]));
        // The other course is untouched.
        $this->assertSame(1, $DB->count_records('fancytimer_state', ['fancytimerid' => $other->id]));
        $this->assertSame(1, $DB->count_records('fancytimer_commands', ['fancytimerid' => $other->id]));

        // A course without timers still reports the item.
        $empty = $this->getDataGenerator()->create_course(['summary' => '']);
        $this->assertCount(1, fancytimer_reset_userdata((object)['courseid' => $empty->id,
            'reset_fancytimer_remote' => 1]));
    }

    /**
     * Viewing triggers the course_module_viewed event.
     */
    public function test_view(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $timer = $this->getDataGenerator()->create_module('fancytimer', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('fancytimer', $timer->cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $record = $DB->get_record('fancytimer', ['id' => $timer->id], '*', MUST_EXIST);

        $sink = $this->redirectEvents();
        fancytimer_view($record, $course, $cm, $context);
        $events = array_values(array_filter(
            $sink->get_events(),
            fn($e) => $e instanceof \mod_fancytimer\event\course_module_viewed
        ));
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertEquals($timer->id, $events[0]->objectid);
        $this->assertEquals($context->id, $events[0]->contextid);
    }

    /**
     * File areas.
     */
    public function test_get_file_areas(): void {
        $this->assertSame(['image', 'recording'], array_keys(fancytimer_get_file_areas(null, null, null)));
    }
}
