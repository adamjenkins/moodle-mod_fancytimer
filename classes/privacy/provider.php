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

use context;
use context_module;
use context_user;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_fancytimer\local\recordings;

/**
 * Privacy provider for mod_fancytimer.
 *
 * Teacher recordings, remote-control commands and the published timer state are stored in the
 * activity's module context. Presets are personal (usable in any course) and belong to the
 * owner's user context.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the personal data stored.
     *
     * @param collection $collection The collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('fancytimer_recordings', [
            'userid' => 'privacy:metadata:recordings:userid',
            'name' => 'privacy:metadata:recordings:name',
            'timecreated' => 'privacy:metadata:recordings:timecreated',
        ], 'privacy:metadata:recordings');

        $collection->add_database_table('fancytimer_presets', [
            'userid' => 'privacy:metadata:presets:userid',
            'name' => 'privacy:metadata:presets:name',
            'config' => 'privacy:metadata:presets:config',
            'timecreated' => 'privacy:metadata:presets:timecreated',
            'timemodified' => 'privacy:metadata:presets:timemodified',
        ], 'privacy:metadata:presets');

        $collection->add_database_table('fancytimer_commands', [
            'userid' => 'privacy:metadata:commands:userid',
            'command' => 'privacy:metadata:commands:command',
            'value' => 'privacy:metadata:commands:value',
            'timecreated' => 'privacy:metadata:commands:timecreated',
        ], 'privacy:metadata:commands');

        $collection->add_database_table('fancytimer_state', [
            'userid' => 'privacy:metadata:state:userid',
            'state' => 'privacy:metadata:state:state',
            'statetimems' => 'privacy:metadata:state:statetimems',
        ], 'privacy:metadata:state');

        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:core_files');

        return $collection;
    }

    /**
     * Contexts holding the user's data: module contexts (recordings, commands, state) and their own
     * user context (presets).
     *
     * @param int $userid User id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $params = ['contextlevel' => CONTEXT_MODULE, 'modname' => 'fancytimer', 'userid' => $userid];
        foreach (['fancytimer_recordings', 'fancytimer_commands', 'fancytimer_state'] as $table) {
            $sql = "SELECT ctx.id
                      FROM {context} ctx
                      JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                      JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                      JOIN {" . $table . "} t ON t.fancytimerid = cm.instance
                     WHERE t.userid = :userid";
            $contextlist->add_from_sql($sql, $params);
        }
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {fancytimer_presets} p ON p.userid = ctx.instanceid AND ctx.contextlevel = :contextlevel
                 WHERE p.userid = :userid";
        $contextlist->add_from_sql($sql, ['contextlevel' => CONTEXT_USER, 'userid' => $userid]);
        return $contextlist;
    }

    /**
     * Users who have data in a context.
     *
     * @param userlist $userlist The userlist to fill.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context instanceof context_user) {
            $userlist->add_from_sql(
                'userid',
                "SELECT userid FROM {fancytimer_presets} WHERE userid = :userid",
                ['userid' => $context->instanceid]
            );
            return;
        }
        if (!$context instanceof context_module) {
            return;
        }
        $params = ['modname' => 'fancytimer', 'cmid' => $context->instanceid];
        foreach (['fancytimer_recordings', 'fancytimer_commands', 'fancytimer_state'] as $table) {
            $sql = "SELECT t.userid
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                      JOIN {" . $table . "} t ON t.fancytimerid = cm.instance
                     WHERE cm.id = :cmid";
            $userlist->add_from_sql('userid', $sql, $params);
        }
    }

    /**
     * Export the user's recordings (with their audio files), commands, published state and presets.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $user = $contextlist->get_user();
        $userid = (int)$user->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_user) {
                if ((int)$context->instanceid === $userid) {
                    self::export_presets($context, $userid);
                }
                continue;
            }
            $instanceid = self::get_instanceid($context);
            if (!$instanceid) {
                continue;
            }
            $writer = writer::with_context($context);

            $data = helper::get_context_data($context, $user);
            $commands = $DB->get_records(
                'fancytimer_commands',
                ['fancytimerid' => $instanceid, 'userid' => $userid],
                'id ASC'
            );
            $data->commands = array_values(array_map(fn($row) => (object)[
                'command' => $row->command,
                'value' => (int)$row->value,
                'timecreated' => transform::datetime($row->timecreated),
            ], $commands));
            $state = $DB->get_record('fancytimer_state', ['fancytimerid' => $instanceid, 'userid' => $userid]);
            if ($state) {
                $decoded = json_decode((string)$state->state);
                $data->publishedstate = (object)[
                    'state' => $decoded ?? (string)$state->state,
                    'timepublished' => transform::datetime(intdiv((int)$state->statetimems, 1000)),
                ];
            }
            $writer->export_data([], $data);
            helper::export_context_files($context, $user);

            $records = $DB->get_records(
                'fancytimer_recordings',
                ['fancytimerid' => $instanceid, 'userid' => $userid],
                'id ASC'
            );
            if ($records) {
                $path = [get_string('privacy:path:recordings', 'mod_fancytimer')];
                $writer->export_data($path, (object)[
                    'recordings' => array_values(array_map(fn($row) => (object)[
                        'id' => (int)$row->id,
                        'name' => format_string($row->name, true, ['context' => $context]),
                        'timecreated' => transform::datetime($row->timecreated),
                    ], $records)),
                ]);
                foreach ($records as $record) {
                    $writer->export_area_files($path, 'mod_fancytimer', 'recording', (int)$record->id);
                }
            }
        }
    }

    /**
     * Export a user's presets into their user context.
     *
     * @param context_user $context The owner's user context.
     * @param int $userid The owner.
     * @return void
     */
    protected static function export_presets(context_user $context, int $userid): void {
        global $DB;
        $records = $DB->get_records('fancytimer_presets', ['userid' => $userid], 'id ASC');
        if (!$records) {
            return;
        }
        $presets = [];
        foreach ($records as $record) {
            $config = json_decode((string)$record->config);
            $presets[] = (object)[
                'name' => format_string($record->name, true, ['context' => $context]),
                'config' => $config ?? (string)$record->config,
                'timecreated' => transform::datetime($record->timecreated),
                'timemodified' => transform::datetime($record->timemodified),
            ];
        }
        writer::with_context($context)->export_data([
            get_string('pluginname', 'mod_fancytimer'),
            get_string('presets', 'mod_fancytimer'),
        ], (object)['presets' => $presets]);
    }

    /**
     * Delete everyone's data in a context.
     *
     * @param context $context The context.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context) {
        global $DB;
        if ($context instanceof context_user) {
            $DB->delete_records('fancytimer_presets', ['userid' => $context->instanceid]);
            return;
        }
        $instanceid = self::get_instanceid($context);
        if (!$instanceid) {
            return;
        }
        foreach ($DB->get_records('fancytimer_recordings', ['fancytimerid' => $instanceid]) as $record) {
            recordings::delete($context, $record);
        }
        $DB->delete_records('fancytimer_commands', ['fancytimerid' => $instanceid]);
        $DB->delete_records('fancytimer_state', ['fancytimerid' => $instanceid]);
    }

    /**
     * Delete one user's data in the approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            self::delete_users_data($context, [$userid]);
        }
    }

    /**
     * Delete several users' data in one context.
     *
     * @param approved_userlist $userlist Approved users.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $userids = array_map('intval', $userlist->get_userids());
        if ($userids) {
            self::delete_users_data($userlist->get_context(), $userids);
        }
    }

    /**
     * Delete the data of the given users in one context.
     *
     * @param context $context Module context or user context.
     * @param int[] $userids Users.
     * @return void
     */
    protected static function delete_users_data(context $context, array $userids): void {
        global $DB;
        if ($context instanceof context_user) {
            if (in_array((int)$context->instanceid, $userids, true)) {
                $DB->delete_records('fancytimer_presets', ['userid' => $context->instanceid]);
            }
            return;
        }
        $instanceid = self::get_instanceid($context);
        if (!$instanceid) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params = ['fancytimerid' => $instanceid] + $inparams;
        $select = "fancytimerid = :fancytimerid AND userid $insql";
        foreach ($DB->get_records_select('fancytimer_recordings', $select, $params) as $record) {
            recordings::delete($context, $record);
        }
        $DB->delete_records_select('fancytimer_commands', $select, $params);
        $DB->delete_records_select('fancytimer_state', $select, $params);
    }

    /**
     * The fancytimer instance id behind a module context, or 0 when it is not one of ours.
     *
     * @param context $context The context.
     * @return int
     */
    protected static function get_instanceid(context $context): int {
        global $DB;
        if (!$context instanceof context_module) {
            return 0;
        }
        $sql = "SELECT cm.instance
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                 WHERE cm.id = :cmid";
        return (int)$DB->get_field_sql($sql, ['modname' => 'fancytimer', 'cmid' => $context->instanceid]);
    }
}
