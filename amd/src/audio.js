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
 * Sound playback for Fancy Timer: synthesised built-in sounds and teacher recordings (Web Audio API).
 *
 * One shared AudioContext is created lazily. Browsers start it suspended until a user
 * gesture, so unlock() must be called from a click (the Start button). Every function
 * here resolves rather than rejects: a sound that cannot play is skipped silently.
 *
 * @module     mod_fancytimer/audio
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @type {string[]} Keys of the built-in sounds. */
export const BUILTIN_KEYS = ['chime', 'bell', 'gong', 'buzzer', 'alarm', 'klaxon', 'tick', 'pulse'];

/** @type {number} Master volume (before the limiter). */
const MASTER_GAIN = 0.5;

/** @type {number} Longest wait for AudioContext.resume(), ms. */
const RESUME_TIMEOUT_MS = 1000;

/** @type {number} Longest wait for fetching and decoding one recording, ms. */
const LOAD_TIMEOUT_MS = 15000;

/** @type {AudioContext|null} */
let context = null;

/** @type {AudioNode|null} Input of the master chain (gain, then a limiter). */
let master = null;

/** @type {Map<string, Promise<AudioBuffer|null>>} Decoded recordings by URL. */
const cache = new Map();

/** @type {AudioBuffer|null} A short buffer of white noise, made once. */
let noiseBuffer = null;

/**
 * Resolve with a fallback value if a promise has not settled in time; never rejects.
 *
 * @param {Promise} promise
 * @param {number} ms
 * @param {*} fallback
 * @returns {Promise}
 */
const withTimeout = (promise, ms, fallback) => new Promise((resolve) => {
    const timer = setTimeout(() => resolve(fallback), ms);
    Promise.resolve(promise).then((value) => {
        clearTimeout(timer);
        resolve(value);
        return value;
    }).catch(() => {
        clearTimeout(timer);
        resolve(fallback);
    });
});

/**
 * The shared AudioContext, created on first use (null when the browser has no Web Audio).
 *
 * @returns {AudioContext|null}
 */
const getContext = () => {
    if (context) {
        return context;
    }
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass) {
        return null;
    }
    try {
        context = new AudioContextClass();
        const gain = context.createGain();
        gain.gain.value = MASTER_GAIN;
        const limiter = context.createDynamicsCompressor();
        limiter.threshold.value = -6;
        limiter.knee.value = 6;
        limiter.ratio.value = 12;
        limiter.attack.value = 0.003;
        limiter.release.value = 0.25;
        gain.connect(limiter);
        limiter.connect(context.destination);
        master = gain;
    } catch (e) {
        context = null;
        master = null;
    }
    return context;
};

/**
 * Whether sound can play now (the AudioContext exists and is running).
 *
 * @returns {boolean}
 */
export const isUnlocked = () => !!context && context.state === 'running';

/**
 * Create or resume the AudioContext. Call from a user gesture.
 *
 * @returns {Promise<void>}
 */
export const unlock = () => {
    const ctx = getContext();
    if (!ctx) {
        return Promise.resolve();
    }
    if (ctx.state === 'running') {
        return Promise.resolve();
    }
    let resumed;
    try {
        resumed = ctx.resume();
    } catch (e) {
        resumed = Promise.resolve();
    }
    // Some mobile browsers only unlock after a sound starts inside the gesture: play one silent sample.
    try {
        const source = ctx.createBufferSource();
        source.buffer = ctx.createBuffer(1, 1, 22050);
        source.connect(ctx.destination);
        source.start(0);
    } catch (e) {
        // Nothing to do: the silent sample is only a help.
    }
    return withTimeout(resumed, RESUME_TIMEOUT_MS, undefined).then(() => undefined);
};

/**
 * Fetch and decode a recording (cached by URL). Resolves null on any failure.
 *
 * @param {string} url
 * @returns {Promise<AudioBuffer|null>}
 */
const loadRecording = (url) => {
    if (cache.has(url)) {
        return cache.get(url);
    }
    const ctx = getContext();
    if (!ctx || !url) {
        return Promise.resolve(null);
    }
    const work = fetch(url, {credentials: 'same-origin'})
        .then((response) => {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.arrayBuffer();
        })
        .then((data) => new Promise((resolve, reject) => {
            // Older Safari only supports the callback form; newer browsers return a promise too.
            const result = ctx.decodeAudioData(data, resolve, reject);
            if (result && typeof result.then === 'function') {
                result.then(resolve, reject); // eslint-disable-line promise/catch-or-return, promise/no-nesting
            }
        }));
    const promise = withTimeout(work, LOAD_TIMEOUT_MS, null).then((buffer) => {
        if (!buffer) {
            // Allow a later retry.
            cache.delete(url);
        }
        return buffer;
    });
    cache.set(url, promise);
    return promise;
};

/**
 * Fetch and decode the recordings among some sound descriptors, so changeovers start on time.
 *
 * @param {Array<{type: string, url: string}>} descriptors
 * @returns {Promise<void>} Never rejects.
 */
export const preload = (descriptors) => {
    const list = Array.isArray(descriptors) ? descriptors : [];
    const loads = list
        .filter((d) => d && d.type === 'recording' && d.url)
        .map((d) => loadRecording(d.url));
    return Promise.all(loads).then(() => undefined).catch(() => undefined);
};

/**
 * A white-noise buffer (1 s), made once.
 *
 * @param {AudioContext} ctx
 * @returns {AudioBuffer}
 */
const getNoise = (ctx) => {
    if (!noiseBuffer) {
        const length = ctx.sampleRate;
        noiseBuffer = ctx.createBuffer(1, length, ctx.sampleRate);
        const data = noiseBuffer.getChannelData(0);
        for (let i = 0; i < length; i++) {
            data[i] = Math.random() * 2 - 1;
        }
    }
    return noiseBuffer;
};

/**
 * Schedule one oscillator note with an attack / exponential-decay envelope.
 *
 * @param {AudioContext} ctx
 * @param {AudioNode} out Destination.
 * @param {object} note
 * @param {string} note.type Oscillator type.
 * @param {number} note.freq Frequency, Hz.
 * @param {number} note.start Start time (context seconds).
 * @param {number} note.peak Peak gain.
 * @param {number} note.attack Attack, s.
 * @param {number} note.decay Decay to silence, s.
 * @param {number} [note.hold] Time at peak before decaying, s.
 * @returns {OscillatorNode}
 */
const note = (ctx, out, {type, freq, start, peak, attack, decay, hold = 0}) => {
    const osc = ctx.createOscillator();
    const env = ctx.createGain();
    osc.type = type;
    osc.frequency.setValueAtTime(freq, start);
    env.gain.setValueAtTime(0.0001, start);
    env.gain.exponentialRampToValueAtTime(peak, start + attack);
    env.gain.setValueAtTime(peak, start + attack + hold);
    env.gain.exponentialRampToValueAtTime(0.0001, start + attack + hold + decay);
    osc.connect(env);
    env.connect(out);
    osc.start(start);
    osc.stop(start + attack + hold + decay + 0.05);
    return osc;
};

/**
 * Schedule a burst of filtered noise.
 *
 * @param {AudioContext} ctx
 * @param {AudioNode} out
 * @param {object} burst
 * @param {number} burst.start
 * @param {number} burst.duration
 * @param {number} burst.peak
 * @param {string} burst.filter Biquad filter type.
 * @param {number} burst.freq Filter frequency.
 * @param {number} [burst.q] Filter Q.
 * @param {number} [burst.attack]
 */
const noise = (ctx, out, {start, duration, peak, filter, freq, q = 1, attack = 0.002}) => {
    const source = ctx.createBufferSource();
    source.buffer = getNoise(ctx);
    const biquad = ctx.createBiquadFilter();
    biquad.type = filter;
    biquad.frequency.value = freq;
    biquad.Q.value = q;
    const env = ctx.createGain();
    env.gain.setValueAtTime(0.0001, start);
    env.gain.exponentialRampToValueAtTime(peak, start + attack);
    env.gain.exponentialRampToValueAtTime(0.0001, start + Math.max(attack + 0.01, duration));
    source.connect(biquad);
    biquad.connect(env);
    env.connect(out);
    source.start(start);
    source.stop(start + duration + 0.05);
};

/**
 * Synthesisers for the built-in sounds. Each schedules its notes and returns its length in seconds.
 *
 * @type {Object<string, function(AudioContext, AudioNode, number): number>}
 */
const SYNTHS = {
    // Two-tone cabin call chime: a high "bing" then a lower "bong", sine with soft harmonics.
    chime: (ctx, out, t) => {
        [[932.33, 0], [739.99, 0.55]].forEach(([freq, offset]) => {
            note(ctx, out, {type: 'sine', freq, start: t + offset, peak: 0.45, attack: 0.008, decay: 1.1});
            note(ctx, out, {type: 'sine', freq: freq * 2, start: t + offset, peak: 0.08, attack: 0.008, decay: 0.6});
            note(ctx, out, {type: 'sine', freq: freq * 3, start: t + offset, peak: 0.03, attack: 0.008, decay: 0.35});
        });
        return 1.7;
    },
    // A struck bell: inharmonic partials, the low ones ringing longest, plus a short strike noise.
    bell: (ctx, out, t) => {
        const base = 523.25;
        [[0.5, 0.18, 3.2], [1, 0.3, 2.8], [1.19, 0.14, 2.2], [1.56, 0.1, 1.8], [2, 0.08, 1.5],
            [2.74, 0.05, 1.1], [3, 0.04, 0.9], [4.07, 0.03, 0.6]].forEach(([ratio, peak, decay]) => {
            note(ctx, out, {type: 'sine', freq: base * ratio, start: t, peak, attack: 0.003, decay});
        });
        noise(ctx, out, {start: t, duration: 0.04, peak: 0.12, filter: 'bandpass', freq: 3500, q: 2});
        return 3.3;
    },
    // A low gong: inharmonic partials that swell in, a slight downward pitch bend and a filtered noise wash.
    gong: (ctx, out, t) => {
        const base = 98;
        [[1, 0.3], [1.48, 0.16], [1.97, 0.12], [2.44, 0.08], [2.9, 0.06], [3.6, 0.04]].forEach(([ratio, peak], i) => {
            const osc = note(ctx, out, {type: 'sine', freq: base * ratio, start: t, peak,
                attack: 0.25 + i * 0.08, hold: 0.1, decay: 3.8 - i * 0.3});
            osc.frequency.exponentialRampToValueAtTime(base * ratio * 0.985, t + 3);
        });
        noise(ctx, out, {start: t, duration: 2.2, peak: 0.05, filter: 'lowpass', freq: 600, q: 0.7, attack: 0.4});
        return 4.3;
    },
    // A harsh buzz: detuned square and sawtooth through a low-pass filter.
    buzzer: (ctx, out, t) => {
        const filter = ctx.createBiquadFilter();
        filter.type = 'lowpass';
        filter.frequency.value = 2200;
        filter.connect(out);
        note(ctx, filter, {type: 'square', freq: 110, start: t, peak: 0.22, attack: 0.01, hold: 0.7, decay: 0.08});
        note(ctx, filter, {type: 'sawtooth', freq: 113, start: t, peak: 0.22, attack: 0.01, hold: 0.7, decay: 0.08});
        return 0.85;
    },
    // Three fast urgent beeps.
    alarm: (ctx, out, t) => {
        const filter = ctx.createBiquadFilter();
        filter.type = 'lowpass';
        filter.frequency.value = 4000;
        filter.connect(out);
        [0, 0.18, 0.36].forEach((offset) => {
            note(ctx, filter, {type: 'square', freq: 1046.5, start: t + offset, peak: 0.25, attack: 0.005, hold: 0.1,
                decay: 0.03});
        });
        return 0.55;
    },
    // An "awooga" horn: a sawtooth that falls then rises, played twice.
    klaxon: (ctx, out, t) => {
        const filter = ctx.createBiquadFilter();
        filter.type = 'lowpass';
        filter.frequency.value = 1800;
        filter.Q.value = 3;
        filter.connect(out);
        [0, 0.8].forEach((offset) => {
            const start = t + offset;
            const osc = note(ctx, filter, {type: 'sawtooth', freq: 420, start, peak: 0.3, attack: 0.02, hold: 0.55,
                decay: 0.08});
            osc.frequency.setValueAtTime(420, start);
            osc.frequency.exponentialRampToValueAtTime(260, start + 0.22);
            osc.frequency.exponentialRampToValueAtTime(470, start + 0.6);
        });
        return 1.5;
    },
    // A soft short click.
    tick: (ctx, out, t) => {
        noise(ctx, out, {start: t, duration: 0.03, peak: 0.25, filter: 'bandpass', freq: 3000, q: 4});
        note(ctx, out, {type: 'sine', freq: 1500, start: t, peak: 0.12, attack: 0.002, decay: 0.03});
        return 0.1;
    },
    // A soft low sine blip.
    pulse: (ctx, out, t) => {
        note(ctx, out, {type: 'sine', freq: 220, start: t, peak: 0.4, attack: 0.02, decay: 0.28});
        note(ctx, out, {type: 'sine', freq: 440, start: t, peak: 0.05, attack: 0.02, decay: 0.15});
        return 0.35;
    },
};

/**
 * A promise that resolves after some seconds.
 *
 * @param {number} seconds
 * @returns {Promise<void>}
 */
const wait = (seconds) => new Promise((resolve) => {
    setTimeout(resolve, Math.max(0, seconds * 1000));
});

/**
 * Play a built-in sound.
 *
 * @param {string} key One of BUILTIN_KEYS.
 * @returns {Promise<void>} Resolves when the sound has finished; never rejects.
 */
export const playBuiltin = (key) => {
    try {
        const ctx = getContext();
        const synth = SYNTHS[key];
        if (!ctx || !master || !synth || ctx.state !== 'running') {
            return Promise.resolve();
        }
        const length = synth(ctx, master, ctx.currentTime + 0.02);
        return wait(length + 0.05);
    } catch (e) {
        return Promise.resolve();
    }
};

/**
 * Play a decoded recording.
 *
 * @param {string} url
 * @returns {Promise<void>}
 */
const playRecording = (url) => loadRecording(url).then((buffer) => {
    const ctx = getContext();
    if (!buffer || !ctx || !master || ctx.state !== 'running') {
        return undefined;
    }
    return new Promise((resolve) => {
        const source = ctx.createBufferSource();
        source.buffer = buffer;
        // Recordings get a little more level than synthesised sounds.
        const gain = ctx.createGain();
        gain.gain.value = 1.6;
        source.connect(gain);
        gain.connect(master);
        const timer = setTimeout(resolve, (buffer.duration + 1) * 1000);
        source.onended = () => {
            clearTimeout(timer);
            resolve();
        };
        source.start();
    });
}).catch(() => undefined);

/**
 * Play a sound descriptor.
 *
 * @param {{type: string, key: (string|undefined), url: (string|undefined)}} descriptor
 * @returns {Promise<void>} Resolves when the sound has finished (at once for 'none'); never rejects.
 */
export const play = (descriptor) => {
    try {
        if (!descriptor || descriptor.type === 'none') {
            return Promise.resolve();
        }
        if (descriptor.type === 'recording') {
            return playRecording(descriptor.url);
        }
        if (descriptor.type === 'builtin') {
            return playBuiltin(descriptor.key);
        }
    } catch (e) {
        // Fall through: a sound that cannot play is skipped.
    }
    return Promise.resolve();
};
