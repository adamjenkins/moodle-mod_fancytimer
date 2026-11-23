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
 * WCAG 2.x contrast ratio, the same formula as \mod_fancytimer\local\segments::contrast_ratio().
 *
 * @module     mod_fancytimer/contrast
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Normalise a colour to lower-case #rrggbb, or null when it is not a colour.
 *
 * @param {string} value '#abc', '#aabbcc' or the same without '#'.
 * @returns {string|null}
 */
export const normalise = (value) => {
    const text = String(value || '').trim().toLowerCase();
    let m = text.match(/^#?([0-9a-f]{3})$/);
    if (m) {
        const c = m[1];
        return '#' + c[0] + c[0] + c[1] + c[1] + c[2] + c[2];
    }
    m = text.match(/^#?([0-9a-f]{6})$/);
    return m ? '#' + m[1] : null;
};

/**
 * Relative luminance of a #rrggbb colour.
 *
 * @param {string} hex
 * @returns {number}
 */
const luminance = (hex) => {
    const channels = [1, 3, 5].map((pos) => {
        const c = parseInt(hex.substr(pos, 2), 16) / 255;
        return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
};

/**
 * Contrast ratio of two colours. Invalid colours count as black, as on the server.
 *
 * @param {string} a
 * @param {string} b
 * @returns {number} 1..21
 */
export const ratio = (a, b) => {
    const la = luminance(normalise(a) || '#000000');
    const lb = luminance(normalise(b) || '#000000');
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
};
