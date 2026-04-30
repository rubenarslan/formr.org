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

const { test, expect } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v2 = require('./helpers/v2Form');

const RUN = () => runName('all_widgets', 'v2');

// Inject MediaRecorder + getUserMedia stubs that complete a record/stop
// cycle synthetically — ondataavailable + onstop fire as soon as stop() is
// called. Returns a synthetic Blob containing the byte string we passed in.
async function installMediaStubs(page, { sampleBytes = 'fmr-test-audio-blob' } = {}) {
    await page.evaluate((bytes) => {
        // Replace getUserMedia with one that returns a stream-like object whose
        // tracks have a no-op stop().
        if (!navigator.mediaDevices) {
            // jsdom-like environments — define the surface.
            Object.defineProperty(navigator, 'mediaDevices', { value: {}, configurable: true });
        }
        navigator.mediaDevices.getUserMedia = async () => ({
            getTracks: () => [{ stop: () => {} }],
        });

        // Stub MediaRecorder. The real one fires `dataavailable` on stop and
        // then `stop`. Our stub does the same but with a deterministic Blob.
        class FakeMediaRecorder {
            constructor(_stream, opts) {
                this.mimeType = (opts && opts.mimeType) || 'audio/webm';
                this.state = 'inactive';
                this.ondataavailable = null;
                this.onstop = null;
            }
            static isTypeSupported() { return true; }
            start() { this.state = 'recording'; }
            stop() {
                this.state = 'inactive';
                const blob = new Blob([bytes], { type: this.mimeType });
                if (this.ondataavailable) this.ondataavailable({ data: blob });
                if (this.onstop) this.onstop();
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
            const injected = await page.evaluate(() => {
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
            const initOk = await page.evaluate(() => {
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

            // Click record → click stop → verify file input has a File.
            await recordBtn.click();
            await recordBtn.click(); // second click stops via the same handler
            await page.waitForTimeout(300);

            const fileInfo = await page.evaluate(() => {
                const input = document.querySelector('.fmr-test-audio-wrap input[type=file]');
                if (!input || !input.files || input.files.length === 0) return null;
                const f = input.files[0];
                return { name: f.name, size: f.size, type: f.type };
            });
            expect(fileInfo, 'no file attached after synthetic record cycle').not.toBeNull();
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

            await page.evaluate(() => {
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

            await page.locator('.fmr-test-video-wrap .fmr-recorder-record').click();
            await page.locator('.fmr-test-video-wrap .fmr-recorder-record').click();
            await page.waitForTimeout(300);

            const fileInfo = await page.evaluate(() => {
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
