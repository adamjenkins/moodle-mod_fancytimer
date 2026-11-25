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

namespace mod_fancytimer\backup;

use backup;
use backup_controller;
use mod_fancytimer\local\recordings;
use mod_fancytimer\local\segments;
use restore_controller;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/backup/moodle2/backup_plan_builder.class.php');
require_once($CFG->dirroot . '/backup/moodle2/restore_plan_builder.class.php');
require_once($CFG->dirroot . '/mod/fancytimer/backup/moodle2/backup_fancytimer_activity_task.class.php');
require_once($CFG->dirroot . '/mod/fancytimer/backup/moodle2/restore_fancytimer_activity_task.class.php');

/**
 * Backup and restore of a Fancy Timer: settings, segments, rotation, recordings and files.
 *
 * Uses the MODE_GENERAL recipe of core_backup_backup_restore_base_testcase: the backup is zipped and
 * its temp dir removed, so the file is extracted again into $CFG->tempdir/backup/<id> before restoring.
 *
 * @package    mod_fancytimer
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\backup_fancytimer_activity_structure_step::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_fancytimer_activity_structure_step::class)]
final class backup_restore_test extends \advanced_testcase {
    /**
     * Back up a course in MODE_GENERAL and extract the file for restore.
     *
     * @param \stdClass $course
     * @param bool $users Include user data.
     * @return string Backup id.
     */
    private function backup_course(\stdClass $course, bool $users = true): string {
        global $CFG, $USER;
        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $course->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value($users);
        $bc->execute_plan();
        $backupid = $bc->get_backupid();
        $results = $bc->get_results();
        $file = $results['backup_destination'];
        $bc->destroy();

        $path = $CFG->tempdir . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . $backupid;
        get_file_packer('application/vnd.moodle.backup')->extract_to_pathname($file, $path);
        return $backupid;
    }

    /**
     * Restore a backup into an existing (empty) course.
     *
     * @param string $backupid
     * @param \stdClass $course Target course.
     */
    private function restore_into(string $backupid, \stdClass $course): void {
        global $USER;
        $rc = new restore_controller(
            $backupid,
            $course->id,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id,
            backup::TARGET_EXISTING_ADDING
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
    }

    /**
     * A course with one timer: segment 1 has an image, segment 2 plays a recording.
     *
     * @param int $recordinguserid Owner of the recording.
     * @return array [course, timer instance, recording, segments]
     */
    private function make_source(int $recordinguserid): array {
        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['summary' => '']);
        $timer = $generator->create_module('fancytimer', [
            'course' => $course->id,
            'name' => 'Speaking practice',
            'looping' => 1,
            'warningenabled' => 1,
            'warningsecs' => 10,
            'warningsound' => 'pulse',
            'showring' => 1,
        ]);
        $context = \context_module::instance($timer->cmid);
        $recording = recordings::save(
            $context,
            (int)$timer->id,
            $recordinguserid,
            'Next person, please',
            'audio/webm',
            'fake-audio-bytes'
        );
        $list = segments::get_segments((int)$timer->id);
        $DB->set_field('fancytimer_segments', 'sound', 'rec:' . $recording->id, ['id' => $list[1]->id]);
        $DB->set_field('fancytimer', 'startsound', 'rec:' . $recording->id, ['id' => $timer->id]);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_fancytimer',
            'filearea' => 'image',
            'itemid' => $list[0]->id,
            'filepath' => '/',
            'filename' => 'headline.png',
        ], 'not-really-a-png');
        return [$course, $timer, $recording, segments::get_segments((int)$timer->id)];
    }

    /**
     * Full round trip with user data.
     */
    public function test_backup_and_restore(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $timer, $recording, $oldsegments] = $this->make_source((int)$USER->id);
        $oldrotation = segments::get_rotation((int)$timer->id);

        $target = $this->getDataGenerator()->create_course(['summary' => '']);
        $this->restore_into($this->backup_course($course), $target);

        $new = $DB->get_record('fancytimer', ['course' => $target->id], '*', MUST_EXIST);
        $this->assertNotEquals((int)$timer->id, (int)$new->id);
        $newcm = get_coursemodule_from_instance('fancytimer', $new->id, $target->id, false, MUST_EXIST);
        $newcontext = \context_module::instance($newcm->id);

        // Instance settings.
        $this->assertSame('Speaking practice', $new->name);
        $this->assertEquals(1, $new->looping);
        $this->assertEquals(1, $new->rotationenabled);
        $this->assertEquals(1, $new->warningenabled);
        $this->assertEquals(10, $new->warningsecs);
        $this->assertSame('pulse', $new->warningsound);
        $this->assertEquals(1, $new->showring);

        // Segments in order, with the rotation segment.
        $newsegments = segments::get_segments((int)$new->id);
        $this->assertCount(3, $newsegments);
        foreach ($oldsegments as $i => $old) {
            $this->assertSame($old->label, $newsegments[$i]->label);
            $this->assertEquals($old->duration, $newsegments[$i]->duration);
            $this->assertSame($old->bgcolour, $newsegments[$i]->bgcolour);
            $this->assertSame($old->textcolour, $newsegments[$i]->textcolour);
        }
        $this->assertSame('Person 1 is speaking', $newsegments[0]->label);
        $this->assertSame('chime', $newsegments[0]->sound);
        $this->assertSame('chime', $newsegments[2]->sound);
        $newrotation = segments::get_rotation((int)$new->id);
        $this->assertNotNull($newrotation);
        $this->assertSame($oldrotation->label, $newrotation->label);
        $this->assertEquals(30, $newrotation->duration);
        $this->assertSame('bell', $newrotation->sound);
        $this->assertCount(4, segments::get_playlist($new));

        // The recording, and segment 2 pointing at the NEW recording id.
        $newrecordings = array_values(recordings::for_activity((int)$new->id));
        $this->assertCount(1, $newrecordings);
        $newrecording = $newrecordings[0];
        $this->assertNotEquals((int)$recording->id, (int)$newrecording->id);
        $this->assertSame('Next person, please', $newrecording->name);
        $this->assertEquals($USER->id, $newrecording->userid);
        $this->assertSame('rec:' . $newrecording->id, $newsegments[1]->sound);
        $this->assertSame('rec:' . $newrecording->id, $new->startsound);

        // Files land in the new context under the new item ids.
        $fs = get_file_storage();
        $audio = $fs->get_area_files($newcontext->id, 'mod_fancytimer', 'recording', $newrecording->id, 'id', false);
        $this->assertCount(1, $audio);
        $this->assertSame('fake-audio-bytes', reset($audio)->get_content());
        $this->assertNotNull(recordings::url($newcontext, (int)$newrecording->id));

        $images = $fs->get_area_files($newcontext->id, 'mod_fancytimer', 'image', $newsegments[0]->id, 'id', false);
        $this->assertCount(1, $images);
        $this->assertSame('headline.png', reset($images)->get_filename());
        $this->assertSame([], $fs->get_area_files(
            $newcontext->id,
            'mod_fancytimer',
            'image',
            $newsegments[1]->id,
            'id',
            false
        ));

        // The source timer is untouched.
        $this->assertSame('rec:' . $recording->id, segments::get_segments((int)$timer->id)[1]->sound);
    }

    /**
     * Without user data the recordings still come back (they are activity content), owned by the restorer.
     */
    public function test_restore_without_user_data_keeps_recordings(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $teacher = $this->getDataGenerator()->create_user();
        [$course] = $this->make_source((int)$teacher->id);

        $target = $this->getDataGenerator()->create_course(['summary' => '']);
        $this->restore_into($this->backup_course($course, false), $target);

        $new = $DB->get_record('fancytimer', ['course' => $target->id], '*', MUST_EXIST);
        $newrecordings = array_values(recordings::for_activity((int)$new->id));
        $this->assertCount(1, $newrecordings);
        $this->assertEquals($USER->id, $newrecordings[0]->userid);
        $this->assertSame('rec:' . $newrecordings[0]->id, segments::get_segments((int)$new->id)[1]->sound);

        // Remote state and commands are never backed up.
        $this->assertSame(0, $DB->count_records('fancytimer_state', ['fancytimerid' => $new->id]));
        $this->assertSame(0, $DB->count_records('fancytimer_commands', ['fancytimerid' => $new->id]));
    }
}
