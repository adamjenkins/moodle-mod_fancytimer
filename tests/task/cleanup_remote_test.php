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

namespace mod_fancytimer\task;

use mod_fancytimer\local\remote;

/**
 * Tests for the remote command cleanup task.
 *
 * @package    mod_fancytimer
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(cleanup_remote::class)]
final class cleanup_remote_test extends \advanced_testcase {
    /**
     * Commands older than the TTL go; newer ones and the published state stay.
     */
    public function test_execute_deletes_old_commands_only(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $timer = $this->getDataGenerator()->create_module('fancytimer', ['course' => $course->id]);
        $timerid = (int)$timer->id;

        $old = remote::queue_command($timerid, (int)$user->id, 'next', 0);
        $DB->set_field('fancytimer_commands', 'timecreated', time() - remote::COMMAND_TTL - 60, ['id' => $old]);
        $recent = remote::queue_command($timerid, (int)$user->id, 'addtime', 30);
        $DB->set_field('fancytimer_commands', 'timecreated', time() - remote::COMMAND_TTL + 600, ['id' => $recent]);
        $fresh = remote::queue_command($timerid, (int)$user->id, 'pause', 0);
        remote::publish_state($timerid, (int)$user->id, '{"status":"paused","index":0}');

        $task = new cleanup_remote();
        $this->assertSame(get_string('taskcleanupremote', 'mod_fancytimer'), $task->get_name());
        $task->execute();

        $this->assertFalse($DB->record_exists('fancytimer_commands', ['id' => $old]));
        $this->assertTrue($DB->record_exists('fancytimer_commands', ['id' => $recent]));
        $this->assertTrue($DB->record_exists('fancytimer_commands', ['id' => $fresh]));
        $this->assertSame(1, $DB->count_records('fancytimer_state', ['fancytimerid' => $timerid]));
    }

    /**
     * The task is registered as a scheduled task.
     */
    public function test_task_is_scheduled(): void {
        $this->resetAfterTest();
        $task = \core\task\manager::get_scheduled_task(cleanup_remote::class);
        $this->assertInstanceOf(cleanup_remote::class, $task);
    }
}
