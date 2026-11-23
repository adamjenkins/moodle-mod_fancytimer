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
 * Backup task for mod_fancytimer.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/fancytimer/backup/moodle2/backup_fancytimer_stepslib.php');

/**
 * Provides the steps to back up one Fancy Timer activity.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_fancytimer_activity_task extends backup_activity_task {
    /**
     * No settings of its own.
     *
     * @return void
     */
    protected function define_my_settings() {
    }

    /**
     * One structure step writing fancytimer.xml.
     *
     * @return void
     */
    protected function define_my_steps() {
        $this->add_step(new backup_fancytimer_activity_structure_step('fancytimer_structure', 'fancytimer.xml'));
    }

    /**
     * Encode links to the activity's pages so restore can point them at the new course.
     *
     * @param string $content HTML that may contain links
     * @return string The content with the links encoded
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        // The list of timers in a course.
        $search = '/(' . $base . '\/mod\/fancytimer\/index.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@FANCYTIMERINDEX*$2@$', $content);

        // One timer by course module id.
        $search = '/(' . $base . '\/mod\/fancytimer\/view.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@FANCYTIMERVIEWBYID*$2@$', $content);

        return $content;
    }
}
