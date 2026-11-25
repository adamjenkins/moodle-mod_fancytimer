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
 * Tests for teacher recordings.
 *
 * @package    mod_fancytimer
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(recordings::class)]
final class recordings_test extends \advanced_testcase {
    /** @var \stdClass Timer under test. */
    private \stdClass $timer;

    /** @var \context_module Its context. */
    private \context_module $context;

    /** @var \stdClass A teacher. */
    private \stdClass $teacher;

    /**
     * Make a timer.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $this->teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->timer = $this->getDataGenerator()->create_module('fancytimer', ['course' => $course->id]);
        $this->context = \context_module::instance($this->timer->cmid);
    }

    /**
     * Save a recording into the test timer.
     *
     * @param string $mimetype
     * @param string $bytes
     * @param string $name
     * @return \stdClass
     */
    private function save(string $mimetype = 'audio/webm', string $bytes = 'audio-bytes', string $name = 'Next!'): \stdClass {
        return recordings::save($this->context, (int)$this->timer->id, (int)$this->teacher->id, $name, $mimetype, $bytes);
    }

    /**
     * A saved recording has a row and exactly one file, typed by its container.
     */
    public function test_save(): void {
        global $DB;
        $record = $this->save('audio/webm;codecs=opus', 'abc', '  <b>Change</b> now ');

        $stored = $DB->get_record('fancytimer_recordings', ['id' => $record->id], '*', MUST_EXIST);
        $this->assertSame('Change now', $stored->name);
        $this->assertEquals($this->teacher->id, $stored->userid);
        $this->assertEquals($this->timer->id, $stored->fancytimerid);

        $files = get_file_storage()->get_area_files(
            $this->context->id,
            'mod_fancytimer',
            'recording',
            $record->id,
            'id',
            false
        );
        $this->assertCount(1, $files);
        $file = reset($files);
        $this->assertSame('recording-' . $record->id . '.webm', $file->get_filename());
        $this->assertSame('audio/webm', $file->get_mimetype());
        $this->assertSame('abc', $file->get_content());
        $this->assertEquals($this->teacher->id, $file->get_userid());

        $url = recordings::url($this->context, (int)$record->id);
        $this->assertStringContainsString(
            '/mod_fancytimer/recording/' . $record->id . '/recording-' . $record->id . '.webm',
            $url->out(false)
        );
        $this->assertArrayHasKey($record->id, recordings::for_activity((int)$this->timer->id));
    }

    /**
     * Each accepted mime type gets its extension.
     */
    public function test_save_extensions(): void {
        foreach (recordings::MIMETYPES as $type => $ext) {
            $record = $this->save(strtoupper($type), 'x');
            $url = recordings::url($this->context, (int)$record->id)->out(false);
            $this->assertStringEndsWith('recording-' . $record->id . '.' . $ext, $url);
        }
    }

    /**
     * Data for rejected recordings.
     *
     * @return array
     */
    public static function invalid_provider(): array {
        return [
            'video' => ['video/webm', 'x'],
            'svg' => ['image/svg+xml', 'x'],
            'html' => ['text/html', '<script>'],
            'empty type' => ['', 'x'],
            'no audio' => ['audio/webm', ''],
        ];
    }

    /**
     * A bad mime type or empty audio is recordinginvalid, and nothing is stored.
     *
     * @param string $mimetype
     * @param string $bytes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_provider')]
    public function test_save_rejects_invalid(string $mimetype, string $bytes): void {
        global $DB;
        try {
            $this->save($mimetype, $bytes);
            $this->fail('Expected recordinginvalid');
        } catch (\moodle_exception $e) {
            $this->assertSame('recordinginvalid', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('fancytimer_recordings', ['fancytimerid' => $this->timer->id]));
        $this->assertTrue(get_file_storage()->is_area_empty($this->context->id, 'mod_fancytimer', 'recording', false));
    }

    /**
     * MAX_BYTES is accepted, one byte more is recordingtoolarge.
     */
    public function test_save_rejects_oversize(): void {
        global $DB;
        $this->save('audio/ogg', str_repeat('a', recordings::MAX_BYTES));
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('recordingtoolarge', 'mod_fancytimer', display_size(recordings::MAX_BYTES)));
        try {
            $this->save('audio/ogg', str_repeat('a', recordings::MAX_BYTES + 1));
        } finally {
            $this->assertSame(1, $DB->count_records('fancytimer_recordings', ['fancytimerid' => $this->timer->id]));
        }
    }

    /**
     * The 31st recording of an activity is refused; another activity still has room.
     */
    public function test_save_rejects_too_many(): void {
        global $DB;
        for ($i = 0; $i < recordings::MAX_PER_ACTIVITY; $i++) {
            $this->save('audio/webm', 'r' . $i, 'R' . $i);
        }
        $this->assertCount(recordings::MAX_PER_ACTIVITY, recordings::for_activity((int)$this->timer->id));

        $other = $this->getDataGenerator()->create_module('fancytimer', ['course' => $this->timer->course]);
        $this->assertNotEmpty(recordings::save(
            \context_module::instance($other->cmid),
            (int)$other->id,
            (int)$this->teacher->id,
            'Other',
            'audio/webm',
            'x'
        )->id);

        try {
            $this->save('audio/webm', 'one too many');
            $this->fail('Expected toomanyrecordings');
        } catch (\moodle_exception $e) {
            $this->assertSame('toomanyrecordings', $e->errorcode);
            $this->assertSame(
                get_string('toomanyrecordings', 'mod_fancytimer', recordings::MAX_PER_ACTIVITY),
                $e->getMessage()
            );
        }
        $this->assertSame(
            recordings::MAX_PER_ACTIVITY,
            $DB->count_records('fancytimer_recordings', ['fancytimerid' => $this->timer->id])
        );
    }

    /**
     * The other names browsers give uploaded files' types are stored as the accepted type.
     */
    public function test_save_type_aliases(): void {
        foreach (['audio/x-wav' => 'wav', 'audio/mp3' => 'mp3', 'audio/x-m4a' => 'm4a'] as $type => $extension) {
            $record = $this->save($type, 'bytes');
            $url = recordings::url($this->context, (int)$record->id);
            $this->assertStringEndsWith('recording-' . $record->id . '.' . $extension, $url->out(false));
        }
    }

    /**
     * Deleting a recording resets the segments using it to chime and removes its file.
     */
    public function test_delete(): void {
        global $DB;
        $gone = $this->save('audio/webm', 'gone');
        $kept = $this->save('audio/webm', 'kept');
        [$first, $second, $third] = segments::get_segments((int)$this->timer->id);
        $rotation = segments::get_rotation((int)$this->timer->id);
        $DB->set_field('fancytimer_segments', 'sound', 'rec:' . $gone->id, ['id' => $first->id]);
        $DB->set_field('fancytimer_segments', 'sound', 'rec:' . $gone->id, ['id' => $rotation->id]);
        $DB->set_field('fancytimer_segments', 'sound', 'rec:' . $kept->id, ['id' => $second->id]);
        $DB->set_field('fancytimer_segments', 'sound', 'klaxon', ['id' => $third->id]);

        $DB->set_field('fancytimer', 'startsound', 'rec:' . $gone->id, ['id' => $this->timer->id]);

        recordings::delete($this->context, $gone);

        $this->assertSame('none', $DB->get_field('fancytimer', 'startsound', ['id' => $this->timer->id]));
        $this->assertFalse($DB->record_exists('fancytimer_recordings', ['id' => $gone->id]));
        $this->assertTrue(get_file_storage()->is_area_empty($this->context->id, 'mod_fancytimer', 'recording', $gone->id));
        $this->assertNull(recordings::url($this->context, (int)$gone->id));
        $this->assertSame('chime', $DB->get_field('fancytimer_segments', 'sound', ['id' => $first->id]));
        $this->assertSame('chime', $DB->get_field('fancytimer_segments', 'sound', ['id' => $rotation->id]));
        $this->assertSame('rec:' . $kept->id, $DB->get_field('fancytimer_segments', 'sound', ['id' => $second->id]));
        $this->assertSame('klaxon', $DB->get_field('fancytimer_segments', 'sound', ['id' => $third->id]));
        $this->assertNotNull(recordings::url($this->context, (int)$kept->id));
    }

    /**
     * for_activity() lists only that activity's recordings, oldest first.
     */
    public function test_for_activity(): void {
        $a = $this->save('audio/webm', 'a');
        $b = $this->save('audio/webm', 'b');
        $other = $this->getDataGenerator()->create_module('fancytimer', ['course' => $this->timer->course]);
        recordings::save(
            \context_module::instance($other->cmid),
            (int)$other->id,
            (int)$this->teacher->id,
            'Other',
            'audio/webm',
            'x'
        );

        $this->assertSame(
            [(int)$a->id, (int)$b->id],
            array_map('intval', array_keys(recordings::for_activity((int)$this->timer->id)))
        );
    }
}
