// Audio + video recorders for items the admin marked with `class=record_audio`
// or `class=record_video` (the spreadsheet's wrapper class column). v1 had
// a jQuery-driven AudioRecorder.js that injects MediaRecorder + a 3-button
// group (record / play / delete) into `.controls-inner`; v2 didn't import
// it, so capture-marked items rendered as the bare native file picker.
// This is the vanilla port — verbatim audio path + an analogous video path.
// Recorded Blobs are stuffed back into the file input via DataTransfer so
// the v2 multipart submit picks them up without a separate code path.

import { formatDuration } from '../lib/time.js';

const SETUPS = [
    { selector: '.record_audio', kind: 'audio', constraints: { audio: true, video: false }, mimes: [
        'audio/webm', 'audio/webm;codecs=opus', 'audio/mp4', 'audio/aac', 'audio/x-m4a',
    ] },
    { selector: '.record_video', kind: 'video', constraints: { audio: true, video: true }, mimes: [
        'video/webm', 'video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/mp4',
    ] },
];

export function initMediaRecorders(root) {
    if (typeof MediaRecorder === 'undefined' || !navigator.mediaDevices?.getUserMedia) return;

    SETUPS.forEach((cfg) => {
        root.querySelectorAll(cfg.selector).forEach((container) => {
            if (container.dataset.fmrRecorderInit === '1') return;
            container.dataset.fmrRecorderInit = '1';
            const fileInput = container.querySelector('input[type="file"]');
            if (!fileInput) return;
            wireRecorder(container, fileInput, cfg);
        });
    });
}

function wireRecorder(container, fileInput, cfg) {
    fileInput.classList.add('js_hidden');

    const widget = document.createElement('div');
    widget.className = `fmr-recorder fmr-recorder-${cfg.kind}`;

    const group = document.createElement('div');
    group.className = 'btn-group';
    const recordBtn = makeBtn('btn-outline-primary fmr-recorder-record', cfg.kind === 'video' ? 'video-camera' : 'microphone');
    const playBtn = makeBtn('btn-outline-secondary fmr-recorder-play', 'play', true);
    const deleteBtn = makeBtn('btn-outline-danger fmr-recorder-delete', 'trash', true);
    group.append(recordBtn, playBtn, deleteBtn);

    const lengthEl = document.createElement('span');
    lengthEl.className = 'fmr-recorder-length';

    let previewEl = null;
    if (cfg.kind === 'video') {
        previewEl = document.createElement('video');
        previewEl.className = 'fmr-recorder-preview';
        previewEl.controls = true;
        previewEl.muted = true;
        previewEl.playsInline = true;
        previewEl.style.maxWidth = '100%';
        previewEl.style.display = 'none';
    }

    widget.append(group, lengthEl);
    if (previewEl) widget.appendChild(previewEl);
    (container.querySelector('.controls-inner') || container).appendChild(widget);

    let mediaRecorder = null;
    let stream = null;
    let chunks = [];
    let blob = null;
    let mimeType = '';
    let blobUrl = null;

    const supportedMime = () => cfg.mimes.find((m) => MediaRecorder.isTypeSupported(m)) || '';

    const teardownStream = () => {
        if (stream) {
            stream.getTracks().forEach((t) => t.stop());
            stream = null;
        }
        mediaRecorder = null;
    };

    const onStop = async () => {
        blob = new Blob(chunks, { type: mimeType });
        if (blobUrl) URL.revokeObjectURL(blobUrl);
        blobUrl = URL.createObjectURL(blob);

        try {
            const ext = mimeType.startsWith('video/') ? '.webm' : (mimeType.includes('mp4') ? '.m4a' : '.webm');
            const dt = new DataTransfer();
            dt.items.add(new File([blob], `recording${ext}`, { type: mimeType }));
            fileInput.files = dt.files;
            fileInput.dispatchEvent(new Event('change', { bubbles: true }));
        } catch (err) {
            console.warn('fmr-recorder: failed to attach blob to file input', err);
        }

        if (cfg.kind === 'audio') {
            try {
                const AudioCtor = window.AudioContext || window.webkitAudioContext;
                const ctx = new AudioCtor();
                const buf = await blob.arrayBuffer();
                const decoded = await ctx.decodeAudioData(buf);
                lengthEl.textContent = ` ${formatDuration(decoded.duration)}`;
            } catch {
                lengthEl.textContent = '';
            }
        } else if (previewEl) {
            previewEl.src = blobUrl;
            previewEl.style.display = '';
        }

        playBtn.disabled = false;
        deleteBtn.disabled = false;
        teardownStream();
    };

    recordBtn.addEventListener('click', async () => {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            mediaRecorder.stop();
            recordBtn.querySelector('i').className = `fa fa-${cfg.kind === 'video' ? 'video-camera' : 'microphone'}`;
            return;
        }
        mimeType = supportedMime();
        if (!mimeType) {
            alert(cfg.kind === 'video'
                ? 'Your browser does not support video recording in any required format.'
                : 'Your browser does not support audio recording in any required format.');
            return;
        }
        try {
            stream = await navigator.mediaDevices.getUserMedia(cfg.constraints);
        } catch {
            alert('Permission to record was denied or the device is unavailable.');
            return;
        }
        chunks = [];
        mediaRecorder = new MediaRecorder(stream, { mimeType });
        mediaRecorder.ondataavailable = (e) => { if (e.data && e.data.size) chunks.push(e.data); };
        mediaRecorder.onstop = onStop;
        mediaRecorder.start();
        recordBtn.querySelector('i').className = 'fa fa-stop';
    });

    playBtn.addEventListener('click', () => {
        if (!blob) return;
        if (cfg.kind === 'video' && previewEl) {
            previewEl.currentTime = 0;
            previewEl.muted = false;
            previewEl.play();
        } else {
            new Audio(blobUrl).play();
        }
    });

    deleteBtn.addEventListener('click', () => {
        chunks = [];
        blob = null;
        if (blobUrl) { URL.revokeObjectURL(blobUrl); blobUrl = null; }
        fileInput.value = '';
        lengthEl.textContent = '';
        if (previewEl) {
            previewEl.removeAttribute('src');
            previewEl.style.display = 'none';
        }
        playBtn.disabled = true;
        deleteBtn.disabled = true;
    });
}

function makeBtn(extraClass, faIcon, disabled = false) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = `btn ${extraClass}`;
    b.innerHTML = `<i class="fa fa-${faIcon}"></i>`;
    if (disabled) b.disabled = true;
    return b;
}
