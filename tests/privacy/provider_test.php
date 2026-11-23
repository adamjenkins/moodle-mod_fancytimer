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

namespace mod_fancytimer\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_fancytimer\local\presets;
use mod_fancytimer\local\recordings;
use mod_fancytimer\local\remote;
use mod_fancytimer\local\segments;
use mod_fancytimer\local\templates;

/**
 * Tests for the mod_fancytimer privacy provider.
 *
 * Fixture: one timer (threeperson template) and four users, each holding one kind of data:
 * a recording (used by segment 2), a remote command, the published state, a personal preset.
 *
 * @package    mod_fancytimer
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass The timer instance (with cmid). */
    private \stdClass $timer;

    /** @var \context_module Its module context. */
    private \context_module $context;

    /** @var \stdClass User who made a recording. */
    private \stdClass $recorder;

    /** @var \stdClass User who sent a remote command. */
    private \stdClass $commander;

    /** @var \stdClass User whose page published the state. */
    private \stdClass $publisher;

    /** @var \stdClass User who owns a preset. */
    private \stdClass $presetowner;

    /** @var \stdClass The recording row. */
    private \stdClass $recording;

    /** @var int The preset id. */
    private int $presetid;

    /**
     * Build the fixture.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['summary' => '']);
        $this->timer = $generator->create_module('fancytimer', ['course' => $course->id, 'name' => 'Speaking practice']);
        $this->context = \context_module::instance($this->timer->cmid);

        $this->recorder = $generator->create_user();
        $this->commander = $generator->create_user();
        $this->publisher = $generator->create_user();
        $this->presetowner = $generator->create_user();
        foreach ([$this->recorder, $this->commander, $this->publisher] as $user) {
            $generator->enrol_user($user->id, $course->id, 'editingteacher');
        }

        $this->recording = recordings::save(
            $this->context,
            (int)$this->timer->id,
            (int)$this->recorder->id,
            'Next person, please',
            'audio/webm',
            'fake-audio-bytes'
        );
        $list = segments::get_segments((int)$this->timer->id);
        $DB->set_field('fancytimer_segments', 'sound', 'rec:' . $this->recording->id, ['id' => $list[1]->id]);

        remote::queue_command((int)$this->timer->id, (int)$this->commander->id, 'next', 0);
        remote::publish_state(
            (int)$this->timer->id,
            (int)$this->publisher->id,
            '{"status":"running","index":1,"remainingms":5000,"durationms":120000,"cycle":0}'
        );

        $this->presetid = presets::create((int)$this->presetowner->id, 'My pair talk', templates::get('pairs'));
    }

    /**
     * Context ids of a user, as ints (contextlist ids are strings).
     *
     * @param \stdClass $user
     * @return int[]
     */
    private function context_ids_for(\stdClass $user): array {
        $ids = array_map('intval', provider::get_contexts_for_userid((int)$user->id)->get_contextids());
        sort($ids);
        return $ids;
    }

    /**
     * The recording files of the timer.
     *
     * @return \stored_file[]
     */
    private function recording_files(): array {
        return get_file_storage()->get_area_files($this->context->id, 'mod_fancytimer', 'recording', false, 'id', false);
    }

    /**
     * Sound key of segment 2.
     *
     * @return string
     */
    private function segment2_sound(): string {
        return (string)segments::get_segments((int)$this->timer->id)[1]->sound;
    }

    /**
     * The metadata names the four user-data tables and the files subsystem.
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new collection('mod_fancytimer'));
        $names = array_map(fn($item) => $item->get_name(), $collection->get_collection());
        $expected = ['fancytimer_recordings', 'fancytimer_presets', 'fancytimer_commands', 'fancytimer_state', 'core_files'];
        foreach ($expected as $name) {
            $this->assertContains($name, $names);
        }
        $this->assertCount(5, $names);
    }

    /**
     * Each kind of data yields the right context: module context for recordings, commands and state,
     * the user context for presets.
     */
    public function test_get_contexts_for_userid(): void {
        $moduleid = (int)$this->context->id;
        $this->assertSame([$moduleid], $this->context_ids_for($this->recorder));
        $this->assertSame([$moduleid], $this->context_ids_for($this->commander));
        $this->assertSame([$moduleid], $this->context_ids_for($this->publisher));
        $usercontextid = (int)\context_user::instance($this->presetowner->id)->id;
        $this->assertSame([$usercontextid], $this->context_ids_for($this->presetowner));

        $nobody = $this->getDataGenerator()->create_user();
        $this->assertSame([], $this->context_ids_for($nobody));
    }

    /**
     * Users with data in the module context and in a user context.
     */
    public function test_get_users_in_context(): void {
        $userlist = new userlist($this->context, 'mod_fancytimer');
        provider::get_users_in_context($userlist);
        $ids = array_map('intval', $userlist->get_userids());
        sort($ids);
        $expected = [(int)$this->recorder->id, (int)$this->commander->id, (int)$this->publisher->id];
        sort($expected);
        $this->assertSame($expected, $ids);

        $userlist = new userlist(\context_user::instance($this->presetowner->id), 'mod_fancytimer');
        provider::get_users_in_context($userlist);
        $this->assertSame([(int)$this->presetowner->id], array_map('intval', $userlist->get_userids()));

        $userlist = new userlist(\context_user::instance($this->recorder->id), 'mod_fancytimer');
        provider::get_users_in_context($userlist);
        $this->assertSame([], $userlist->get_userids());
    }

    /**
     * The recorder's export holds the recording list and its audio file.
     */
    public function test_export_recordings_with_files(): void {
        $this->export_context_data_for_user((int)$this->recorder->id, $this->context, 'mod_fancytimer');
        $writer = writer::with_context($this->context);
        $this->assertTrue($writer->has_any_data());

        $path = [get_string('privacy:path:recordings', 'mod_fancytimer')];
        $data = $writer->get_data($path);
        $this->assertCount(1, $data->recordings);
        $this->assertSame((int)$this->recording->id, $data->recordings[0]->id);
        $this->assertSame('Next person, please', $data->recordings[0]->name);

        $files = $writer->get_files($path);
        $filename = 'recording-' . $this->recording->id . '.webm';
        $this->assertArrayHasKey($filename, $files);
        $this->assertSame('fake-audio-bytes', $files[$filename]->get_content());
    }

    /**
     * Commands and the published state are exported with the activity's data.
     */
    public function test_export_commands_and_state(): void {
        $this->export_context_data_for_user((int)$this->commander->id, $this->context, 'mod_fancytimer');
        $data = writer::with_context($this->context)->get_data([]);
        $this->assertCount(1, $data->commands);
        $this->assertSame('next', $data->commands[0]->command);
        $this->assertObjectNotHasProperty('publishedstate', $data);

        writer::reset();
        $this->export_context_data_for_user((int)$this->publisher->id, $this->context, 'mod_fancytimer');
        $data = writer::with_context($this->context)->get_data([]);
        $this->assertSame([], $data->commands);
        $this->assertSame('running', $data->publishedstate->state->status);
        $this->assertSame(1, $data->publishedstate->state->index);
    }

    /**
     * Presets are exported in the owner's user context with their config decoded.
     */
    public function test_export_presets_decoded(): void {
        $usercontext = \context_user::instance($this->presetowner->id);
        $this->export_context_data_for_user((int)$this->presetowner->id, $usercontext, 'mod_fancytimer');
        $data = writer::with_context($usercontext)->get_data([
            get_string('pluginname', 'mod_fancytimer'),
            get_string('presets', 'mod_fancytimer'),
        ]);
        $this->assertCount(1, $data->presets);
        $preset = $data->presets[0];
        $this->assertSame('My pair talk', $preset->name);
        $this->assertIsObject($preset->config);
        $this->assertSame('mod_fancytimer-preset', $preset->config->format);
        $this->assertCount(2, $preset->config->segments);

        // Another user's export of the same user context holds nothing.
        writer::reset();
        $this->export_context_data_for_user((int)$this->recorder->id, $usercontext, 'mod_fancytimer');
        $this->assertFalse(writer::with_context($usercontext)->has_any_data());
    }

    /**
     * Deleting everyone in the module context removes recordings (and files), commands and state, and
     * the segment that used the recording falls back to the chime. Presets stay.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $this->assertSame('rec:' . $this->recording->id, $this->segment2_sound());
        $this->assertCount(1, $this->recording_files());

        provider::delete_data_for_all_users_in_context($this->context);

        $timerid = (int)$this->timer->id;
        $this->assertSame(0, $DB->count_records('fancytimer_recordings', ['fancytimerid' => $timerid]));
        $this->assertSame(0, $DB->count_records('fancytimer_commands', ['fancytimerid' => $timerid]));
        $this->assertSame(0, $DB->count_records('fancytimer_state', ['fancytimerid' => $timerid]));
        $this->assertSame([], $this->recording_files());
        $this->assertSame('chime', $this->segment2_sound());
        $this->assertTrue($DB->record_exists('fancytimer_presets', ['id' => $this->presetid]));

        provider::delete_data_for_all_users_in_context(\context_user::instance($this->presetowner->id));
        $this->assertFalse($DB->record_exists('fancytimer_presets', ['id' => $this->presetid]));
    }

    /**
     * Deleting one user's data leaves everyone else's.
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        $timerid = (int)$this->timer->id;

        $contextlist = new approved_contextlist($this->recorder, 'mod_fancytimer', [$this->context->id]);
        provider::delete_data_for_user($contextlist);
        $this->assertSame(0, $DB->count_records('fancytimer_recordings', ['fancytimerid' => $timerid]));
        $this->assertSame([], $this->recording_files());
        $this->assertSame('chime', $this->segment2_sound());
        $this->assertSame(1, $DB->count_records('fancytimer_commands', ['fancytimerid' => $timerid]));
        $this->assertSame(1, $DB->count_records('fancytimer_state', ['fancytimerid' => $timerid]));

        // A user context that is not the user's own is left alone.
        $othercontext = \context_user::instance($this->presetowner->id);
        provider::delete_data_for_user(new approved_contextlist($this->recorder, 'mod_fancytimer', [$othercontext->id]));
        $this->assertTrue($DB->record_exists('fancytimer_presets', ['id' => $this->presetid]));

        $contextlist = new approved_contextlist($this->presetowner, 'mod_fancytimer', [$othercontext->id]);
        provider::delete_data_for_user($contextlist);
        $this->assertFalse($DB->record_exists('fancytimer_presets', ['id' => $this->presetid]));
        $this->assertSame(1, $DB->count_records('fancytimer_commands', ['fancytimerid' => $timerid]));
    }

    /**
     * Deleting a list of users in the module context removes only theirs.
     */
    public function test_delete_data_for_users(): void {
        global $DB;
        $timerid = (int)$this->timer->id;

        $userids = [(int)$this->recorder->id, (int)$this->commander->id];
        $userlist = new approved_userlist($this->context, 'mod_fancytimer', $userids);
        provider::delete_data_for_users($userlist);

        $this->assertSame(0, $DB->count_records('fancytimer_recordings', ['fancytimerid' => $timerid]));
        $this->assertSame(0, $DB->count_records('fancytimer_commands', ['fancytimerid' => $timerid]));
        $this->assertSame(1, $DB->count_records('fancytimer_state', ['fancytimerid' => $timerid]));
        $this->assertSame([], $this->recording_files());
        $this->assertSame('chime', $this->segment2_sound());

        // In a user context only the owner's own id deletes the presets.
        $usercontext = \context_user::instance($this->presetowner->id);
        provider::delete_data_for_users(new approved_userlist($usercontext, 'mod_fancytimer', [(int)$this->recorder->id]));
        $this->assertTrue($DB->record_exists('fancytimer_presets', ['id' => $this->presetid]));
        $userlist = new approved_userlist($usercontext, 'mod_fancytimer', [(int)$this->presetowner->id]);
        provider::delete_data_for_users($userlist);
        $this->assertFalse($DB->record_exists('fancytimer_presets', ['id' => $this->presetid]));
    }
}
