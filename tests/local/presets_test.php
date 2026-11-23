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
 * Tests for users' saved presets.
 *
 * @package    mod_fancytimer
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(presets::class)]
final class presets_test extends \advanced_testcase {
    /**
     * Create, list, read back and delete a preset.
     */
    public function test_create_get_list_delete(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $id = presets::create((int)$user->id, '  <b>Pair talk</b> ', templates::get('pairs'));

        $record = $DB->get_record('fancytimer_presets', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame('Pair talk', $record->name);
        $this->assertEquals($user->id, $record->userid);

        [$got, $config] = presets::get($id, (int)$user->id);
        $this->assertEquals($id, $got->id);
        $this->assertSame('Pair talk', $config['name']);
        $this->assertSame([180, 180], array_column($config['segments'], 'duration'));
        $this->assertNotNull($config['rotation']);

        $list = presets::for_user((int)$user->id);
        $this->assertSame([(int)$id], array_map('intval', array_keys($list)));
        $this->assertObjectNotHasProperty('config', reset($list));

        $this->assertTrue(presets::delete($id, (int)$user->id));
        $this->assertFalse($DB->record_exists('fancytimer_presets', ['id' => $id]));
        $this->assertFalse(presets::delete($id, (int)$user->id));
    }

    /**
     * An empty name gets the "untitled" name.
     */
    public function test_create_unnamed(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $id = presets::create((int)$user->id, '   ', templates::get('single'));
        [$record] = presets::get($id, (int)$user->id);
        $this->assertSame(get_string('presetunnamed', 'mod_fancytimer'), $record->name);
    }

    /**
     * Another user's preset cannot be read.
     */
    public function test_get_other_users_preset(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $id = presets::create((int)$owner->id, 'Mine', templates::get('pairs'));

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('presetnotfound', 'mod_fancytimer'));
        presets::get($id, (int)$other->id);
    }

    /**
     * Another user's preset cannot be deleted.
     */
    public function test_delete_other_users_preset(): void {
        global $DB;
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $id = presets::create((int)$owner->id, 'Mine', templates::get('pairs'));

        $this->assertFalse(presets::delete($id, (int)$other->id));
        $this->assertTrue($DB->record_exists('fancytimer_presets', ['id' => $id]));
        $this->assertSame([], presets::for_user((int)$other->id));
    }

    /**
     * A damaged stored config is reported as invalidpreset.
     */
    public function test_get_damaged_config(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $id = presets::create((int)$user->id, 'Mine', templates::get('pairs'));
        $DB->set_field('fancytimer_presets', 'config', '{"format":"other"}', ['id' => $id]);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('invalidpreset', 'mod_fancytimer'));
        presets::get($id, (int)$user->id);
    }

    /**
     * A user may keep at most MAX_PER_USER presets; other users are not affected.
     */
    public function test_max_per_user(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $json = preset_format::to_json(templates::get('single'));
        $rows = [];
        for ($i = 1; $i < presets::MAX_PER_USER; $i++) {
            $rows[] = (object)['userid' => $user->id, 'name' => 'P' . $i, 'config' => $json,
                'timecreated' => 1, 'timemodified' => 1];
        }
        $DB->insert_records('fancytimer_presets', $rows);

        // The 100th is accepted.
        presets::create((int)$user->id, 'Last', templates::get('single'));
        $this->assertSame(presets::MAX_PER_USER, $DB->count_records('fancytimer_presets', ['userid' => $user->id]));
        $this->assertGreaterThan(0, presets::create((int)$other->id, 'Other', templates::get('single')));

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('toomanypresets', 'mod_fancytimer', presets::MAX_PER_USER));
        presets::create((int)$user->id, 'One too many', templates::get('single'));
    }

    /**
     * for_user() lists newest first.
     */
    public function test_for_user_order(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $old = presets::create((int)$user->id, 'Old', templates::get('single'));
        $new = presets::create((int)$user->id, 'New', templates::get('single'));
        $DB->set_field('fancytimer_presets', 'timemodified', 1000, ['id' => $old]);
        $DB->set_field('fancytimer_presets', 'timemodified', 2000, ['id' => $new]);

        $this->assertSame(['New', 'Old'], array_values(array_map(
            fn($p) => $p->name,
            presets::for_user((int)$user->id)
        )));
    }

    /**
     * A preset read for an activity keeps that activity's recording keys only.
     */
    public function test_get_keeps_recordings_of_target_activity(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $timer = $this->getDataGenerator()->create_module('fancytimer', ['course' => $course->id]);
        $rec = $this->getDataGenerator()->get_plugin_generator('mod_fancytimer')->create_recording($timer);
        $first = segments::get_segments((int)$timer->id)[0];
        $DB->set_field('fancytimer_segments', 'sound', 'rec:' . $rec->id, ['id' => $first->id]);
        $timer = $DB->get_record('fancytimer', ['id' => $timer->id], '*', MUST_EXIST);
        $adminid = (int)get_admin()->id;
        $id = presets::create($adminid, 'With recording', preset_format::from_instance($timer, true));

        [, $config] = presets::get($id, $adminid, (int)$timer->id);
        $this->assertSame('rec:' . $rec->id, $config['segments'][0]['sound']);
        [, $config] = presets::get($id, $adminid);
        $this->assertSame('chime', $config['segments'][0]['sound']);
    }
}
