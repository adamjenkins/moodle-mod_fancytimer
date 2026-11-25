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
 * Teacher sounds on the segment editor: record with MediaRecorder or upload a file, save through
 * mod_fancytimer_save_recording, play and delete saved recordings.
 *
 * @module     mod_fancytimer/recorder
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Pending from 'core/pending';
import {getString, getStrings} from 'core/str';
import {add as addToast} from 'core/toast';
import {unlock, play} from 'mod_fancytimer/audio';
import {registerRecording, removeRecording} from 'mod_fancytimer/sound_preview';

/** @var {string[]} Container types to try, best first. */
const MIMETYPES = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4', 'audio/webm'];

/** @var {Object} File name extension => audio type; browsers often report no type, or another name, for a file. */
const EXTENSIONS = {
    mp3: 'audio/mpeg',
    wav: 'audio/wav',
    ogg: 'audio/ogg',
    oga: 'audio/ogg',
    webm: 'audio/webm',
    m4a: 'audio/mp4',
};

/** @var {number} How long getUserMedia may take (a permission prompt included), ms. */
const MIC_TIMEOUT = 30000;

/** @var {number} How long reading the blob may take, ms. */
const READ_TIMEOUT = 10000;

/**
 * Race a promise against a timeout.
 *
 * @param {Promise} promise
 * @param {number} ms
 * @returns {Promise}
 */
const withTimeout = (promise, ms) => Promise.race([
    promise,
    new Promise((resolve, reject) => {
        setTimeout(() => reject(new Error('timeout')), ms);
    }),
]);

/**
 * Format seconds as m:ss.
 *
 * @param {number} seconds
 * @returns {string}
 */
const formatTime = (seconds) => {
    const s = Math.max(0, Math.floor(seconds));
    return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
};

/**
 * Is recording possible in this browser and on this connection?
 *
 * @returns {boolean}
 */
const supported = () => !!(window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia
    && window.MediaRecorder);

/**
 * The first container type this browser can record, or '' to let it choose.
 *
 * @returns {string}
 */
const pickMimeType = () => {
    if (typeof window.MediaRecorder.isTypeSupported !== 'function') {
        return '';
    }
    return MIMETYPES.find((type) => window.MediaRecorder.isTypeSupported(type)) || '';
};

/**
 * Base64 of a blob, without the data: prefix.
 *
 * @param {Blob} blob
 * @returns {Promise<string>}
 */
const blobToBase64 = (blob) => withTimeout(new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => {
        const result = String(reader.result || '');
        const comma = result.indexOf(',');
        resolve(comma >= 0 ? result.substring(comma + 1) : '');
    };
    reader.onerror = () => reject(reader.error || new Error('read'));
    reader.readAsDataURL(blob);
}), READ_TIMEOUT);

/**
 * One recorder widget.
 */
class Recorder {
    /**
     * Set up the widget.
     *
     * @param {HTMLElement} root The template's root element.
     * @param {Object} strings Loaded strings.
     */
    constructor(root, strings) {
        this.root = root;
        this.strings = strings;
        this.cmid = parseInt(root.dataset.cmid, 10);
        this.maxseconds = parseInt(root.dataset.maxseconds, 10) || 60;
        this.canaddmore = root.dataset.canaddmore === '1';
        this.region = (name) => root.querySelector('[data-region="' + name + '"]');
        this.button = (action) => root.querySelector('[data-action="' + action + '"]');
        this.mediarecorder = null;
        this.stream = null;
        this.chunks = [];
        this.blob = null;
        this.blobtype = '';
        this.previewurl = null;
        this.timer = null;
        this.stoptimer = null;
        this.started = 0;
        this.busy = false;

        this.maxbytes = parseInt(root.dataset.maxbytes, 10) || 0;

        root.addEventListener('click', (e) => this.handleClick(e));
        this.region('file').addEventListener('change', () => this.chooseFile());
        this.button('upload').disabled = !this.canaddmore;

        if (!supported()) {
            this.region('unsupported').classList.remove('d-none');
            this.button('record').disabled = true;
        } else if (!this.canaddmore) {
            this.button('record').disabled = true;
        }
    }

    /**
     * Route clicks on the widget's buttons.
     *
     * @param {Event} e
     */
    handleClick(e) {
        const target = e.target.closest('[data-action]');
        if (!target || !this.root.contains(target) || target.disabled) {
            return;
        }
        const action = target.dataset.action;
        const item = target.closest('[data-region="item"]');
        if (action === 'record') {
            this.start();
        } else if (action === 'upload') {
            this.region('file').click();
        } else if (action === 'stop') {
            this.stop();
        } else if (action === 'save') {
            this.save();
        } else if (action === 'discard') {
            this.discard();
        } else if (action === 'play' && item) {
            this.playItem(item);
        } else if (action === 'delete' && item) {
            this.deleteItem(item, target);
        }
    }

    /**
     * Show one of the panel states: idle, recording, recorded.
     *
     * @param {string} state
     */
    show(state) {
        ['idle', 'recording', 'recorded'].forEach((name) => {
            this.region(name).classList.toggle('d-none', name !== state);
        });
    }

    /**
     * Say something in the polite status line.
     *
     * @param {string} text Plain text.
     */
    status(text) {
        this.region('status').textContent = text;
    }

    /**
     * Ask for the microphone and start recording.
     */
    async start() {
        if (this.mediarecorder || this.busy) {
            return;
        }
        this.busy = true;
        this.button('record').disabled = true;
        let stream;
        try {
            stream = await withTimeout(navigator.mediaDevices.getUserMedia({audio: true}), MIC_TIMEOUT);
        } catch (e) {
            this.busy = false;
            this.button('record').disabled = false;
            this.status(this.strings.micdenied);
            return;
        }
        this.stream = stream;
        const mimeType = pickMimeType();
        let recorder;
        try {
            recorder = mimeType ? new window.MediaRecorder(stream, {mimeType}) : new window.MediaRecorder(stream);
        } catch (e) {
            this.releaseStream();
            this.busy = false;
            this.button('record').disabled = false;
            this.status(this.strings.micdenied);
            return;
        }
        this.mediarecorder = recorder;
        this.chunks = [];
        recorder.addEventListener('dataavailable', (ev) => {
            if (ev.data && ev.data.size > 0) {
                this.chunks.push(ev.data);
            }
        });
        recorder.addEventListener('stop', () => this.finished());
        recorder.start();
        this.started = Date.now();
        this.region('elapsed').textContent = formatTime(0);
        this.timer = setInterval(() => {
            this.region('elapsed').textContent = formatTime((Date.now() - this.started) / 1000);
        }, 250);
        this.stoptimer = setTimeout(() => this.stop(), this.maxseconds * 1000);
        this.busy = false;
        this.show('recording');
        this.status(this.strings.started);
        this.button('stop').focus();
    }

    /**
     * Stop recording (the recorder's stop event then calls finished()).
     */
    stop() {
        clearTimeout(this.stoptimer);
        clearInterval(this.timer);
        if (this.mediarecorder && this.mediarecorder.state !== 'inactive') {
            this.mediarecorder.stop();
        }
    }

    /**
     * Stop and forget the microphone stream.
     */
    releaseStream() {
        if (this.stream) {
            this.stream.getTracks().forEach((track) => track.stop());
        }
        this.stream = null;
    }

    /**
     * Recording has stopped: build the blob and offer the preview.
     */
    finished() {
        const recorder = this.mediarecorder;
        this.mediarecorder = null;
        this.releaseStream();
        clearTimeout(this.stoptimer);
        clearInterval(this.timer);
        this.blobtype = (recorder && recorder.mimeType) || (this.chunks[0] && this.chunks[0].type) || 'audio/webm';
        this.blob = new Blob(this.chunks, {type: this.blobtype});
        this.chunks = [];
        this.button('record').disabled = !this.canaddmore;
        if (!this.blob.size) {
            this.blob = null;
            this.show('idle');
            this.status(this.strings.empty);
            return;
        }
        this.offer(this.blob, this.blobtype, this.strings.defaultname, this.strings.stopped);
    }

    /**
     * Offer the preview of a sound file the teacher chose.
     */
    chooseFile() {
        const input = this.region('file');
        const file = input.files && input.files[0];
        if (!file || this.busy || this.mediarecorder) {
            return;
        }
        const match = /^(.*)\.([^.]+)$/.exec(file.name);
        const type = match ? EXTENSIONS[match[2].toLowerCase()] : null;
        if (!type) {
            this.status(this.strings.badtype);
        } else if (!file.size || (this.maxbytes && file.size > this.maxbytes)) {
            this.status(this.strings.badsize);
        } else {
            this.offer(file, type, match[1].substring(0, 255), this.strings.uploadready);
        }
        // Choosing the same file again must fire a change event.
        input.value = '';
    }

    /**
     * Show a new sound for listening, naming and saving.
     *
     * @param {Blob} blob
     * @param {string} type Audio type.
     * @param {string} suggested Suggested name.
     * @param {string} message Status text.
     */
    offer(blob, type, suggested, message) {
        this.clearPreview();
        this.blob = blob;
        this.blobtype = type;
        this.previewurl = URL.createObjectURL(blob);
        this.region('preview').src = this.previewurl;
        const name = this.region('name');
        name.value = suggested;
        this.show('recorded');
        this.status(message);
        name.focus();
        name.select();
    }

    /**
     * Release the preview's object URL.
     */
    clearPreview() {
        const preview = this.region('preview');
        preview.pause();
        preview.removeAttribute('src');
        if (this.previewurl) {
            URL.revokeObjectURL(this.previewurl);
        }
        this.previewurl = null;
    }

    /**
     * Focus Record, or Upload where recording is not available.
     */
    focusIdle() {
        const record = this.button('record');
        (record.disabled ? this.button('upload') : record).focus();
    }

    /**
     * Throw the new recording away.
     */
    discard() {
        this.clearPreview();
        this.blob = null;
        this.show('idle');
        this.status(this.strings.discarded);
        this.focusIdle();
    }

    /**
     * Upload the new recording.
     */
    async save() {
        if (!this.blob || this.busy) {
            return;
        }
        const name = this.region('name').value.trim() || this.strings.defaultname;
        const savebutton = this.button('save');
        const pending = new Pending('mod_fancytimer/recorder:save');
        this.busy = true;
        savebutton.disabled = true;
        this.status(this.strings.saving);
        try {
            const audio = await blobToBase64(this.blob);
            const result = await Promise.resolve(Ajax.call([{
                methodname: 'mod_fancytimer_save_recording',
                args: {cmid: this.cmid, name, mimetype: this.blobtype, audio},
            }])[0]);
            this.addItem(result.id, result.name, result.url);
            await registerRecording(result.id, result.url, result.name);
            this.clearPreview();
            this.blob = null;
            this.show('idle');
            this.status(this.strings.saved);
            this.focusIdle();
        } catch (e) {
            this.status('');
            Notification.exception(e);
        } finally {
            this.busy = false;
            savebutton.disabled = false;
            pending.resolve();
        }
    }

    /**
     * Add a row to the saved recordings list.
     *
     * @param {number} id
     * @param {string} name
     * @param {string} url
     */
    addItem(id, name, url) {
        const li = document.createElement('li');
        li.className = 'd-flex flex-wrap align-items-center py-2 border-bottom';
        li.dataset.region = 'item';
        li.dataset.recordingid = String(id);
        li.dataset.url = url || '';

        const label = document.createElement('span');
        label.className = 'me-auto';
        label.dataset.region = 'itemname';
        label.textContent = name;
        li.appendChild(label);

        const makeButton = (action, text, className) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = className;
            button.dataset.action = action;
            button.appendChild(document.createTextNode(text));
            const hidden = document.createElement('span');
            hidden.className = 'visually-hidden';
            hidden.textContent = ' ' + name;
            button.appendChild(hidden);
            return button;
        };
        const playbutton = makeButton('play', this.strings.play, 'btn btn-secondary btn-sm ms-2');
        playbutton.disabled = !url;
        li.appendChild(playbutton);
        li.appendChild(makeButton('delete', this.strings.delete, 'btn btn-outline-danger btn-sm ms-2'));

        this.region('list').appendChild(li);
        this.region('empty').classList.add('d-none');
        this.updateLimit();
    }

    /**
     * Enable or disable recording by the number of saved recordings.
     */
    updateLimit() {
        const max = parseInt(this.root.dataset.maxrecordings || '0', 10);
        if (max) {
            this.canaddmore = this.region('list').querySelectorAll('[data-region="item"]').length < max;
        }
        this.region('full').classList.toggle('d-none', this.canaddmore);
        this.button('upload').disabled = !this.canaddmore;
        if (!this.mediarecorder && supported()) {
            this.button('record').disabled = !this.canaddmore;
        }
    }

    /**
     * Play a saved recording.
     *
     * @param {HTMLElement} item
     */
    async playItem(item) {
        const url = item.dataset.url;
        if (!url) {
            return;
        }
        try {
            await unlock();
            await play({type: 'recording', url});
        } catch (e) {
            addToast(this.strings.playfailed, {type: 'warning'});
        }
    }

    /**
     * Confirm, then delete a saved recording.
     *
     * @param {HTMLElement} item
     * @param {HTMLElement} trigger The delete button.
     */
    async deleteItem(item, trigger) {
        const id = parseInt(item.dataset.recordingid, 10);
        const name = item.querySelector('[data-region="itemname"]').textContent;
        try {
            // The question is HTML: pass the name as a string parameter, which core/str does not escape, so escape it.
            const safe = document.createElement('span');
            safe.textContent = name;
            const question = await getString('deleterecordingconfirm', 'mod_fancytimer', safe.innerHTML);
            await Notification.deleteCancelPromise(this.strings.deletetitle, question, this.strings.delete,
                {triggerElement: trigger});
        } catch (e) {
            // Cancelled.
            return;
        }
        const pending = new Pending('mod_fancytimer/recorder:delete');
        try {
            await Promise.resolve(Ajax.call([{
                methodname: 'mod_fancytimer_delete_recording',
                args: {cmid: this.cmid, recordingid: id},
            }])[0]);
            item.remove();
            removeRecording(id);
            if (!this.region('list').querySelector('[data-region="item"]')) {
                this.region('empty').classList.remove('d-none');
            }
            this.canaddmore = true;
            this.updateLimit();
            this.status(this.strings.deleted);
            addToast(this.strings.deleted);
            this.focusIdle();
        } catch (e) {
            Notification.exception(e);
        } finally {
            pending.resolve();
        }
    }
}

/**
 * Start the recorder on the editor page.
 *
 * @param {string} rootId Id of the recorder template's root element.
 * @returns {Promise<void>}
 */
export const init = async(rootId) => {
    const root = document.getElementById(rootId);
    if (!root || root.dataset.initialised) {
        return;
    }
    root.dataset.initialised = '1';
    const keys = ['recordingdefaultname', 'recordingmicdenied', 'recordingstarted', 'recordingstopped',
        'recordingempty', 'recordingdiscarded', 'recordingsaving', 'recordingsaved', 'playrecording',
        'deleterecording', 'deleterecordingtitle', 'recordingdeleted', 'soundpreviewfailed', 'uploadbadtype',
        'uploadready'];
    const requests = keys.map((key) => ({key, component: 'mod_fancytimer'}));
    requests.push({key: 'uploadbadsize', component: 'mod_fancytimer', param: root.dataset.maxsize});
    const values = await getStrings(requests);
    const strings = {
        defaultname: values[0],
        micdenied: values[1],
        started: values[2],
        stopped: values[3],
        empty: values[4],
        discarded: values[5],
        saving: values[6],
        saved: values[7],
        play: values[8],
        'delete': values[9],
        deletetitle: values[10],
        deleted: values[11],
        playfailed: values[12],
        badtype: values[13],
        uploadready: values[14],
        badsize: values[15],
    };
    new Recorder(root, strings);
};
