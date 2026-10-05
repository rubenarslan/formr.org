// The audio recorder of a `record_audio` file item (form_v2,
// items/recorders.js), driven by local Chromium's fake microphone
// (playwright.config.js). Every stream the page opens is tracked, so a test
// can tell a microphone left on. Everything is created here and deleted in
// afterAll: one study, one run.

const { request } = require('@playwright/test');
const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { freshParticipant } = require('./helpers/participant');
const { STATE_PATH, ensureAdminState } = require('./helpers/admin');
const { provisioner } = require('./helpers/provision');
const v2 = require('./helpers/v2Form');

const SFX = Date.now().toString(36);
const NAME = `e2e_recorder_${SFX}`;
const RUN = `e2e-recorder-${SFX}`;
const ITEMS = [
    { type: 'file', name: 'sr_voice', label: 'Say something', optional: 1, class: 'record_audio' },
    { type: 'text', name: 'sr_more', label: 'Tell us more', optional: 1, showif: 'answered(sr_voice)' },
    { type: 'submit', name: 'sr_s1', label: 'Done' },
];
const RECORD = '.record_audio .fmr-recorder-record';
const RECORDING = '.record_audio .fmr-recorder-record i.fa-stop';

// Track the streams the page opens; make the microphone or the audio
// decode slow where a test needs the time.
const instrument = (page, delays = {}) => page.addInitScript(({ gumDelay, decodeDelay }) => {
    window.__streams = [];
    const gum = navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);
    navigator.mediaDevices.getUserMedia = async (c) => {
        if (gumDelay) await new Promise((r) => setTimeout(r, gumDelay));
        const s = await gum(c);
        window.__streams.push(s);
        return s;
    };
    if (decodeDelay) {
        const decode = AudioContext.prototype.decodeAudioData;
        AudioContext.prototype.decodeAudioData = function (...args) {
            return new Promise((r) => setTimeout(r, decodeDelay)).then(() => decode.apply(this, args));
        };
    }
}, { gumDelay: delays.gumDelay || 0, decodeDelay: delays.decodeDelay || 0 });
const streamsLive = (page) => page.evaluate(() => window.__streams.map((s) => s.getTracks().every((tr) => tr.readyState === 'live')));

test.describe('form_v2: the audio recorder', () => {
    test.skip(RUNNING_ON_BS, 'provisions through the admin; fake microphone');
    const prov = provisioner();
    let admin;

    test.beforeAll(async ({ browser }) => {
        test.setTimeout(120000);
        await ensureAdminState(browser);
        admin = await request.newContext({ ignoreHTTPSErrors: true, storageState: STATE_PATH });
        const study = await prov.uploadStudy(admin, { engine: 'v2', json: { name: NAME, items: ITEMS } });
        await prov.createRun(admin, RUN, [{ type: 'Survey', studyId: study.id }, { type: 'Page', body: `END_${SFX}` }]);
    });

    test.afterAll(async () => {
        if (admin) {
            await prov.cleanup(admin);
            await admin.dispose();
        }
    });

    test('a re-take started while the last recording is being finished keeps recording', async ({ page, baseURL }) => {
        await instrument(page, { decodeDelay: 2000 });
        await freshParticipant(page, RUN, { baseURL });
        await v2.waitForBundle(page);
        await page.locator(RECORD).click();
        await expect(page.locator(RECORDING)).toBeVisible();
        await page.waitForTimeout(800);
        // Stop (its audio is now decoded for 2 s), and record again at once.
        await page.locator(RECORD).click();
        await page.locator(RECORD).click();
        await expect.poll(() => page.evaluate(() => window.__streams.length)).toBe(2);
        await page.waitForTimeout(2600);
        expect(await streamsLive(page), 'the first microphone off, the re-take on').toEqual([false, true]);
        await expect(page.locator(RECORDING)).toBeVisible();
    });

    test('a second tap while the microphone opens starts one recording', async ({ page, baseURL }) => {
        await instrument(page, { gumDelay: 700 });
        await freshParticipant(page, RUN, { baseURL });
        await v2.waitForBundle(page);
        await page.locator(RECORD).click();
        await page.locator(RECORD).click();
        await expect(page.locator(RECORDING)).toBeVisible();
        await page.waitForTimeout(1200);
        expect(await page.evaluate(() => window.__streams.length), 'one microphone').toBe(1);
        await page.locator(RECORD).click();
        await expect.poll(() => streamsLive(page), { message: 'stopped: no microphone left on' }).toEqual([false]);
    });
});
