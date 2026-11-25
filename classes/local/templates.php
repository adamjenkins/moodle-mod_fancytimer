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
 * Bundled timer templates.
 *
 * Labels are lang strings, resolved in the current language when a template is applied.
 *
 * @package    mod_fancytimer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class templates {
    /** @var string Template used for a new activity when nothing else is chosen. */
    public const DEFAULT = 'threeperson';

    /**
     * Template keys, in menu order.
     *
     * @return string[]
     */
    public static function keys(): array {
        return ['threeperson', 'pairs', 'thinkpairshare', 'single'];
    }

    /**
     * Menu of templates: key => display name.
     *
     * @return array
     */
    public static function menu(): array {
        $menu = [];
        foreach (self::keys() as $key) {
            $menu[$key] = get_string('template_' . $key, 'mod_fancytimer');
        }
        return $menu;
    }

    /**
     * The config of one template, in preset_format shape (already valid).
     *
     * @param string $key
     * @return array|null Null for an unknown key.
     */
    public static function get(string $key): ?array {
        $str = fn(string $id, $a = null) => get_string($id, 'mod_fancytimer', $a);
        $seg = fn(string $label, int $duration, string $colour, string $sound = 'chime') => [
            'label' => $label,
            'duration' => $duration,
            'bgcolour' => segments::PALETTE[$colour],
            'textcolour' => segments::DEFAULT_TEXT,
            'sound' => $sound,
        ];
        $settings = fn(int $looping, int $rotation) => [
            'looping' => $looping,
            'rotationenabled' => $rotation,
            'warningenabled' => 0,
            'warningsecs' => 5,
            'warningsound' => sounds::DEFAULT_WARNING,
            'startsound' => sounds::NONE,
        ];
        switch ($key) {
            case 'threeperson':
                $segments = [
                    $seg($str('templatelabel_person', 1), 120, 'blue'),
                    $seg($str('templatelabel_person', 2), 120, 'purple'),
                    $seg($str('templatelabel_person', 3), 120, 'yellow'),
                ];
                $rotation = $seg($str('templatelabel_changegroups'), 30, 'green', 'bell');
                $config = ['settings' => $settings(1, 1), 'segments' => $segments, 'rotation' => $rotation];
                break;
            case 'pairs':
                $segments = [
                    $seg($str('templatelabel_person', 1), 180, 'blue'),
                    $seg($str('templatelabel_person', 2), 180, 'orange'),
                ];
                $rotation = $seg($str('templatelabel_changepartners'), 30, 'green', 'bell');
                $config = ['settings' => $settings(1, 1), 'segments' => $segments, 'rotation' => $rotation];
                break;
            case 'thinkpairshare':
                $segments = [
                    $seg($str('templatelabel_think'), 60, 'teal'),
                    $seg($str('templatelabel_pair'), 120, 'purple'),
                    $seg($str('templatelabel_share'), 120, 'yellow', 'bell'),
                ];
                $config = ['settings' => $settings(0, 0), 'segments' => $segments, 'rotation' => null];
                break;
            case 'single':
                $segments = [$seg($str('templatelabel_timeleft'), 300, 'blue', 'gong')];
                $config = ['settings' => $settings(0, 0), 'segments' => $segments, 'rotation' => null];
                break;
            default:
                return null;
        }
        return preset_format::validate([
            'format' => preset_format::FORMAT,
            'version' => preset_format::VERSION,
            'name' => $str('template_' . $key),
        ] + $config);
    }
}
