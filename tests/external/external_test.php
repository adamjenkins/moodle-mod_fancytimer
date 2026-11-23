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

namespace mod_fancytimer\external;

use core_external\external_api;
use mod_fancytimer\local\recordings;
use mod_fancytimer\local\remote;

/**
 * Tests for the five web services of mod_fancytimer.
 *
 * @package    mod_fancytimer
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(send_command::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(get_updates::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(publish_state::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(save_recording::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(delete_recording::class)]
final class external_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Timer under test (with cmid). */
    private \stdClass $timer;

    /** @var \stdClass Second timer in the same course. */
    private \stdClass $othertimer;

    /** @var \stdClass A label in the same course. */
    private \stdClass $label;

    /** @var \stdClass Editing teacher: control + manageactivities. */
    private \stdClass $editor;

    /** @var \stdClass Non-editing teacher: control, no manageactivities. */
    private \stdClass $noneditor;

    /** @var \stdClass Student: view only. */
    private \stdClass $student;

    /**
     * Course, users and two timers.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['summary' => '']);
        $this->editor = $generator->create_and_enrol($this->course, 'editingteacher');
        $this->noneditor = $generator->create_and_enrol($this->course, 'teacher');
        $this->student = $generator->create_and_enrol($this->course, 'student');
        $this->timer = $generator->create_module('fancytimer', ['course' => $this->course->id]);
        $this->othertimer = $generator->create_module('fancytimer', ['course' => $this->course->id]);
        $this->label = $generator->create_module('label', ['course' => $this->course->id]);
    }

    /**
     * Call send_command as the web service layer would.
     *
     * @param int $cmid
     * @param string $command
     * @param int $value
     * @return array
     */
    private function send(int $cmid, string $command, int $value = 0): array {
        $result = send_command::execute($cmid, $command, $value);
        return external_api::clean_returnvalue(send_command::execute_returns(), $result);
    }

    /**
     * Call get_updates as the web service layer would.
     *
     * @param int $cmid
     * @param int $since
     * @return array
     */
    private function updates(int $cmid, int $since): array {
        $result = get_updates::execute($cmid, $since);
        return external_api::clean_returnvalue(get_updates::execute_returns(), $result);
    }

    /**
     * Call publish_state as the web service layer would.
     *
     * @param int $cmid
     * @param string $state
     * @return array
     */
    private function publish(int $cmid, string $state): array {
        $result = publish_state::execute($cmid, $state);
        return external_api::clean_returnvalue(publish_state::execute_returns(), $result);
    }

    /**
     * Call save_recording as the web service layer would.
     *
     * @param int $cmid
     * @param string $audio Base64.
     * @param string $mimetype
     * @param string $name
     * @return array
     */
    private function save(int $cmid, string $audio, string $mimetype = 'audio/webm;codecs=opus', string $name = 'Swap'): array {
        $result = save_recording::execute($cmid, $name, $mimetype, $audio);
        return external_api::clean_returnvalue(save_recording::execute_returns(), $result);
    }

    /**
     * Call delete_recording as the web service layer would.
     *
     * @param int $cmid
     * @param int $recordingid
     * @return array
     */
    private function delete(int $cmid, int $recordingid): array {
        $result = delete_recording::execute($cmid, $recordingid);
        return external_api::clean_returnvalue(delete_recording::execute_returns(), $result);
    }

    /**
     * Assert that a callable throws a moodle_exception with this error code.
     *
     * @param string $errorcode
     * @param callable $fn
     * @param string|null $debuginfo Expected substring of the debug info.
     */
    private function assert_error(string $errorcode, callable $fn, ?string $debuginfo = null): void {
        try {
            $fn();
            $this->fail("Expected the error '$errorcode'");
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode, $e->getMessage() . ' ' . $e->debuginfo);
            if ($debuginfo !== null) {
                $this->assertStringContainsString($debuginfo, (string)$e->debuginfo);
            }
        }
    }

    /**
     * A controller queues commands; addtime is clamped.
     */
    public function test_send_command(): void {
        global $DB;
        $this->setUser($this->noneditor);
        $result = $this->send((int)$this->timer->cmid, 'addtime', 9999);
        $row = $DB->get_record('fancytimer_commands', ['id' => $result['id']], '*', MUST_EXIST);
        $this->assertSame('addtime', $row->command);
        $this->assertEquals(remote::MAX_ADDTIME, $row->value);
        $this->assertEquals($this->timer->id, $row->fancytimerid);
        $this->assertEquals($this->noneditor->id, $row->userid);

        $result = send_command::execute((int)$this->timer->cmid, 'next');
        $this->assertEquals(0, $DB->get_field('fancytimer_commands', 'value', ['id' => $result['id']]));
    }

    /**
     * A student cannot send commands.
     */
    public function test_send_command_needs_control(): void {
        $this->setUser($this->student);
        $this->expectException(\required_capability_exception::class);
        $this->send((int)$this->timer->cmid, 'start');
    }

    /**
     * An unknown command is an invalid parameter, and nothing is queued.
     */
    public function test_send_command_bad_command(): void {
        global $DB;
        $this->setUser($this->editor);
        $this->assert_error('invalidparameter', fn() => $this->send((int)$this->timer->cmid, 'explode'), 'Unknown command');
        // Not even PARAM_ALPHA.
        $this->assert_error('invalidparameter', fn() => $this->send((int)$this->timer->cmid, 'add-time'));
        $this->assert_error('invalidparameter', fn() => $this->send((int)$this->timer->cmid, ''));
        $this->assertSame(0, $DB->count_records('fancytimer_commands', ['fancytimerid' => $this->timer->id]));
    }

    /**
     * A cmid of another module type is refused by every function.
     */
    public function test_other_module_type(): void {
        $this->setAdminUser();
        $cmid = (int)$this->label->cmid;
        $this->assert_error('invalidcoursemoduleid', fn() => $this->send($cmid, 'start'));
        $this->assert_error('invalidcoursemoduleid', fn() => $this->updates($cmid, 0));
        $this->assert_error('invalidcoursemoduleid', fn() => $this->publish($cmid, '{"status":"idle"}'));
        $this->assert_error('invalidcoursemoduleid', fn() => $this->save($cmid, base64_encode('x')));
        $this->assert_error('invalidcoursemoduleid', fn() => $this->delete($cmid, 1));
    }

    /**
     * Commands go only to control holders and never on the initial sync.
     */
    public function test_get_updates_commands(): void {
        $this->setUser($this->editor);
        $first = $this->send((int)$this->timer->cmid, 'start')['id'];
        $second = $this->send((int)$this->timer->cmid, 'addtime', 30)['id'];
        $this->send((int)$this->othertimer->cmid, 'reset');

        // Initial sync: no commands, only the id to continue from.
        $sync = $this->updates((int)$this->timer->cmid, 0);
        $this->assertSame([], $sync['commands']);
        $this->assertSame($second, $sync['lastcommandid']);
        $this->assertSame('', $sync['state']);
        $this->assertSame(-1, $sync['agems']);

        $this->setUser($this->noneditor);
        $got = $this->updates((int)$this->timer->cmid, $first);
        $this->assertSame([['id' => $second, 'command' => 'addtime', 'value' => 30]], $got['commands']);
        $this->assertSame($second, $got['lastcommandid']);

        $got = $this->updates((int)$this->timer->cmid, $first - 1);
        $this->assertSame([$first, $second], array_column($got['commands'], 'id'));

        $this->assertSame([], $this->updates((int)$this->timer->cmid, $second)['commands']);

        // A student sees no commands at all.
        $this->setUser($this->student);
        $got = $this->updates((int)$this->timer->cmid, $first - 1);
        $this->assertSame([], $got['commands']);
        $this->assertSame([], $this->updates((int)$this->timer->cmid, 0)['commands']);
    }

    /**
     * Published state reaches students (mirrors), normalised, with an age.
     */
    public function test_publish_state_and_mirror(): void {
        global $DB;
        $this->setUser($this->noneditor);
        $this->assertTrue($this->publish(
            (int)$this->timer->cmid,
            '{"status":"running","index":2,"remainingms":5000,"durationms":120000,"cycle":1,"x":"<b>"}'
        )['ok']);
        $row = $DB->get_record('fancytimer_state', ['fancytimerid' => $this->timer->id], '*', MUST_EXIST);
        $this->assertEquals($this->noneditor->id, $row->userid);

        $this->setUser($this->student);
        $got = $this->updates((int)$this->timer->cmid, 0);
        $this->assertSame(['status' => 'running', 'index' => 2, 'remainingms' => 5000, 'durationms' => 120000,
            'cycle' => 1], json_decode($got['state'], true));
        $this->assertGreaterThanOrEqual(0, $got['agems']);

        // The other timer has no state.
        $this->assertSame(-1, $this->updates((int)$this->othertimer->cmid, 0)['agems']);
    }

    /**
     * Invalid states are refused and the stored state is kept.
     */
    public function test_publish_state_rejects_invalid(): void {
        global $DB;
        $this->setUser($this->editor);
        $this->publish((int)$this->timer->cmid, '{"status":"paused"}');

        $cmid = (int)$this->timer->cmid;
        $this->assert_error('invalidparameter', fn() => $this->publish($cmid, '{"status": "paused"'), 'Invalid state');
        $this->assert_error('invalidparameter', fn() => $this->publish($cmid, '{"status":"dancing"}'), 'Invalid state');
        $this->assert_error('invalidparameter', fn() => $this->publish(
            $cmid,
            json_encode(['status' => 'running', 'pad' => str_repeat('x', remote::STATE_MAX_BYTES)])
        ), 'Invalid state');

        $state = $DB->get_field('fancytimer_state', 'state', ['fancytimerid' => $this->timer->id]);
        $this->assertSame('paused', json_decode($state, true)['status']);
    }

    /**
     * A student cannot publish state.
     */
    public function test_publish_state_needs_control(): void {
        $this->setUser($this->student);
        $this->expectException(\required_capability_exception::class);
        $this->publish((int)$this->timer->cmid, '{"status":"idle"}');
    }

    /**
     * An editing teacher saves a recording and gets its sound key and URL.
     */
    public function test_save_recording(): void {
        global $DB;
        $this->setUser($this->editor);
        $result = $this->save((int)$this->timer->cmid, base64_encode('opus-bytes'), 'audio/webm;codecs=opus', 'Next one');

        $this->assertSame('Next one', $result['name']);
        $this->assertSame('rec:' . $result['id'], $result['soundkey']);
        $this->assertStringContainsString(
            '/mod_fancytimer/recording/' . $result['id'] . '/recording-' . $result['id'] . '.webm',
            $result['url']
        );
        $record = $DB->get_record('fancytimer_recordings', ['id' => $result['id']], '*', MUST_EXIST);
        $this->assertEquals($this->timer->id, $record->fancytimerid);
        $this->assertEquals($this->editor->id, $record->userid);
        $context = \context_module::instance($this->timer->cmid);
        $files = get_file_storage()->get_area_files($context->id, 'mod_fancytimer', 'recording', $result['id'], 'id', false);
        $this->assertSame('opus-bytes', reset($files)->get_content());

        // An empty name is shown as "Recording N".
        $result = $this->save((int)$this->timer->cmid, base64_encode('x'), 'audio/ogg', '   ');
        $this->assertSame(get_string('recordingunnamed', 'mod_fancytimer', $result['id']), $result['name']);
    }

    /**
     * Audio must be strict base64 of an accepted type.
     */
    public function test_save_recording_rejects_bad_audio(): void {
        global $DB;
        $this->setUser($this->editor);
        $cmid = (int)$this->timer->cmid;
        $this->assert_error('recordinginvalid', fn() => $this->save($cmid, 'not*base64!'));
        $this->assert_error('recordinginvalid', fn() => $this->save($cmid, 'data:audio/webm;base64,' . base64_encode('x')));
        $this->assert_error('recordinginvalid', fn() => $this->save($cmid, ''));
        $this->assert_error('recordinginvalid', fn() => $this->save($cmid, base64_encode('x'), 'text/html'));
        $this->assertSame(0, $DB->count_records('fancytimer_recordings', ['fancytimerid' => $this->timer->id]));
    }

    /**
     * Oversized audio is refused before decoding; just-too-large decoded audio is refused by the store.
     */
    public function test_save_recording_size(): void {
        global $DB;
        $this->setUser($this->editor);
        $cmid = (int)$this->timer->cmid;
        $limit = (int)ceil(recordings::MAX_BYTES * 4 / 3) + 4;

        // Not valid base64 at all, but too long: the size error proves the check runs before decoding.
        $this->assert_error('recordingtoolarge', fn() => $this->save($cmid, str_repeat('!', $limit + 1)));

        // Valid base64 under the encoded limit whose decoded size is one byte over MAX_BYTES.
        $encoded = base64_encode(str_repeat('a', recordings::MAX_BYTES + 1));
        $this->assertLessThanOrEqual($limit, strlen($encoded));
        $this->assert_error('recordingtoolarge', fn() => $this->save($cmid, $encoded));

        $this->assertSame(0, $DB->count_records('fancytimer_recordings', ['fancytimerid' => $this->timer->id]));
    }

    /**
     * Saving a recording needs moodle/course:manageactivities: control alone is not enough.
     */
    public function test_save_recording_needs_manageactivities(): void {
        $this->setUser($this->noneditor);
        $this->expectException(\required_capability_exception::class);
        $this->expectExceptionMessage(get_capability_string('moodle/course:manageactivities'));
        $this->save((int)$this->timer->cmid, base64_encode('x'));
    }

    /**
     * Deleting a recording of this timer works and resets segments using it.
     */
    public function test_delete_recording(): void {
        global $DB;
        $this->setUser($this->editor);
        $saved = $this->save((int)$this->timer->cmid, base64_encode('bye'));
        $segment = \mod_fancytimer\local\segments::get_segments((int)$this->timer->id)[0];
        $DB->set_field('fancytimer_segments', 'sound', $saved['soundkey'], ['id' => $segment->id]);

        $this->assertTrue($this->delete((int)$this->timer->cmid, $saved['id'])['ok']);

        $this->assertFalse($DB->record_exists('fancytimer_recordings', ['id' => $saved['id']]));
        $this->assertSame('chime', $DB->get_field('fancytimer_segments', 'sound', ['id' => $segment->id]));
        $context = \context_module::instance($this->timer->cmid);
        $this->assertTrue(get_file_storage()->is_area_empty($context->id, 'mod_fancytimer', 'recording', $saved['id']));

        $this->assert_error('recordingnotfound', fn() => $this->delete((int)$this->timer->cmid, $saved['id']));
    }

    /**
     * A recording of another activity cannot be deleted through this one.
     */
    public function test_delete_recording_of_other_activity(): void {
        global $DB;
        $this->setUser($this->editor);
        $foreign = $this->save((int)$this->othertimer->cmid, base64_encode('theirs'));

        $this->assert_error('recordingnotfound', fn() => $this->delete((int)$this->timer->cmid, $foreign['id']));

        $this->assertTrue($DB->record_exists('fancytimer_recordings', ['id' => $foreign['id']]));
        $context = \context_module::instance($this->othertimer->cmid);
        $this->assertFalse(get_file_storage()->is_area_empty($context->id, 'mod_fancytimer', 'recording', $foreign['id']));
    }

    /**
     * Deleting a recording needs moodle/course:manageactivities.
     */
    public function test_delete_recording_needs_manageactivities(): void {
        global $DB;
        $this->setUser($this->editor);
        $saved = $this->save((int)$this->timer->cmid, base64_encode('keep'));

        $this->setUser($this->noneditor);
        try {
            $this->delete((int)$this->timer->cmid, $saved['id']);
            $this->fail('Expected required_capability_exception');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        $this->assertTrue($DB->record_exists('fancytimer_recordings', ['id' => $saved['id']]));
    }

    /**
     * Users not enrolled in the course are refused by validate_context().
     */
    public function test_not_enrolled(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\require_login_exception::class);
        $this->updates((int)$this->timer->cmid, 0);
    }
}
