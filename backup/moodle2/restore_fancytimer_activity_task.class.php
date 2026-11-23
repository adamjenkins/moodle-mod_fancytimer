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

/**
 * Restore task for mod_fancytimer.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/fancytimer/backup/moodle2/restore_fancytimer_stepslib.php');

/**
 * Provides the steps to restore one Fancy Timer activity.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_fancytimer_activity_task extends restore_activity_task {
    /**
     * No settings of its own.
     *
     * @return void
     */
    protected function define_my_settings() {
    }

    /**
     * One structure step reading fancytimer.xml.
     *
     * @return void
     */
    protected function define_my_steps() {
        $this->add_step(new restore_fancytimer_activity_structure_step('fancytimer_structure', 'fancytimer.xml'));
    }

    /**
     * Content fields that may hold encoded links.
     *
     * @return restore_decode_content[]
     */
    public static function define_decode_contents() {
        return [
            new restore_decode_content('fancytimer', ['intro'], 'fancytimer'),
        ];
    }

    /**
     * Rules that turn encoded links back into URLs.
     *
     * @return restore_decode_rule[]
     */
    public static function define_decode_rules() {
        return [
            new restore_decode_rule('FANCYTIMERVIEWBYID', '/mod/fancytimer/view.php?id=$1', 'course_module'),
            new restore_decode_rule('FANCYTIMERINDEX', '/mod/fancytimer/index.php?id=$1', 'course'),
        ];
    }

    /**
     * Restore log rules for the activity's legacy logs.
     *
     * @return restore_log_rule[]
     */
    public static function define_restore_log_rules() {
        return [
            new restore_log_rule('fancytimer', 'add', 'view.php?id={course_module}', '{fancytimer}'),
            new restore_log_rule('fancytimer', 'update', 'view.php?id={course_module}', '{fancytimer}'),
            new restore_log_rule('fancytimer', 'view', 'view.php?id={course_module}', '{fancytimer}'),
        ];
    }

    /**
     * Restore log rules for course-level legacy logs (no course module).
     *
     * @return restore_log_rule[]
     */
    public static function define_restore_log_rules_for_course() {
        return [
            new restore_log_rule('fancytimer', 'view all', 'index.php?id={course}', null),
        ];
    }
}
