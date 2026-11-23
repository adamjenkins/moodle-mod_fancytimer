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
 * Segment editor helpers: colour pickers, palette swatches and a live contrast check per colour pair.
 *
 * Colour text inputs carry data-fancytimer-colour ("bg" or "text") and data-fancytimer-pair
 * (the segment index, or "rotation").
 *
 * @module     mod_fancytimer/editor
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';
import Templates from 'core/templates';
import {normalise, ratio} from 'mod_fancytimer/contrast';

/** @var {number} Ratio below which the readout warns (WCAG AA, normal text). */
const GOOD_CONTRAST = 4.5;

/** @var {number} Ratio below which the warning is stronger (WCAG AA, large text). */
const MIN_CONTRAST = 3;

/** @var {number} Delay before the live region is updated while typing, ms. */
const ANNOUNCE_DELAY = 400;

/**
 * Fill a {$a} placeholder.
 *
 * @param {string} text
 * @param {string} value
 * @returns {string}
 */
const fill = (text, value) => text.split('{$a}').join(value);

/**
 * The visible label text of a form field.
 *
 * @param {HTMLInputElement} input
 * @returns {string}
 */
const labelText = (input) => {
    const label = input.id ? document.querySelector('label[for="' + CSS.escape(input.id) + '"]') : null;
    return label ? label.textContent.trim() : '';
};

/**
 * Tell listeners (formslib's change checker among them) that a field changed.
 *
 * @param {HTMLInputElement} input
 */
const fire = (input) => {
    input.dispatchEvent(new Event('input', {bubbles: true}));
    input.dispatchEvent(new Event('change', {bubbles: true}));
};

/**
 * Add a native colour picker beside a colour text field, kept in sync both ways.
 *
 * @param {HTMLInputElement} input
 * @param {Object} strings
 * @returns {HTMLInputElement} The picker.
 */
const addPicker = (input, strings) => {
    const picker = document.createElement('input');
    picker.type = 'color';
    picker.className = 'form-control form-control-color ms-2 mod-fancytimer-colourpicker';
    if (input.id) {
        picker.id = input.id + '-picker';
    }
    picker.setAttribute('aria-label', fill(strings.pickcolour, labelText(input)));
    picker.value = normalise(input.value) || '#000000';
    picker.disabled = input.disabled;
    input.insertAdjacentElement('afterend', picker);

    picker.addEventListener('input', () => {
        input.value = picker.value;
        fire(input);
    });
    input.addEventListener('input', () => {
        const hex = normalise(input.value);
        if (hex && picker.value !== hex) {
            picker.value = hex;
        }
    });
    return picker;
};

/**
 * Add the palette swatches under a background colour field.
 *
 * @param {HTMLInputElement} input
 * @param {Array} palette [{key, hex, name}]
 * @param {Object} strings
 * @returns {HTMLElement} The swatch group.
 */
const addSwatches = (input, palette, strings) => {
    const group = document.createElement('div');
    group.className = 'w-100 mt-2 d-flex flex-wrap mod-fancytimer-swatches';
    group.setAttribute('role', 'group');
    group.setAttribute('aria-label', fill(strings.swatches, labelText(input)));
    palette.forEach((colour) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn me-1 mb-1 mod-fancytimer-swatch';
        button.style.backgroundColor = colour.hex;
        button.dataset.hex = colour.hex;
        button.setAttribute('aria-label', fill(strings.swatchlabel, colour.name));
        button.title = colour.name;
        button.addEventListener('click', () => {
            input.value = colour.hex;
            fire(input);
        });
        group.appendChild(button);
    });
    // The swatches sit after the field's error area so they stay on their own line.
    input.parentElement.appendChild(group);
    return group;
};

/**
 * Mark the swatch that matches the field's value.
 *
 * @param {HTMLElement} group
 * @param {HTMLInputElement} input
 */
const markSwatch = (group, input) => {
    const hex = normalise(input.value);
    group.querySelectorAll('.mod-fancytimer-swatch').forEach((button) => {
        button.setAttribute('aria-pressed', button.dataset.hex === hex ? 'true' : 'false');
    });
};

/**
 * Set up the readout and preview chip of one colour pair.
 *
 * @param {HTMLInputElement} bg
 * @param {HTMLInputElement} text
 * @param {Object} strings
 * @param {Object} icons Rendered pix HTML: {good, warn}.
 * @returns {Function} Updates the readout from the fields.
 */
const addReadout = (bg, text, strings, icons) => {
    const box = document.createElement('div');
    box.className = 'w-100 mt-2 d-flex flex-wrap align-items-center mod-fancytimer-contrast';

    const chip = document.createElement('span');
    chip.className = 'me-3 mod-fancytimer-contrast-chip';
    chip.setAttribute('aria-hidden', 'true');
    chip.textContent = '1:23';

    const chiplabel = document.createElement('span');
    chiplabel.className = 'me-2 small';
    chiplabel.setAttribute('aria-hidden', 'true');
    chiplabel.textContent = strings.preview;

    const readout = document.createElement('p');
    readout.className = 'mb-0 mod-fancytimer-contrast-readout';
    readout.setAttribute('aria-live', 'polite');

    const icon = document.createElement('span');
    icon.className = 'me-1 mod-fancytimer-contrast-icon';
    const message = document.createElement('span');
    readout.appendChild(icon);
    readout.appendChild(message);

    box.appendChild(chiplabel);
    box.appendChild(chip);
    box.appendChild(readout);
    text.parentElement.appendChild(box);

    let announce = null;
    let lastlevel = '';
    let lasttext = '';

    const render = (level, words) => {
        readout.classList.remove('mod-fancytimer-contrast-good', 'mod-fancytimer-contrast-low',
            'mod-fancytimer-contrast-verylow', 'mod-fancytimer-contrast-invalid');
        readout.classList.add('mod-fancytimer-contrast-' + level);
        if (level !== lastlevel) {
            icon.innerHTML = level === 'good' ? icons.good : icons.warn;
            lastlevel = level;
        }
        if (words !== lasttext) {
            message.textContent = words;
            lasttext = words;
        }
    };

    return (immediate) => {
        const b = normalise(bg.value);
        const t = normalise(text.value);
        if (b && t) {
            chip.style.backgroundColor = b;
            chip.style.color = t;
        }
        clearTimeout(announce);
        const update = () => {
            if (!b || !t) {
                render('invalid', strings.invalid);
                return;
            }
            const r = ratio(b, t);
            // Round down, so 4.49 never reads as a passing 4.5.
            const shown = (Math.floor(r * 10) / 10).toFixed(1);
            if (r >= GOOD_CONTRAST) {
                render('good', fill(strings.good, shown));
            } else if (r >= MIN_CONTRAST) {
                render('low', fill(strings.low, shown));
            } else {
                render('verylow', fill(strings.verylow, shown));
            }
        };
        if (immediate) {
            update();
        } else {
            announce = setTimeout(update, ANNOUNCE_DELAY);
        }
    };
};

/**
 * Keep the added controls' disabled state in step with the field (hideIf() disables it).
 *
 * @param {HTMLInputElement} input
 * @param {HTMLElement[]} controls Elements, or groups of buttons.
 */
const followDisabled = (input, controls) => {
    const apply = () => {
        controls.forEach((control) => {
            if (control.tagName === 'INPUT') {
                control.disabled = input.disabled;
            } else {
                control.querySelectorAll('button').forEach((b) => {
                    b.disabled = input.disabled;
                });
            }
        });
    };
    new MutationObserver(apply).observe(input, {attributes: true, attributeFilter: ['disabled']});
    apply();
};

/**
 * Enhance every colour field on the page.
 *
 * @param {Array} palette [{key, hex, name}] from segments::PALETTE.
 * @returns {Promise<void>}
 */
export const init = async(palette) => {
    const inputs = document.querySelectorAll('input[data-fancytimer-colour]');
    if (!inputs.length) {
        return;
    }
    const [pickcolour, swatches, swatchlabel, preview, good, low, verylow, invalid] = await getStrings([
        'pickcolour', 'swatches', 'swatchlabel', 'contrastpreview', 'contrastgood', 'contrastlow',
        'contrastverylow', 'contrastinvalid',
    ].map((key) => ({key, component: 'mod_fancytimer'})));
    const strings = {pickcolour, swatches, swatchlabel, preview, good, low, verylow, invalid};
    let icons = {good: '', warn: ''};
    try {
        const [goodicon, warnicon] = await Promise.all([
            Templates.renderPix('i/valid', 'core', ''),
            Templates.renderPix('i/warning', 'core', ''),
        ]);
        icons = {good: goodicon, warn: warnicon};
    } catch (e) {
        // The text and colour still carry the meaning without the icon.
    }

    const pairs = new Map();
    inputs.forEach((input) => {
        if (input.dataset.fancytimerEnhanced) {
            return;
        }
        input.dataset.fancytimerEnhanced = '1';
        const pairkey = input.dataset.fancytimerPair || '';
        if (!pairs.has(pairkey)) {
            pairs.set(pairkey, {});
        }
        pairs.get(pairkey)[input.dataset.fancytimerColour] = input;

        const controls = [addPicker(input, strings)];
        if (input.dataset.fancytimerColour === 'bg') {
            const group = addSwatches(input, palette || [], strings);
            markSwatch(group, input);
            input.addEventListener('input', () => markSwatch(group, input));
            controls.push(group);
        }
        followDisabled(input, controls);
    });

    pairs.forEach((pair) => {
        if (!pair.bg || !pair.text) {
            return;
        }
        const update = addReadout(pair.bg, pair.text, strings, icons);
        pair.bg.addEventListener('input', () => update(false));
        pair.text.addEventListener('input', () => update(false));
        update(true);
    });
};
