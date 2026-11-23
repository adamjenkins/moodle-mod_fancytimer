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
 * Phone remote for a Fancy Timer: sends commands and mirrors the published state.
 *
 * @module     mod_fancytimer/remote
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Pending from 'core/pending';
import {getString, getStrings} from 'core/str';

/** @var {number} Poll interval after an error, ms. */
const ERROR_POLL_MS = 10000;

/** @var {number} A running timer whose state is older than this is treated as disconnected, ms. */
const STALE_MS = 15000;

/** @var {number} How long the pressed feedback stays on a button, ms. */
const PRESSED_MS = 400;

/** @var {string[]} Lang string keys used by this module. */
const STRING_KEYS = [
    'remote_pause', 'remote_resume', 'remote_connected', 'remote_notconnected_short', 'remote_waiting',
    'remote_status_idle', 'remote_status_running', 'remote_status_paused', 'remote_status_finished',
    'remote_commandfailed',
];

/**
 * Call one external function, as a native promise.
 *
 * @param {string} methodname
 * @param {Object} args
 * @returns {Promise<Object>}
 */
const call = (methodname, args) => Promise.resolve(Ajax.call([{methodname, args}])[0]);

/**
 * Format milliseconds as m:ss (minutes may exceed 59).
 *
 * @param {number} ms
 * @returns {string}
 */
const formatTime = (ms) => {
    const total = Math.max(0, Math.ceil(ms / 1000));
    const minutes = Math.floor(total / 60);
    const seconds = total % 60;
    return minutes + ':' + String(seconds).padStart(2, '0');
};

/**
 * Start the remote.
 *
 * @param {string} rootId Id of the root element of templates/remote.mustache.
 */
export const init = async(rootId) => {
    const root = document.getElementById(rootId);
    if (!root || root.dataset.initialised) {
        return;
    }
    root.dataset.initialised = '1';

    let config;
    try {
        config = JSON.parse(root.dataset.config || '{}');
    } catch (e) {
        return;
    }
    const segments = Array.isArray(config.segments) ? config.segments : [];
    const pollMs = Math.max(500, parseInt(config.pollms, 10) || 1500);

    const region = (name) => root.querySelector('[data-region="' + name + '"]');
    const els = {
        connection: region('connection-text'),
        label: region('label'),
        countdown: region('countdown'),
        statustext: region('statustext'),
        position: region('position'),
        announce: region('announce'),
        notconnected: region('notconnected'),
        error: region('error'),
        toggle: region('toggle'),
    };

    const values = await getStrings(STRING_KEYS.map((key) => ({key, component: 'mod_fancytimer'})));
    const str = {};
    STRING_KEYS.forEach((key, i) => {
        str[key] = values[i];
    });

    /** Latest state as received: {status, index, remainingms, durationms, cycle} or null. */
    let state = null;
    /** The performance.now() time when the state was received, minus its age. */
    let stateAt = 0;
    let connected = null;
    let errorMode = false;
    let pollTimer = null;
    let polling = false;
    let tickTimer = null;
    let lastAnnounced = '';
    let positionKey = '';
    let firstPoll = true;

    /**
     * Remaining time of the current segment, counted down locally between polls.
     *
     * @returns {number} ms
     */
    const remainingNow = () => {
        if (!state) {
            return 0;
        }
        if (state.status !== 'running') {
            return state.remainingms;
        }
        return Math.max(0, state.remainingms - (performance.now() - stateAt));
    };

    /**
     * Show or hide the "not connected" state.
     *
     * @param {boolean} isconnected
     */
    const setConnected = (isconnected) => {
        if (connected === isconnected) {
            return;
        }
        connected = isconnected;
        els.notconnected.classList.toggle('d-none', isconnected);
        els.connection.textContent = isconnected ? str.remote_connected : str.remote_notconnected_short;
        els.connection.classList.toggle('text-bg-success', isconnected);
        els.connection.classList.toggle('text-bg-warning', !isconnected);
        els.connection.classList.remove('text-bg-secondary');
    };

    /**
     * Update the countdown text.
     */
    const renderCountdown = () => {
        els.countdown.textContent = state ? formatTime(remainingNow()) : '-:--';
    };

    /**
     * Render the whole status panel from the state.
     */
    const render = async() => {
        const status = state ? state.status : 'idle';
        root.dataset.status = status;
        const segment = state ? segments[state.index] : null;
        els.label.textContent = segment ? segment.label : '';
        els.statustext.textContent = state ? str['remote_status_' + status] : str.remote_waiting;
        els.toggle.textContent = status === 'running' ? str.remote_pause : str.remote_resume;
        renderCountdown();

        const key = state && segments.length ? (state.index + 1) + '/' + segments.length : '';
        if (key !== positionKey) {
            positionKey = key;
            els.position.textContent = key ? await getString('remote_segmentof', 'mod_fancytimer',
                {index: state.index + 1, total: segments.length}) : '';
        }

        const announcement = state ? [els.label.textContent, els.statustext.textContent].join('. ') : '';
        if (announcement !== lastAnnounced) {
            lastAnnounced = announcement;
            els.announce.textContent = announcement;
        }

        if (tickTimer) {
            clearInterval(tickTimer);
            tickTimer = null;
        }
        if (status === 'running') {
            tickTimer = setInterval(renderCountdown, 250);
        }
    };

    /**
     * Apply a get_updates response.
     *
     * @param {Object} response
     */
    const applyResponse = (response) => {
        const agems = parseInt(response.agems, 10);
        let parsed = null;
        if (response.state) {
            try {
                parsed = JSON.parse(response.state);
            } catch (e) {
                parsed = null;
            }
        }
        if (parsed && typeof parsed.status === 'string') {
            const age = Math.max(0, agems);
            state = {
                status: parsed.status,
                index: Math.max(0, parseInt(parsed.index, 10) || 0),
                remainingms: Math.max(0, parseInt(parsed.remainingms, 10) || 0),
                durationms: Math.max(0, parseInt(parsed.durationms, 10) || 0),
                cycle: Math.max(0, parseInt(parsed.cycle, 10) || 0),
            };
            stateAt = performance.now() - age;
        } else {
            state = null;
        }
        const stale = agems === -1 || (state !== null && state.status === 'running' && agems > STALE_MS);
        setConnected(!stale);
        render();
    };

    /**
     * Schedule the next poll.
     *
     * @param {number} delay ms
     */
    const schedule = (delay) => {
        if (pollTimer) {
            clearTimeout(pollTimer);
        }
        pollTimer = setTimeout(poll, delay);
    };

    /**
     * Poll get_updates once, then schedule the next poll. Never overlaps.
     */
    const poll = async() => {
        pollTimer = null;
        if (polling || document.hidden) {
            return;
        }
        polling = true;
        const pending = firstPoll ? new Pending('mod_fancytimer/remote:poll') : null;
        firstPoll = false;
        try {
            const response = await call('mod_fancytimer_get_updates', {cmid: config.cmid, sincecommandid: 0});
            errorMode = false;
            applyResponse(response);
        } catch (e) {
            errorMode = true;
            setConnected(false);
        } finally {
            polling = false;
            if (pending) {
                pending.resolve();
            }
        }
        if (!document.hidden) {
            schedule(errorMode ? ERROR_POLL_MS : pollMs);
        }
    };

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            if (pollTimer) {
                clearTimeout(pollTimer);
                pollTimer = null;
            }
        } else if (!polling) {
            schedule(0);
        }
    });

    /**
     * The command a button sends, given the current state.
     *
     * @param {HTMLElement} button
     * @returns {string}
     */
    const commandFor = (button) => {
        const command = button.dataset.command;
        if (command !== 'toggle' || !state) {
            return command;
        }
        if (state.status === 'running') {
            return 'pause';
        }
        return state.status === 'paused' ? 'resume' : 'start';
    };

    root.addEventListener('click', async(e) => {
        const button = e.target.closest('button[data-command]');
        if (!button || !root.contains(button) || button.getAttribute('aria-disabled') === 'true') {
            return;
        }
        e.preventDefault();
        const command = commandFor(button);
        const value = command === 'addtime' ? (parseInt(button.dataset.value, 10) || 30) : 0;
        const pending = new Pending('mod_fancytimer/remote:command');
        button.setAttribute('aria-disabled', 'true');
        button.classList.add('mod-fancytimer-remote-sent');
        setTimeout(() => button.classList.remove('mod-fancytimer-remote-sent'), PRESSED_MS);
        try {
            await call('mod_fancytimer_send_command', {cmid: config.cmid, command, value});
            els.error.classList.add('d-none');
            els.error.textContent = '';
            // The classroom screen picks the command up on its next poll; look for the result soon.
            if (!polling) {
                schedule(pollMs);
            }
        } catch (err) {
            els.error.textContent = str.remote_commandfailed;
            els.error.classList.remove('d-none');
        } finally {
            button.removeAttribute('aria-disabled');
            pending.resolve();
        }
    });

    render();
    poll();
};
