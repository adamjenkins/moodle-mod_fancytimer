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
 * The Fancy Timer display: runs the timer (control mode) or mirrors the teacher's timer (mirror mode).
 *
 * Timing uses performance.now() timestamps, never interval counting: a running segment
 * stores its end time and the remaining time is end - now, so the display cannot drift.
 * requestAnimationFrame draws; a 250 ms interval keeps the logic moving while the tab
 * is hidden (rAF stops then).
 *
 * @module     mod_fancytimer/timer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Pending from 'core/pending';
import {getStrings} from 'core/str';
import * as Audio from 'mod_fancytimer/audio';

/** @type {number} Ring circumference (r = 44 in a 100 x 100 viewBox). */
const RING_LENGTH = 2 * Math.PI * 44;

/** @type {number} Seconds added by the +30 s control and key. */
const ADD_SECONDS = 30;

/** @type {number} Going back within this many ms of a segment's start goes to the previous segment. */
const BACK_THRESHOLD_MS = 3000;

/** @type {number} State heartbeat while running, ms. */
const HEARTBEAT_MS = 5000;

/** @type {number} Poll delay after an error, ms. */
const ERROR_BACKOFF_MS = 10000;

/** @type {number} A mirror whose state is older than this while running says the teacher's timer is silent, ms. */
const MIRROR_STALE_MS = 20000;

/** @type {number} Length of the changeover flash, ms. */
const FLASH_MS = 1000;

/** @type {string[]} Strings used here (component mod_fancytimer). */
const STRING_KEYS = [
    'announceaddtime', 'announcechangeover', 'announcefinished', 'announcepaused', 'announcereset',
    'announceresumed', 'announcestarted', 'control_exitfullscreen', 'control_fullscreen', 'control_pause',
    'control_resume', 'mirrorstale', 'mirrorwaiting', 'segmentposition', 'segmentunnamed', 'statusfinished',
    'statuspaused',
];

/**
 * Format milliseconds as m:ss, or h:mm:ss from one hour, rounding up so 0:00 shows only at the end.
 *
 * @param {number} ms
 * @returns {string}
 */
const formatTime = (ms) => {
    const total = Math.max(0, Math.ceil(ms / 1000));
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    const ss = String(s).padStart(2, '0');
    return h ? `${h}:${String(m).padStart(2, '0')}:${ss}` : `${m}:${ss}`;
};

/**
 * Put parameters into a raw Moodle string ({$a} or {$a->name}).
 *
 * @param {string} text
 * @param {*} param
 * @returns {string}
 */
const fillString = (text, param) => {
    if (param === undefined || param === null) {
        return text;
    }
    if (typeof param === 'object') {
        return text.replace(/\{\$a->(\w+)\}/g, (match, name) => (name in param ? String(param[name]) : match));
    }
    return text.replace(/\{\$a\}/g, String(param));
};

/**
 * Run an ajax call without letting a jQuery Deferred leak out.
 *
 * @param {string} methodname
 * @param {object} args
 * @returns {Promise}
 */
const call = (methodname, args) => {
    const pending = new Pending('mod_fancytimer/timer:' + methodname);
    return Promise.resolve(Ajax.call([{methodname, args}])[0])
        .then((result) => {
            pending.resolve();
            return result;
        })
        .catch((error) => {
            pending.resolve();
            throw error;
        });
};

/**
 * One timer display.
 */
class TimerDisplay {
    /**
     * Constructor.
     *
     * @param {HTMLElement} root
     * @param {object} config Display config (classes/local/display_config.php).
     * @param {Object<string, string>} strings Raw strings by key.
     */
    constructor(root, config, strings) {
        this.root = root;
        this.config = config;
        this.strings = strings;
        this.segments = Array.isArray(config.segments) ? config.segments : [];
        this.control = config.mode === 'control';
        this.cyclems = Math.max(1, (config.cyclelength || 0) * 1000);

        this.state = {
            status: 'idle',
            index: 0,
            cycle: 0,
            endAt: 0,
            remainingMs: this.segmentMs(0),
            durationMs: this.segmentMs(0),
            warned: false,
        };
        this.soundNeeded = false;
        this.wakeLock = null;
        this.lastCommandId = null;
        this.pollTimer = null;
        this.polling = false;
        this.publishing = false;
        this.publishAgain = false;
        this.mirrorHasState = false;
        this.mirrorStale = false;
        this.flashTimer = null;
        this.lastRender = {};

        this.find();
        this.applySegment(false);
        this.renderControls();
        this.renderStatus();
        this.bind();
        this.loop();
        this.schedulePoll(0);
        if (this.control) {
            setInterval(() => {
                if (this.state.status === 'running') {
                    this.publish();
                }
            }, HEARTBEAT_MS);
        }
    }

    /**
     * A string, with parameters filled in.
     *
     * @param {string} key
     * @param {*} [param]
     * @returns {string}
     */
    str(key, param) {
        return fillString(this.strings[key] || key, param);
    }

    /**
     * Find the elements of the display.
     */
    find() {
        const q = (region) => this.root.querySelector(`[data-region="${region}"]`);
        this.el = {
            stage: q('stage'),
            label: q('label'),
            position: q('position'),
            imagewrap: q('imagewrap'),
            countdown: q('countdown'),
            ring: q('ring'),
            ringfill: q('ringfill'),
            status: q('status'),
            cyclebar: q('cyclebar'),
            cyclebarfill: q('cyclebarfill'),
            soundprompt: q('soundprompt'),
            announcer: q('announcer'),
            remotepanel: q('remotepanel'),
            helppanel: q('helppanel'),
            order: q('order'),
        };
        const a = (action) => this.root.querySelector(`[data-action="${action}"]`);
        this.btn = {};
        ['start', 'toggle', 'previous', 'next', 'reset', 'addtime', 'fullscreen', 'remote', 'help'].forEach((name) => {
            this.btn[name] = a(name);
        });
    }

    /**
     * Length of a playlist segment, ms.
     *
     * @param {number} index
     * @returns {number}
     */
    segmentMs(index) {
        const segment = this.segments[index];
        return segment ? Math.max(1, segment.duration) * 1000 : 0;
    }

    /**
     * Time left in the current segment, ms.
     *
     * @returns {number}
     */
    remaining() {
        if (this.state.status === 'running') {
            return Math.max(0, this.state.endAt - performance.now());
        }
        return Math.max(0, this.state.remainingMs);
    }

    /**
     * Wire up the controls, keys and page events.
     */
    bind() {
        if (this.control) {
            const on = (name, handler) => {
                if (this.btn[name]) {
                    this.btn[name].addEventListener('click', (e) => {
                        e.preventDefault();
                        handler();
                    });
                }
            };
            on('start', () => this.start(true));
            on('toggle', () => this.toggle(true));
            on('previous', () => this.previous());
            on('next', () => this.next());
            on('reset', () => this.reset());
            on('addtime', () => this.addTime(ADD_SECONDS));
            on('fullscreen', () => this.toggleFullscreen());
            on('remote', () => this.togglePanel('remote'));
            on('help', () => this.togglePanel('help'));
            document.addEventListener('keydown', (e) => this.onKey(e));
            if (this.btn.fullscreen && !this.fullscreenSupported()) {
                this.btn.fullscreen.classList.add('d-none');
            }
            ['fullscreenchange', 'webkitfullscreenchange'].forEach((name) => {
                document.addEventListener(name, () => this.renderFullscreen());
            });
        }
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                return;
            }
            this.tick();
            if (this.control && this.state.status === 'running') {
                this.acquireWakeLock();
            }
            if (!this.control && !this.polling && !this.pollTimer) {
                this.schedulePoll(0);
            }
        });
    }

    /**
     * Keyboard shortcuts (control mode).
     *
     * @param {KeyboardEvent} e
     */
    onKey(e) {
        if (e.ctrlKey || e.metaKey || e.altKey || e.defaultPrevented || this.keyBelongsToTarget(e)) {
            return;
        }
        if (e.key === 'Escape') {
            if (this.closePanels()) {
                e.preventDefault();
            }
            return;
        }
        const actions = {
            ' ': () => this.toggle(true),
            'Spacebar': () => this.toggle(true),
            'ArrowRight': () => this.next(),
            'ArrowLeft': () => this.previous(),
            'r': () => this.reset(),
            'R': () => this.reset(),
            'f': () => this.toggleFullscreen(),
            'F': () => this.toggleFullscreen(),
            '+': () => this.addTime(ADD_SECONDS),
            '=': () => this.addTime(ADD_SECONDS),
            '?': () => this.togglePanel('help'),
        };
        if (Object.prototype.hasOwnProperty.call(actions, e.key)) {
            actions[e.key]();
            e.preventDefault();
        }
    }

    /**
     * Whether a key press should be left to the focused element (form fields, and Space/Enter on buttons).
     *
     * @param {KeyboardEvent} e
     * @returns {boolean}
     */
    keyBelongsToTarget(e) {
        const target = e.target;
        if (!target || target.nodeType !== 1) {
            return false;
        }
        if (target.isContentEditable || target.closest('input, textarea, select, [contenteditable]')) {
            return true;
        }
        // A focused button handles Space and Enter itself; acting here too would press it twice.
        return (e.key === ' ' || e.key === 'Spacebar' || e.key === 'Enter') &&
            !!target.closest('button, a[href], [role="button"], summary');
    }

    /**
     * Unlock audio from a gesture, or note that sound still needs one.
     *
     * @param {boolean} fromUser Whether a click or key on this page triggered it.
     */
    unlockAudio(fromUser) {
        Audio.unlock().then(() => {
            const unlocked = Audio.isUnlocked();
            if (unlocked) {
                Audio.preload(this.soundDescriptors());
            }
            const needed = !unlocked && !fromUser;
            if (needed !== this.soundNeeded) {
                this.soundNeeded = needed;
                this.renderControls();
            }
            if (needed) {
                this.armGestureUnlock();
            }
            return undefined;
        }).catch(() => undefined);
    }

    /**
     * After a remote start without sound, unlock audio on the next click or key anywhere on the page.
     */
    armGestureUnlock() {
        if (this.gestureArmed) {
            return;
        }
        this.gestureArmed = true;
        const handler = () => {
            document.removeEventListener('pointerdown', handler, true);
            document.removeEventListener('keydown', handler, true);
            this.gestureArmed = false;
            this.unlockAudio(true);
        };
        document.addEventListener('pointerdown', handler, true);
        document.addEventListener('keydown', handler, true);
    }

    /**
     * Every sound the timer may play.
     *
     * @returns {object[]}
     */
    soundDescriptors() {
        const list = this.segments.map((s) => s.sound);
        if (this.config.warning && this.config.warning.enabled) {
            list.push({type: 'builtin', key: this.config.warning.sound});
        }
        return list;
    }

    /**
     * Start the timer (or resume it, or restart it after it finished).
     *
     * @param {boolean} fromUser
     */
    start(fromUser) {
        if (!this.segments.length) {
            return;
        }
        this.unlockAudio(fromUser);
        const s = this.state;
        if (s.status === 'running') {
            return;
        }
        if (s.status === 'paused') {
            this.resume();
            return;
        }
        if (s.status === 'finished') {
            this.setIndex(0, false);
            s.cycle = 0;
            this.applySegment(false);
        }
        s.status = 'running';
        s.endAt = performance.now() + s.remainingMs;
        this.acquireWakeLock();
        this.announce(this.str('announcestarted'));
        this.changed();
    }

    /**
     * Pause.
     */
    pause() {
        const s = this.state;
        if (s.status !== 'running') {
            return;
        }
        s.remainingMs = this.remaining();
        s.status = 'paused';
        this.releaseWakeLock();
        this.announce(this.str('announcepaused'));
        this.changed();
    }

    /**
     * Resume after a pause.
     */
    resume() {
        const s = this.state;
        if (s.status !== 'paused') {
            return;
        }
        s.status = 'running';
        s.endAt = performance.now() + s.remainingMs;
        this.acquireWakeLock();
        this.announce(this.str('announceresumed'));
        this.changed();
    }

    /**
     * Start, pause or resume, whichever fits.
     *
     * @param {boolean} fromUser
     */
    toggle(fromUser) {
        const status = this.state.status;
        if (status === 'running') {
            this.pause();
        } else if (status === 'paused') {
            this.unlockAudio(fromUser);
            this.resume();
        } else {
            this.start(fromUser);
        }
    }

    /**
     * Skip to the next segment (no changeover sound).
     */
    next() {
        const s = this.state;
        if (s.status === 'finished' || !this.segments.length) {
            return;
        }
        let index = s.index + 1;
        if (index >= this.segments.length) {
            if (!this.config.looping) {
                this.finish(false);
                return;
            }
            index = 0;
            s.cycle++;
        }
        this.setIndex(index, false);
        this.applySegment(true);
        this.changed();
    }

    /**
     * Go back: restart the current segment, or go to the previous one near its start.
     */
    previous() {
        const s = this.state;
        if (!this.segments.length) {
            return;
        }
        if (s.status === 'finished') {
            s.status = 'paused';
            this.setIndex(this.segments.length - 1, false);
            this.applySegment(true);
            this.changed();
            return;
        }
        const elapsed = s.durationMs - this.remaining();
        let index = s.index;
        if (elapsed <= BACK_THRESHOLD_MS && index > 0) {
            index--;
        }
        this.setIndex(index, false);
        this.applySegment(true);
        this.changed();
    }

    /**
     * Back to the start: idle at segment 1 with its full time.
     */
    reset() {
        const s = this.state;
        s.status = 'idle';
        s.cycle = 0;
        this.setIndex(0, false);
        this.releaseWakeLock();
        this.applySegment(false);
        this.announce(this.str('announcereset'));
        this.changed();
    }

    /**
     * Add time to the current segment.
     *
     * @param {number} seconds
     */
    addTime(seconds) {
        const s = this.state;
        const secs = Math.max(1, Math.min(600, parseInt(seconds, 10) || ADD_SECONDS));
        if (s.status === 'finished' || !this.segments.length) {
            return;
        }
        const ms = secs * 1000;
        if (s.status === 'running') {
            s.endAt += ms;
        } else {
            s.remainingMs += ms;
        }
        s.durationMs += ms;
        if (this.config.warning && this.remaining() > this.config.warning.secs * 1000) {
            s.warned = false;
        }
        this.announce(this.str('announceaddtime', secs));
        this.changed();
    }

    /**
     * Point the engine at a segment with its full time.
     *
     * @param {number} index
     * @param {number|false} carryEnd End time of the previous segment to continue from (automatic changeover), or false.
     */
    setIndex(index, carryEnd) {
        const s = this.state;
        s.index = index;
        s.durationMs = this.segmentMs(index);
        s.warned = false;
        if (s.status === 'running') {
            s.endAt = (carryEnd === false ? performance.now() : carryEnd) + s.durationMs;
        } else {
            s.remainingMs = s.durationMs;
        }
    }

    /**
     * End of the playlist without looping.
     *
     * @param {boolean} withSound Whether to play the last segment's sound (automatic end).
     */
    finish(withSound) {
        const s = this.state;
        const ended = this.segments[s.index];
        s.status = 'finished';
        s.remainingMs = 0;
        this.releaseWakeLock();
        this.applySegment(false);
        if (withSound && this.control && ended) {
            Audio.play(ended.sound);
        }
        this.flash();
        this.announce(this.str('announcefinished'));
        this.changed();
    }

    /**
     * Advance the engine: automatic changeovers and the warning cue.
     */
    tick() {
        const s = this.state;
        if (s.status !== 'running' || !this.segments.length) {
            return;
        }
        const now = performance.now();
        const warning = this.config.warning || {};
        if (this.control && warning.enabled && !s.warned && s.durationMs >= (warning.secs + 1) * 1000) {
            const left = s.endAt - now;
            if (left > 0 && left <= warning.secs * 1000) {
                s.warned = true;
                Audio.play({type: 'builtin', key: warning.sound});
            }
        }
        if (s.endAt > now) {
            return;
        }
        // One or more segments ended (several if the tab slept). Continue from each end time, not from now.
        let ended = null;
        let guard = 0;
        while (s.status === 'running' && s.endAt <= now && guard++ < 1000) {
            ended = this.segments[s.index];
            let index = s.index + 1;
            if (index >= this.segments.length) {
                if (!this.config.looping) {
                    this.finish(true);
                    return;
                }
                index = 0;
                s.cycle++;
            }
            this.setIndex(index, s.endAt);
        }
        if (this.control && ended) {
            Audio.play(ended.sound);
        }
        this.applySegment(true);
        this.changed();
    }

    /**
     * The animation loop (draws while the page is visible) plus a timer that runs the logic when it is hidden.
     */
    loop() {
        const frame = () => {
            this.tick();
            this.renderTime();
            window.requestAnimationFrame(frame);
        };
        window.requestAnimationFrame(frame);
        setInterval(() => {
            this.tick();
            if (document.hidden) {
                this.renderTime();
            }
        }, 250);
    }

    /**
     * After any state change: redraw, and publish in control mode.
     */
    changed() {
        this.renderControls();
        this.renderStatus();
        this.renderTime();
        if (this.control) {
            this.publish();
        }
    }

    /**
     * Show the current segment: label, colours, image, position, running order; optionally flash and announce.
     *
     * @param {boolean} changeover Whether this is a changeover (flash and announce).
     */
    applySegment(changeover) {
        const s = this.state;
        const segment = this.segments[s.index];
        if (!segment) {
            return;
        }
        const count = this.segments.length;
        const label = segment.label || this.str('segmentunnamed', s.index + 1);
        if (this.el.label) {
            this.el.label.textContent = label;
        }
        if (this.el.position) {
            this.el.position.textContent = this.str('segmentposition', {index: s.index + 1, count});
        }
        if (this.el.stage) {
            this.el.stage.style.backgroundColor = segment.bgcolour;
            this.el.stage.style.color = segment.textcolour;
        }
        this.renderImage(segment.imageurl);
        if (this.el.order) {
            this.el.order.querySelectorAll('[data-position]').forEach((item) => {
                const current = parseInt(item.dataset.position, 10) === s.index + 1;
                item.classList.toggle('mod-fancytimer-current', current);
                if (current) {
                    item.setAttribute('aria-current', 'step');
                } else {
                    item.removeAttribute('aria-current');
                }
            });
        }
        if (changeover) {
            this.flash();
            this.announce(this.str('announcechangeover', {index: s.index + 1, count, label}));
        }
    }

    /**
     * Show or hide the headline image.
     *
     * @param {string|null} url
     */
    renderImage(url) {
        const wrap = this.el.imagewrap;
        if (!wrap) {
            return;
        }
        let img = wrap.querySelector('img');
        if (!url) {
            wrap.classList.add('d-none');
            return;
        }
        if (!img) {
            img = document.createElement('img');
            img.className = 'mod-fancytimer-image';
            img.alt = '';
            wrap.appendChild(img);
        }
        if (img.getAttribute('src') !== url) {
            img.setAttribute('src', url);
        }
        wrap.classList.remove('d-none');
    }

    /**
     * Flash the stage for a moment (CSS swaps the animation for an outline under reduced motion).
     */
    flash() {
        const stage = this.el.stage;
        if (!stage) {
            return;
        }
        stage.classList.remove('mod-fancytimer-flash');
        // Force a reflow so the animation restarts.
        stage.getBoundingClientRect();
        stage.classList.add('mod-fancytimer-flash');
        clearTimeout(this.flashTimer);
        this.flashTimer = setTimeout(() => stage.classList.remove('mod-fancytimer-flash'), FLASH_MS);
    }

    /**
     * Say something in the polite live region.
     *
     * @param {string} text
     */
    announce(text) {
        const region = this.el.announcer;
        if (!region) {
            return;
        }
        region.textContent = '';
        // A short delay makes screen readers announce a repeated message too.
        setTimeout(() => {
            region.textContent = text;
        }, 100);
    }

    /**
     * Draw the countdown, the ring and the cycle bar (DOM writes only when something changed).
     */
    renderTime() {
        const s = this.state;
        const left = this.remaining();
        const text = formatTime(left);
        const last = this.lastRender;
        if (this.el.countdown && last.text !== text) {
            this.el.countdown.textContent = text;
            this.el.countdown.classList.toggle('mod-fancytimer-long', text.length > 5);
            last.text = text;
        }
        const segfraction = s.durationMs > 0 ? Math.min(1, Math.max(0, 1 - left / s.durationMs)) : 0;
        let before = 0;
        for (let i = 0; i < s.index && i < this.segments.length; i++) {
            before += this.segmentMs(i);
        }
        const cyclefraction = s.status === 'finished' ? 1 :
            Math.min(1, (before + segfraction * this.segmentMs(s.index)) / this.cyclems);
        const offset = (RING_LENGTH * (1 - segfraction)).toFixed(2);
        if (this.el.ringfill && last.offset !== offset) {
            this.el.ringfill.setAttribute('stroke-dashoffset', offset);
            last.offset = offset;
        }
        const width = (cyclefraction * 100).toFixed(2) + '%';
        if (this.el.cyclebarfill && last.width !== width) {
            this.el.cyclebarfill.style.width = width;
            last.width = width;
        }
        const segpct = String(Math.round(segfraction * 100));
        if (this.el.ring && last.segpct !== segpct) {
            this.el.ring.setAttribute('aria-valuenow', segpct);
            last.segpct = segpct;
        }
        const cyclepct = String(Math.round(cyclefraction * 100));
        if (this.el.cyclebar && last.cyclepct !== cyclepct) {
            this.el.cyclebar.setAttribute('aria-valuenow', cyclepct);
            last.cyclepct = cyclepct;
        }
    }

    /**
     * The status line under the countdown.
     */
    renderStatus() {
        if (!this.el.status) {
            return;
        }
        const status = this.state.status;
        let text = '';
        if (!this.control && (!this.mirrorHasState || status === 'idle')) {
            text = this.str('mirrorwaiting');
        } else if (!this.control && this.mirrorStale) {
            text = this.str('mirrorstale');
        } else if (status === 'paused') {
            text = this.str('statuspaused');
        } else if (status === 'finished') {
            text = this.str('statusfinished');
        }
        this.el.status.textContent = text;
        if (this.el.stage) {
            this.el.stage.dataset.status = status;
        }
    }

    /**
     * Show the right buttons for the state; keep focus on a visible button.
     */
    renderControls() {
        if (!this.control) {
            return;
        }
        const status = this.state.status;
        const focused = document.activeElement;
        const showStart = status === 'idle' || status === 'finished' || this.soundNeeded;
        const showToggle = status === 'running' || status === 'paused';
        if (this.btn.start) {
            this.btn.start.classList.toggle('d-none', !showStart);
        }
        if (this.btn.toggle) {
            this.btn.toggle.classList.toggle('d-none', !showToggle);
            const paused = status === 'paused';
            this.btn.toggle.textContent = this.str(paused ? 'control_resume' : 'control_pause');
            this.btn.toggle.setAttribute('aria-pressed', paused ? 'true' : 'false');
        }
        if (this.el.soundprompt) {
            this.el.soundprompt.classList.toggle('d-none', !this.soundNeeded);
        }
        if (focused === this.btn.start && !showStart && this.btn.toggle) {
            this.btn.toggle.focus();
        } else if (focused === this.btn.toggle && !showToggle && this.btn.start) {
            this.btn.start.focus();
        }
        if (this.btn.addtime) {
            this.btn.addtime.disabled = status === 'finished';
        }
        if (this.btn.next) {
            this.btn.next.disabled = status === 'finished';
        }
    }

    /**
     * Whether the browser can put an element in full screen.
     *
     * @returns {boolean}
     */
    fullscreenSupported() {
        const stage = this.el.stage;
        return !!stage && !!(stage.requestFullscreen || stage.webkitRequestFullscreen);
    }

    /**
     * The element in full screen, if any.
     *
     * @returns {Element|null}
     */
    fullscreenElement() {
        return document.fullscreenElement || document.webkitFullscreenElement || null;
    }

    /**
     * Enter or leave full screen on the stage.
     */
    toggleFullscreen() {
        const stage = this.el.stage;
        if (!stage) {
            return;
        }
        try {
            let result;
            if (this.fullscreenElement()) {
                const exit = document.exitFullscreen || document.webkitExitFullscreen;
                result = exit ? exit.call(document) : null;
            } else {
                const request = stage.requestFullscreen || stage.webkitRequestFullscreen;
                result = request ? request.call(stage) : null;
            }
            if (result && typeof result.catch === 'function') {
                result.catch(() => undefined);
            }
        } catch (e) {
            // Full screen refused (no gesture, or not allowed in this frame).
        }
    }

    /**
     * Update the full screen button and class after a change.
     */
    renderFullscreen() {
        const on = this.fullscreenElement() === this.el.stage;
        if (this.el.stage) {
            this.el.stage.classList.toggle('mod-fancytimer-isfullscreen', on);
        }
        if (this.btn.fullscreen) {
            this.btn.fullscreen.textContent = this.str(on ? 'control_exitfullscreen' : 'control_fullscreen');
            this.btn.fullscreen.setAttribute('aria-pressed', on ? 'true' : 'false');
        }
    }

    /**
     * Open or close a disclosure panel.
     *
     * @param {string} name 'remote' or 'help'.
     */
    togglePanel(name) {
        const panel = this.el[name + 'panel'];
        const button = this.btn[name];
        if (!panel || !button) {
            return;
        }
        const open = panel.hidden;
        panel.hidden = !open;
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    /**
     * Close any open panel.
     *
     * @returns {boolean} Whether one was open.
     */
    closePanels() {
        let closed = false;
        ['remote', 'help'].forEach((name) => {
            const panel = this.el[name + 'panel'];
            if (panel && !panel.hidden) {
                this.togglePanel(name);
                closed = true;
            }
        });
        return closed;
    }

    /**
     * Keep the screen awake while running, where the browser allows it.
     */
    acquireWakeLock() {
        if (!navigator.wakeLock || document.hidden || this.wakeLock || this.wakeLockRequested) {
            return;
        }
        this.wakeLockRequested = true;
        Promise.resolve(navigator.wakeLock.request('screen')).then((lock) => {
            this.wakeLockRequested = false;
            if (this.state.status !== 'running') {
                lock.release();
                return undefined;
            }
            this.wakeLock = lock;
            lock.addEventListener('release', () => {
                this.wakeLock = null;
            });
            return undefined;
        }).catch(() => {
            this.wakeLockRequested = false;
        });
    }

    /**
     * Let the screen sleep again.
     */
    releaseWakeLock() {
        if (this.wakeLock) {
            const lock = this.wakeLock;
            this.wakeLock = null;
            Promise.resolve(lock.release()).catch(() => undefined);
        }
    }

    /**
     * Publish the state for remotes and mirrors (control mode). Never two requests at once.
     */
    publish() {
        if (!this.control) {
            return;
        }
        if (this.publishing) {
            this.publishAgain = true;
            return;
        }
        this.publishing = true;
        const s = this.state;
        const state = {
            status: s.status,
            index: s.index,
            remainingms: Math.round(this.remaining()),
            durationms: Math.round(s.durationMs),
            cycle: s.cycle,
        };
        call('mod_fancytimer_publish_state', {cmid: this.config.cmid, state: JSON.stringify(state)})
            .catch(() => undefined)
            .then(() => {
                this.publishing = false;
                if (this.publishAgain) {
                    this.publishAgain = false;
                    this.publish();
                }
                return undefined;
            })
            .catch(() => undefined);
    }

    /**
     * Schedule the next poll.
     *
     * @param {number} delay ms
     */
    schedulePoll(delay) {
        clearTimeout(this.pollTimer);
        this.pollTimer = setTimeout(() => {
            this.pollTimer = null;
            this.poll();
        }, delay);
    }

    /**
     * Ask the server for remote commands (control mode) or the published state (mirror mode).
     */
    poll() {
        if (this.polling) {
            return;
        }
        if (!this.control && document.hidden) {
            // Mirrors stop polling while hidden; visibilitychange restarts them.
            return;
        }
        this.polling = true;
        const since = this.control && this.lastCommandId !== null ? this.lastCommandId : 0;
        const requestedAt = performance.now();
        call('mod_fancytimer_get_updates', {cmid: this.config.cmid, sincecommandid: since})
            .then((response) => {
                this.polling = false;
                if (this.control) {
                    this.applyCommands(response);
                } else {
                    this.applyMirror(response, requestedAt);
                }
                this.schedulePoll(this.config.pollms || 3000);
                return undefined;
            })
            .catch(() => {
                this.polling = false;
                this.schedulePoll(ERROR_BACKOFF_MS);
            });
    }

    /**
     * Run the commands a remote sent, in order.
     *
     * @param {object} response get_updates result.
     */
    applyCommands(response) {
        const commands = Array.isArray(response.commands) ? response.commands : [];
        if (this.lastCommandId === null) {
            // First call: only learn where the queue is, so old commands are not replayed.
            this.lastCommandId = parseInt(response.lastcommandid, 10) || 0;
            return;
        }
        commands.forEach((command) => {
            const id = parseInt(command.id, 10) || 0;
            if (id <= this.lastCommandId) {
                return;
            }
            this.lastCommandId = id;
            this.runCommand(command.command, parseInt(command.value, 10) || 0);
        });
        if (!commands.length) {
            this.lastCommandId = Math.max(this.lastCommandId, parseInt(response.lastcommandid, 10) || 0);
        }
    }

    /**
     * Run one remote command as if its button was pressed.
     *
     * @param {string} name
     * @param {number} value
     */
    runCommand(name, value) {
        switch (name) {
            case 'start':
                this.start(false);
                break;
            case 'pause':
                this.pause();
                break;
            case 'resume':
                if (this.state.status === 'paused') {
                    this.unlockAudio(false);
                    this.resume();
                } else if (this.state.status !== 'running') {
                    this.start(false);
                }
                break;
            case 'toggle':
                this.toggle(false);
                break;
            case 'next':
                this.next();
                break;
            case 'previous':
                this.previous();
                break;
            case 'reset':
                this.reset();
                break;
            case 'addtime':
                this.addTime(value || ADD_SECONDS);
                break;
            default:
                break;
        }
    }

    /**
     * Show the teacher's published state (mirror mode, silent).
     *
     * @param {object} response get_updates result.
     * @param {number} requestedAt performance.now() when the request was sent.
     */
    applyMirror(response, requestedAt) {
        const data = parseState(response.state);
        if (!data || !this.segments.length) {
            this.mirrorHasState = false;
            this.renderStatus();
            return;
        }
        const s = this.state;
        const now = performance.now();
        const agems = Math.max(0, parseInt(response.agems, 10) || 0);
        // Remaining now = published remaining - age at the server - time since the request was sent.
        const left = (parseInt(data.remainingms, 10) || 0) - agems - (now - requestedAt);
        const index = Math.min(this.segments.length - 1, Math.max(0, parseInt(data.index, 10) || 0));
        const status = ['idle', 'running', 'paused', 'finished'].includes(data.status) ? data.status : 'idle';
        const durationMs = Math.max(1, parseInt(data.durationms, 10) || this.segmentMs(index));

        this.mirrorStale = status === 'running' && agems > MIRROR_STALE_MS;
        this.mirrorHasState = true;

        // A running segment that has already ended by now: the local engine has moved on, keep it.
        if (status === 'running' && left <= 0 && s.status === 'running') {
            this.renderStatus();
            return;
        }
        const indexChanged = index !== s.index;
        const wasFinished = s.status === 'finished';
        s.status = status;
        s.index = index;
        s.cycle = Math.max(0, parseInt(data.cycle, 10) || 0);
        s.durationMs = durationMs;
        if (status === 'running') {
            s.endAt = now + left;
        } else {
            s.remainingMs = status === 'finished' ? 0 : Math.max(0, parseInt(data.remainingms, 10) || 0);
        }
        if (indexChanged) {
            this.applySegment(true);
        }
        if (status === 'finished' && !wasFinished) {
            this.flash();
            this.announce(this.str('announcefinished'));
        }
        this.renderStatus();
        this.renderTime();
    }
}

/**
 * Parse a published state JSON string.
 *
 * @param {string} json
 * @returns {object|null}
 */
const parseState = (json) => {
    if (!json) {
        return null;
    }
    try {
        return JSON.parse(json);
    } catch (e) {
        return null;
    }
};

/**
 * Start the display.
 *
 * @param {string} rootId Id of the root element of templates/view.mustache.
 */
export const init = (rootId) => {
    const root = document.getElementById(rootId);
    if (!root || root.dataset.fancytimerInit) {
        return;
    }
    root.dataset.fancytimerInit = '1';
    let config;
    try {
        config = JSON.parse(root.dataset.config || '{}');
    } catch (e) {
        return;
    }
    const pending = new Pending('mod_fancytimer/timer:init');
    Promise.resolve(getStrings(STRING_KEYS.map((key) => ({key, component: 'mod_fancytimer'}))))
        .then((values) => {
            const strings = {};
            STRING_KEYS.forEach((key, i) => {
                strings[key] = values[i];
            });
            return strings;
        })
        .catch(() => ({}))
        .then((strings) => {
            const display = new TimerDisplay(root, config, strings);
            pending.resolve();
            return display;
        })
        .catch(() => {
            pending.resolve();
        });
};
