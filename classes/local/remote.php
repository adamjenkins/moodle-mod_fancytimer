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

use moodle_url;
use stdClass;

/**
 * Remote control: the command queue (remote → controlling page) and the published state
 * (controlling page → remotes and mirrors).
 *
 * State JSON published by amd/src/timer.js (string, at most STATE_MAX_BYTES):
 *
 *     {
 *       "status": "idle" | "running" | "paused" | "finished",
 *       "index": int,              // position in the playlist
 *       "remainingms": int,        // left in the current segment at the moment of publishing
 *       "durationms": int,         // current segment length incl. added time
 *       "cycle": int               // completed cycles (looping timers)
 *     }
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class remote {
    /** @var string[] Commands a remote may send. */
    public const COMMANDS = ['start', 'pause', 'resume', 'toggle', 'next', 'previous', 'reset', 'addtime'];

    /** @var int Largest state JSON accepted, bytes. */
    public const STATE_MAX_BYTES = 2048;

    /** @var int Largest addtime value, seconds. */
    public const MAX_ADDTIME = 600;

    /** @var int Commands older than this are deleted by the cleanup task, seconds. */
    public const COMMAND_TTL = DAYSECS;

    /**
     * URL of the phone remote for a course module.
     *
     * @param int $cmid
     * @return moodle_url
     */
    public static function remote_url(int $cmid): moodle_url {
        return new moodle_url('/mod/fancytimer/remote.php', ['id' => $cmid]);
    }

    /**
     * A QR code for a URL, as an inline SVG string (core_qrcode, TCPDF).
     *
     * The QR code is a convenience next to the remote link, so a failure inside core's
     * TCPDF encoder must not break the timer page: on any PHP warning or exception it
     * returns '' and the page shows the link alone. (CI's PHP 8.4.28 job hit
     * "Trying to access array offset on null" in tcpdf/include/barcodes/qrcode.php:761
     * for http://localhost:8000/mod/fancytimer/remote.php?id=109000, which encodes
     * cleanly on PHP 8.4.24 and 8.3.)
     *
     * @param moodle_url $url
     * @return string SVG markup (no XML prolog), or '' when the encoder failed.
     */
    public static function qr_svg(moodle_url $url): string {
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        });
        try {
            $qrcode = new \core_qrcode($url->out(false));
            $svg = (string)$qrcode->getBarcodeSVGcode(6, 6, 'black');
        } catch (\Throwable $e) {
            $svg = '';
        } finally {
            restore_error_handler();
        }
        if (strpos($svg, '<svg') === false) {
            return '';
        }
        // Drop the XML prolog and DOCTYPE so the SVG can be inlined in HTML.
        return trim(preg_replace('/^.*?(<svg)/s', '$1', $svg));
    }

    /**
     * Queue a command for the controlling page.
     *
     * @param int $fancytimerid
     * @param int $userid
     * @param string $command One of COMMANDS (checked by the caller).
     * @param int $value Seconds for addtime; ignored otherwise.
     * @return int Command id.
     */
    public static function queue_command(int $fancytimerid, int $userid, string $command, int $value): int {
        global $DB;
        $value = $command === 'addtime' ? max(1, min(self::MAX_ADDTIME, $value)) : 0;
        return (int)$DB->insert_record('fancytimer_commands', (object)[
            'fancytimerid' => $fancytimerid,
            'userid' => $userid,
            'command' => $command,
            'value' => $value,
            'timecreated' => time(),
        ]);
    }

    /**
     * The id of the newest command of a timer (0 if none).
     *
     * @param int $fancytimerid
     * @return int
     */
    public static function last_command_id(int $fancytimerid): int {
        global $DB;
        return (int)$DB->get_field_sql(
            'SELECT MAX(id) FROM {fancytimer_commands} WHERE fancytimerid = ?',
            [$fancytimerid]
        );
    }

    /**
     * Commands newer than an id, oldest first.
     *
     * @param int $fancytimerid
     * @param int $sinceid
     * @return stdClass[] list of {id, command, value}.
     */
    public static function commands_since(int $fancytimerid, int $sinceid): array {
        global $DB;
        return array_values($DB->get_records_select(
            'fancytimer_commands',
            'fancytimerid = ? AND id > ?',
            [$fancytimerid, $sinceid],
            'id ASC',
            'id, command, value',
            0,
            50
        ));
    }

    /**
     * Store the state published by a controlling page.
     *
     * @param int $fancytimerid
     * @param int $userid
     * @param string $statejson Already validated by the caller.
     */
    public static function publish_state(int $fancytimerid, int $userid, string $statejson): void {
        global $DB;
        $nowms = (int)floor(microtime(true) * 1000);
        $existing = $DB->get_record('fancytimer_state', ['fancytimerid' => $fancytimerid], 'id');
        $row = (object)['fancytimerid' => $fancytimerid, 'state' => $statejson, 'statetimems' => $nowms,
            'userid' => $userid];
        if ($existing) {
            $row->id = $existing->id;
            $DB->update_record('fancytimer_state', $row);
        } else {
            $DB->insert_record('fancytimer_state', $row);
        }
    }

    /**
     * The last published state.
     *
     * @param int $fancytimerid
     * @return array{state: string, agems: int} state '' and agems -1 when nothing was published.
     */
    public static function get_state(int $fancytimerid): array {
        global $DB;
        $row = $DB->get_record('fancytimer_state', ['fancytimerid' => $fancytimerid]);
        if (!$row) {
            return ['state' => '', 'agems' => -1];
        }
        $nowms = (int)floor(microtime(true) * 1000);
        return ['state' => (string)$row->state, 'agems' => max(0, $nowms - (int)$row->statetimems)];
    }

    /**
     * Validate and normalise a state JSON string from a controlling page.
     *
     * @param string $json
     * @return string|null Re-encoded JSON with known keys only, or null when invalid.
     */
    public static function clean_state(string $json): ?string {
        if (strlen($json) > self::STATE_MAX_BYTES) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !in_array($data['status'] ?? null, ['idle', 'running', 'paused', 'finished'], true)) {
            return null;
        }
        return json_encode([
            'status' => $data['status'],
            'index' => max(0, (int)($data['index'] ?? 0)),
            'remainingms' => max(0, (int)($data['remainingms'] ?? 0)),
            'durationms' => max(0, (int)($data['durationms'] ?? 0)),
            'cycle' => max(0, (int)($data['cycle'] ?? 0)),
        ]);
    }
}
