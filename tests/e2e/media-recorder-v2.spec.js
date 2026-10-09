// Audio + video recorder smoke for v2.
//
// v2 ships a vanilla port of v1's AudioRecorder (and a sibling video
// recorder) that wires MediaRecorder + getUserMedia into items the admin
// marked with `class=record_audio` / `class=record_video`. Local-Chromium
// can't actually record without a microphone/camera, so we stub
// `navigator.mediaDevices.getUserMedia` and the global `MediaRecorder`,
// inject a fake `.record_audio` container into the rendered form, ask the
// bundle to (re-)scan for recorders, then drive the synthetic record/stop
// cycle and confirm a Blob ends up in the file input.
//
// Real-device verification belongs on BrowserStack iOS/Android; we don't
// trust local Chromium to model permission prompts faithfully.

const { test, expect, bsSafeEvaluate } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v2 = require('./helpers/v2Form');

const RUN = () => runName('all_widgets', 'v2');

// Inject MediaRecorder + getUserMedia stubs that complete a record/stop
// cycle synthetically — ondataavailable + onstop fire as soon as stop() is
// called. Returns a synthetic Blob containing the byte string we passed in.
async function installMediaStubs(page, { sampleBytes = 'fmr-test-audio-blob' } = {}) {
    await bsSafeEvaluate(page, (bytes) => {
        // Replace getUserMedia with one that returns a stream-like object whose
        // tracks have a no-op stop().
        //
        // iOS Safari real-device + BS: plain `navigator.mediaDevices
        // .getUserMedia = stub` SILENTLY no-ops because the property is
        // non-writable on the device. The bundle's later call hangs on
        // the real (permissionless) getUserMedia indefinitely. Use
        // Object.defineProperty with explicit writable+configurable to
        // override the device's locked-down accessor — this works on
        // both iOS Safari and Chromium, so no platform-branching needed.
        if (!navigator.mediaDevices) {
            // jsdom-like environments — define the surface.
            Object.defineProperty(navigator, 'mediaDevices', { value: {}, configurable: true });
        }
        const fakeGUM = async () => ({
            getTracks: () => [{ stop: () => {} }],
        });
        // Override at BOTH instance and prototype. iOS Safari real-device
        // exposes `getUserMedia` on `MediaDevices.prototype`; an instance-
        // level assignment doesn't always shadow the prototype lookup from
        // bundle code (the bundle's `navigator.mediaDevices.getUserMedia`
        // resolves to the prototype slot). Replace it on whichever object
        // actually owns the property.
        const ownerOfGUM = Object.prototype.hasOwnProperty.call(navigator.mediaDevices, 'getUserMedia')
            ? navigator.mediaDevices
            : (Object.getPrototypeOf(navigator.mediaDevices) || navigator.mediaDevices);
        try {
            Object.defineProperty(ownerOfGUM, 'getUserMedia', {
                value: fakeGUM,
                writable: true,
                configurable: true,
            });
        } catch {
            // Final fallback — direct assignment if defineProperty refuses.
            try { ownerOfGUM.getUserMedia = fakeGUM; } catch {}
            navigator.mediaDevices.getUserMedia = fakeGUM;
        }

        // Stub MediaRecorder. The real one fires `dataavailable` on stop and
        // then `stop`. Our stub does the same but with a deterministic Blob.
        // Lifecycle is recorded on `window.__fmrRecorderTrace` so the test
        // can introspect what fired (e.g., when iOS Safari + BS doesn't run
        // the bundle's onStop for any reason).
        window.__fmrRecorderTrace = [];
        const trace = (msg) => { try { window.__fmrRecorderTrace.push(msg); } catch {} };
        class FakeMediaRecorder {
            constructor(_stream, opts) {
                this.mimeType = (opts && opts.mimeType) || 'audio/webm';
                this.state = 'inactive';
                this.ondataavailable = null;
                this.onstop = null;
                trace('ctor:' + this.mimeType);
            }
            static isTypeSupported() { return true; }
            start() { this.state = 'recording'; trace('start'); }
            stop() {
                this.state = 'inactive';
                trace('stop:hasOnData=' + !!this.ondataavailable + ',hasOnStop=' + !!this.onstop);
                let blob;
                try {
                    blob = new Blob([bytes], { type: this.mimeType });
                    trace('blob:size=' + blob.size + ',type=' + blob.type);
                } catch (e) { trace('blobErr:' + e); }
                try {
                    if (this.ondataavailable) this.ondataavailable({ data: blob });
                    trace('ondataavailable:fired');
                } catch (e) { trace('ondataErr:' + e); }
                try {
                    if (this.onstop) this.onstop();
                    trace('onstop:fired');
                } catch (e) { trace('onstopErr:' + e); }
            }
        }
        window.MediaRecorder = FakeMediaRecorder;

        // Stub AudioContext.decodeAudioData so the duration-display path
        // resolves cleanly with a synthetic 1-second buffer.
        const AudioCtor = function () {
            this.decodeAudioData = async () => ({ duration: 1.0 });
        };
        window.AudioContext = AudioCtor;
        window.webkitAudioContext = AudioCtor;
    }, sampleBytes);
}

test.describe('media recorders v2', () => {
    test('record_audio container gets recorder UI and synthetic blob lands in file input', async ({ page, baseURL }) => {
        const run = RUN();
        await freshParticipant(page, run, { baseURL });
        expect(page.url(), 'page should be on the participant URL, not about:blank').toContain(`/${run}/`);

        try {
            await v2.waitForBundle(page);
            // Stubs installed AFTER initial bundle load — the form has already
            // initialized once. We inject the markup, then ask the bundle to
            // re-scan via the test-only window.fmrInitMediaRecorders hook.
            await installMediaStubs(page);

            // Inject a `.record_audio` wrapper into the visible v2 page section.
            // Mirror the wrapper structure Item.php produces: form-group +
            // controls-inner + a hidden file input named `audio_test`.
            const injected = await bsSafeEvaluate(page, () => {
                const target = document.querySelector('form.fmr-form-v2 section.fmr-page:not([hidden])');
                if (!target) return false;
                const wrap = document.createElement('div');
                wrap.className = 'form-group form-row item-audio record_audio fmr-test-audio-wrap';
                wrap.innerHTML = `
                    <label class="control-label">test audio</label>
                    <div class="controls">
                        <div class="controls-inner">
                            <input type="file" name="audio_test" accept="audio/*">
                        </div>
                    </div>
                `;
                target.insertBefore(wrap, target.firstChild);
                return true;
            });
            expect(injected, 'failed to inject .record_audio markup into v2 page').toBe(true);

            // Trigger the bundle's recorder init for the new container.
            const initOk = await bsSafeEvaluate(page, () => {
                if (typeof window.fmrInitMediaRecorders !== 'function') return false;
                window.fmrInitMediaRecorders();
                return true;
            });
            expect(initOk, 'window.fmrInitMediaRecorders not exposed by bundle').toBe(true);

            // Recorder UI elements should exist now.
            const recordBtn = page.locator('.fmr-test-audio-wrap .fmr-recorder-record');
            const playBtn = page.locator('.fmr-test-audio-wrap .fmr-recorder-play');
            const deleteBtn = page.locator('.fmr-test-audio-wrap .fmr-recorder-delete');
            await expect(recordBtn).toBeVisible({ timeout: 5000 });
            await expect(playBtn).toBeVisible();
            await expect(deleteBtn).toBeVisible();

            // Hook the page's MediaRecorder.isTypeSupported and
            // navigator.mediaDevices.getUserMedia so we can tell what the
            // bundle actually sees on a failing iOS run. Logs land in
            // `window.__fmrDebug` and get pulled into the diagnostic.
            // Use defineProperty on the same owner installMediaStubs
            // wrote to, otherwise the wrap silently overlays an unwritable
            // slot on iOS Safari and `gum:start` never logs.
            await bsSafeEvaluate(page, () => {
                window.__fmrDebug = [];
                const log = (m) => { try { window.__fmrDebug.push(m); } catch {} };
                const md = navigator.mediaDevices;
                const ownerOfGUM = md && (Object.prototype.hasOwnProperty.call(md, 'getUserMedia')
                    ? md
                    : (Object.getPrototypeOf(md) || md));
                const realGUM = md && md.getUserMedia;
                if (realGUM && ownerOfGUM) {
                    const wrapped = async function (...a) {
                        log('gum:start:' + JSON.stringify(a));
                        try { const r = await realGUM.apply(this, a); log('gum:resolved:' + (r && typeof r.getTracks === 'function' ? 'streamLike' : typeof r)); return r; }
                        catch (e) { log('gum:rejected:' + (e && e.name) + ':' + (e && e.message)); throw e; }
                    };
                    try {
                        Object.defineProperty(ownerOfGUM, 'getUserMedia', {
                            value: wrapped, writable: true, configurable: true,
                        });
                    } catch (e) {
                        log('gumWrap:defineFailed:' + (e && e.message));
                        try { md.getUserMedia = wrapped; } catch (e2) { log('gumWrap:assignFailed:' + (e2 && e2.message)); }
                    }
                    log('gumWrap:owner=' + (ownerOfGUM === md ? 'instance' : 'prototype') + ',is=' + (md.getUserMedia === wrapped ? 'wrapped' : 'NOT-wrapped'));
                }
                if (typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported) {
                    const realIts = MediaRecorder.isTypeSupported.bind(MediaRecorder);
                    MediaRecorder.isTypeSupported = function (m) { const r = realIts(m); log('its:' + m + ':' + r); return r; };
                }
                log('hooks:installed:mrName=' + (window.MediaRecorder && window.MediaRecorder.name));
            });

            // Click record → click stop → verify file input has a File.
            // Programmatic .click() in the page rather than recordBtn.click()
            // — Playwright's actionability click on BS iOS Safari sometimes
            // doesn't actually fire the addEventListener handler (the click
            // event reaches the button but the listener doesn't run). The
            // FakeMediaRecorder lifecycle trace was empty after Playwright
            // clicks, but `el.click()` from inside the page consistently
            // runs the handler.
            await bsSafeEvaluate(page, () => {
                document.querySelector('.fmr-test-audio-wrap .fmr-recorder-record').click();
            });
            await bsSafeEvaluate(page, () => {
                document.querySelector('.fmr-test-audio-wrap .fmr-recorder-record').click();
            });
            await page.waitForTimeout(1500);

            // Probe the bundle's DataTransfer path *and* the input.files
            // result. iOS Safari historically rejects programmatic
            // `input.files = dt.files` (security on file inputs); when the
            // bundle's `try { … } catch` quietly swallows that, the file
            // input is empty even though the recorder UI thinks it
            // delivered the blob. Diagnostics tell us which branch.
            const diagnostic = await bsSafeEvaluate(page, () => {
                const input = document.querySelector('.fmr-test-audio-wrap input[type=file]');
                const probe = {
                    hasInput: !!input,
                    dataTransferCtor: typeof DataTransfer,
                    fileCtor: typeof File,
                    blobCtor: typeof Blob,
                    mediaRecorderIsFake: window.MediaRecorder && window.MediaRecorder.name === 'FakeMediaRecorder',
                    trace: window.__fmrRecorderTrace || null,
                    debug: window.__fmrDebug || null,
                };
                if (!input) return probe;
                probe.fileCount = input.files ? input.files.length : 'no .files';
                if (input.files && input.files.length > 0) {
                    const f = input.files[0];
                    probe.file = { name: f.name, size: f.size, type: f.type };
                }
                return probe;
            });
            test.info().annotations.push({ type: 'recorder-probe', description: JSON.stringify(diagnostic) });
            const fileInfo = diagnostic.file || null;
            expect(fileInfo, `no file attached after synthetic record cycle; probe=${JSON.stringify(diagnostic)}`).not.toBeNull();
            expect(fileInfo.size, 'synthetic blob non-empty').toBeGreaterThan(0);
            expect(fileInfo.type, 'mime should be one of the supported audio/* types').toMatch(/^audio\//);

            // Play + delete buttons should now be enabled.
            await expect(playBtn).toBeEnabled();
            await expect(deleteBtn).toBeEnabled();
        } finally {
            // Page-fixture: nothing to close.
        }
    });

    test('record_video container also wires up', async ({ page, baseURL }) => {
        const run = RUN();
        await freshParticipant(page, run, { baseURL });
        expect(page.url(), 'page should be on the participant URL, not about:blank').toContain(`/${run}/`);

        try {
            await v2.waitForBundle(page);
            await installMediaStubs(page, { sampleBytes: 'fmr-test-video-blob' });

            await bsSafeEvaluate(page, () => {
                const target = document.querySelector('form.fmr-form-v2 section.fmr-page:not([hidden])');
                if (!target) return;
                const wrap = document.createElement('div');
                wrap.className = 'form-group form-row item-video record_video fmr-test-video-wrap';
                wrap.innerHTML = `
                    <label class="control-label">test video</label>
                    <div class="controls">
                        <div class="controls-inner">
                            <input type="file" name="video_test" accept="video/*">
                        </div>
                    </div>
                `;
                target.insertBefore(wrap, target.firstChild);
                window.fmrInitMediaRecorders && window.fmrInitMediaRecorders();
            });

            await expect(page.locator('.fmr-test-video-wrap .fmr-recorder-record')).toBeVisible({ timeout: 5000 });
            // Video preview should be present (initially hidden).
            const previewCount = await page.locator('.fmr-test-video-wrap .fmr-recorder-preview').count();
            expect(previewCount).toBe(1);

            // Programmatic click — see record_audio test for rationale.
            await bsSafeEvaluate(page, () => {
                document.querySelector('.fmr-test-video-wrap .fmr-recorder-record').click();
            });
            await bsSafeEvaluate(page, () => {
                document.querySelector('.fmr-test-video-wrap .fmr-recorder-record').click();
            });
            await page.waitForTimeout(300);

            const fileInfo = await bsSafeEvaluate(page, () => {
                const input = document.querySelector('.fmr-test-video-wrap input[type=file]');
                if (!input || !input.files || input.files.length === 0) return null;
                const f = input.files[0];
                return { name: f.name, size: f.size, type: f.type };
            });
            expect(fileInfo, 'no file attached after synthetic video record cycle').not.toBeNull();
            expect(fileInfo.size).toBeGreaterThan(0);
            // Audio + video share the constraints; FakeMediaRecorder defaults to
            // audio/webm. Don't assert mime since the prefix differs by stub.
        } finally {
            // Page-fixture: nothing to close.
        }
    });
});
