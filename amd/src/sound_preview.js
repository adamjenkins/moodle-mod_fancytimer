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
 * Play buttons beside every sound select (select[data-fancytimer-sound]).
 *
 * Recording URLs come from the JSON in [data-fancytimer-recordingurls] ({"<id>": "<url>"}), when the
 * page has one; recorder.js keeps the selects and the map current through registerRecording() and
 * removeRecording().
 *
 * @module     mod_fancytimer/sound_preview
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';
import {add as addToast} from 'core/toast';
import {unlock, play} from 'mod_fancytimer/audio';

/** @var {string} Prefix of a recording sound key. */
const RECORDING_PREFIX = 'rec:';

/** @var {string} Key meaning silence. */
const NONE = 'none';

/** @var {string} Sound the server falls back to when a recording is deleted. */
const DEFAULT_CHANGEOVER = 'chime';

/** @var {Map<string, string>} Recording id => URL. */
const urls = new Map();

/** @var {WeakMap<HTMLSelectElement, HTMLButtonElement>} Enhanced selects. */
const buttons = new WeakMap();

/** @var {Object|null} Loaded strings. */
let strings = null;

/** @var {Promise|null} The strings request, shared by every caller. */
let stringsPromise = null;

/**
 * Load the strings once.
 *
 * @returns {Promise<Object>}
 */
const loadStrings = () => {
    if (!stringsPromise) {
        stringsPromise = getStrings([
            {key: 'playsound', component: 'mod_fancytimer'},
            {key: 'playsoundshort', component: 'mod_fancytimer'},
            {key: 'playsoundnone', component: 'mod_fancytimer'},
            {key: 'soundpreviewfailed', component: 'mod_fancytimer'},
            {key: 'soundgroup_recordings', component: 'mod_fancytimer'},
        ]).then(([playsound, playsoundshort, playsoundnone, failed, recordingsgroup]) => {
            strings = {playsound, playsoundshort, playsoundnone, failed, recordingsgroup};
            return strings;
        });
    }
    return stringsPromise;
};

/**
 * Read the recording URL maps on the page.
 */
const readUrlMaps = () => {
    document.querySelectorAll('[data-fancytimer-recordingurls]').forEach((el) => {
        try {
            const map = JSON.parse(el.dataset.fancytimerRecordingurls || '{}');
            Object.keys(map).forEach((id) => {
                if (typeof map[id] === 'string' && map[id] !== '') {
                    urls.set(String(id), map[id]);
                }
            });
        } catch (e) {
            // A broken map just means no recording previews.
        }
    });
};

/**
 * The descriptor audio.js plays for a select's current value.
 *
 * @param {HTMLSelectElement} select
 * @returns {Object} {type: 'builtin', key} | {type: 'recording', url} | {type: 'none'}
 */
const descriptorFor = (select) => {
    const value = select.value || NONE;
    if (value === NONE) {
        return {type: 'none'};
    }
    if (value.startsWith(RECORDING_PREFIX)) {
        const url = urls.get(value.substring(RECORDING_PREFIX.length));
        return url ? {type: 'recording', url} : {type: 'none'};
    }
    return {type: 'builtin', key: value};
};

/**
 * Bring a button's accessible name and disabled state in line with its select.
 *
 * @param {HTMLSelectElement} select
 */
const refresh = (select) => {
    const button = buttons.get(select);
    if (!button || !strings) {
        return;
    }
    const option = select.selectedOptions && select.selectedOptions[0];
    const descriptor = descriptorFor(select);
    const silent = descriptor.type === 'none';
    // A select disabled by hideIf() keeps its button disabled too.
    button.disabled = silent || select.disabled;
    button.setAttribute('aria-label', silent
        ? strings.playsoundnone
        : strings.playsound.split('{$a}').join(option ? option.textContent.trim() : ''));
};

/**
 * Add the Play button after one select.
 *
 * @param {HTMLSelectElement} select
 */
const enhance = (select) => {
    if (buttons.has(select)) {
        return;
    }
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-secondary btn-sm ms-2 mod-fancytimer-soundpreview';
    button.textContent = strings.playsoundshort;
    if (select.id) {
        button.setAttribute('aria-controls', select.id);
    }
    select.insertAdjacentElement('afterend', button);
    buttons.set(select, button);

    select.addEventListener('change', () => refresh(select));
    button.addEventListener('click', async() => {
        const descriptor = descriptorFor(select);
        if (descriptor.type === 'none') {
            return;
        }
        try {
            await unlock();
            await play(descriptor);
        } catch (e) {
            addToast(strings.failed, {type: 'warning'});
        }
    });

    // Follow hideIf()/disabledIf() toggling the select's disabled attribute.
    new MutationObserver(() => refresh(select)).observe(select, {attributes: true, attributeFilter: ['disabled']});
    refresh(select);
};

/**
 * Add Play buttons to every sound select on the page. Safe to call more than once.
 *
 * @returns {Promise<void>}
 */
export const init = async() => {
    readUrlMaps();
    await loadStrings();
    document.querySelectorAll('select[data-fancytimer-sound]').forEach(enhance);
};

/**
 * Make a new recording playable and selectable in every changeover select.
 *
 * @param {number|string} id Recording id.
 * @param {string} url Its audio URL.
 * @param {string} name Its name, shown as the option text.
 * @returns {Promise<void>}
 */
export const registerRecording = async(id, url, name) => {
    await loadStrings();
    const key = RECORDING_PREFIX + id;
    urls.set(String(id), url);
    document.querySelectorAll('select[data-fancytimer-sound="changeover"]').forEach((select) => {
        if (!select.querySelector('option[value="' + CSS.escape(key) + '"]')) {
            let group = Array.from(select.querySelectorAll('optgroup'))
                .find((g) => g.dataset.fancytimerRecordings === '1' || g.label === strings.recordingsgroup);
            if (!group) {
                group = document.createElement('optgroup');
                group.label = strings.recordingsgroup;
                // The recordings go before the last group ("Other": no sound), as on the server.
                const groups = select.querySelectorAll('optgroup');
                const last = groups.length ? groups[groups.length - 1] : null;
                select.insertBefore(group, last);
            }
            group.dataset.fancytimerRecordings = '1';
            const option = document.createElement('option');
            option.value = key;
            option.textContent = name;
            group.appendChild(option);
        }
        refresh(select);
    });
};

/**
 * Forget a deleted recording: remove its option everywhere; selects using it go to their fallback (the soft chime).
 *
 * @param {number|string} id Recording id.
 */
export const removeRecording = (id) => {
    const key = RECORDING_PREFIX + id;
    urls.delete(String(id));
    document.querySelectorAll('select[data-fancytimer-sound]').forEach((select) => {
        const option = select.querySelector('option[value="' + CSS.escape(key) + '"]');
        if (option) {
            const wasselected = option.selected;
            const group = option.parentElement;
            option.remove();
            if (group && group.tagName === 'OPTGROUP' && !group.querySelector('option')) {
                group.remove();
            }
            if (wasselected) {
                select.value = select.dataset.fancytimerFallback || DEFAULT_CHANGEOVER;
            }
        }
        refresh(select);
    });
};
