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
 * Tests for the remote-control command queue and published state.
 *
 * @package    mod_fancytimer
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(remote::class)]
final class remote_test extends \advanced_testcase {
    /**
     * Valid states are re-encoded with known keys only and clamped values.
     */
    public function test_clean_state_normalises(): void {
        $json = remote::clean_state(json_encode([
            'status' => 'running',
            'index' => -3,
            'remainingms' => '1500',
            'durationms' => 120000.7,
            'cycle' => 2,
            'evil' => '<script>',
        ]));
        $this->assertSame('{"status":"running","index":0,"remainingms":1500,"durationms":120000,"cycle":2}', $json);

        $json = remote::clean_state('{"status":"idle"}');
        $this->assertSame(
            ['status' => 'idle', 'index' => 0, 'remainingms' => 0, 'durationms' => 0, 'cycle' => 0],
            json_decode($json, true)
        );

        foreach (['paused', 'finished'] as $status) {
            $this->assertNotNull(remote::clean_state(json_encode(['status' => $status])));
        }
    }

    /**
     * Data for rejected states.
     *
     * @return array
     */
    public static function bad_state_provider(): array {
        return [
            'unknown status' => ['{"status":"exploded"}'],
            'status wrong case' => ['{"status":"RUNNING"}'],
            'status missing' => ['{"index":1}'],
            'status not a string' => ['{"status":1}'],
            'not json' => ['running'],
            'json scalar' => ['"running"'],
            'empty' => [''],
            'list' => ['["running"]'],
        ];
    }

    /**
     * Bad states give null.
     *
     * @param string $json
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bad_state_provider')]
    public function test_clean_state_rejects(string $json): void {
        $this->assertNull(remote::clean_state($json));
    }

    /**
     * A state longer than STATE_MAX_BYTES is rejected even when otherwise valid.
     */
    public function test_clean_state_rejects_oversize(): void {
        $prefix = '{"status":"running","pad":"';
        $suffix = '"}';
        $exact = $prefix . str_repeat('x', remote::STATE_MAX_BYTES - strlen($prefix) - strlen($suffix)) . $suffix;
        $this->assertSame(remote::STATE_MAX_BYTES, strlen($exact));
        $this->assertNotNull(remote::clean_state($exact));
        $over = $prefix . str_repeat('x', remote::STATE_MAX_BYTES) . $suffix;
        $this->assertNull(remote::clean_state($over));
    }

    /**
     * addtime is clamped to 1..MAX_ADDTIME; other commands store 0.
     */
    public function test_queue_command_clamps(): void {
        global $DB;
        $this->resetAfterTest();
        $cases = [[30, 30], [0, 1], [-50, 1], [remote::MAX_ADDTIME + 1, remote::MAX_ADDTIME], [99999, remote::MAX_ADDTIME]];
        foreach ($cases as [$in, $expected]) {
            $id = remote::queue_command(5, 2, 'addtime', $in);
            $this->assertEquals($expected, $DB->get_field('fancytimer_commands', 'value', ['id' => $id]), "addtime $in");
        }
        $id = remote::queue_command(5, 2, 'next', 77);
        $row = $DB->get_record('fancytimer_commands', ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals(0, $row->value);
        $this->assertSame('next', $row->command);
        $this->assertEquals(2, $row->userid);
        $this->assertEquals(5, $row->fancytimerid);
    }

    /**
     * commands_since() returns newer commands of the same timer, oldest first, at most 50.
     */
    public function test_commands_since_and_last_command_id(): void {
        $this->resetAfterTest();
        $this->assertSame(0, remote::last_command_id(11));
        $this->assertSame([], remote::commands_since(11, 0));

        $first = remote::queue_command(11, 2, 'start', 0);
        $other = remote::queue_command(12, 2, 'reset', 0);
        $second = remote::queue_command(11, 2, 'addtime', 30);
        $third = remote::queue_command(11, 2, 'pause', 0);

        $all = remote::commands_since(11, 0);
        $this->assertSame([$first, $second, $third], array_map(fn($c) => (int)$c->id, $all));
        $this->assertSame(['start', 'addtime', 'pause'], array_map(fn($c) => $c->command, $all));
        $this->assertEquals(30, $all[1]->value);
        $this->assertSame([0, 1, 2], array_keys($all));

        $this->assertSame([$second, $third], array_map(fn($c) => (int)$c->id, remote::commands_since(11, $first)));
        $this->assertSame([], remote::commands_since(11, $third));
        $this->assertSame($third, remote::last_command_id(11));
        $this->assertSame($other, remote::last_command_id(12));

        for ($i = 0; $i < 60; $i++) {
            remote::queue_command(11, 2, 'next', 0);
        }
        $batch = remote::commands_since(11, $third);
        $this->assertCount(50, $batch);
        $ids = array_map(fn($c) => (int)$c->id, $batch);
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);
        $this->assertCount(10, remote::commands_since(11, end($ids)));
    }

    /**
     * get_state() before and after publishing; publishing updates the single row.
     */
    public function test_publish_and_get_state(): void {
        global $DB;
        $this->resetAfterTest();
        $this->assertSame(['state' => '', 'agems' => -1], remote::get_state(21));

        $state = remote::clean_state('{"status":"running","index":1}');
        remote::publish_state(21, 3, $state);
        $got = remote::get_state(21);
        $this->assertSame($state, $got['state']);
        $this->assertGreaterThanOrEqual(0, $got['agems']);
        $this->assertLessThan(60000, $got['agems']);

        $paused = remote::clean_state('{"status":"paused"}');
        remote::publish_state(21, 4, $paused);
        $this->assertSame(1, $DB->count_records('fancytimer_state', ['fancytimerid' => 21]));
        $row = $DB->get_record('fancytimer_state', ['fancytimerid' => 21], '*', MUST_EXIST);
        $this->assertEquals(4, $row->userid);
        $this->assertSame($paused, remote::get_state(21)['state']);

        // The age is measured from statetimems.
        $DB->set_field('fancytimer_state', 'statetimems', (int)floor(microtime(true) * 1000) - 7000, ['id' => $row->id]);
        $age = remote::get_state(21)['agems'];
        $this->assertGreaterThanOrEqual(7000, $age);
        $this->assertLessThan(67000, $age);

        // A state stamped in the future never gives a negative age.
        $DB->set_field('fancytimer_state', 'statetimems', (int)floor(microtime(true) * 1000) + 100000, ['id' => $row->id]);
        $this->assertSame(0, remote::get_state(21)['agems']);

        $this->assertSame(['state' => '', 'agems' => -1], remote::get_state(22));
    }

    /**
     * The remote URL and its QR code.
     */
    public function test_remote_url_and_qr(): void {
        $url = remote::remote_url(42);
        $this->assertStringEndsWith('/mod/fancytimer/remote.php', $url->get_path(false));
        $this->assertEquals(42, $url->get_param('id'));
        $svg = remote::qr_svg($url);
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringNotContainsString('<?xml', $svg);
        $this->assertStringNotContainsString('DOCTYPE', $svg);
    }
}
