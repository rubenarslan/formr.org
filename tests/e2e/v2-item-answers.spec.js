// What v2 stores for items whose answer is not one plain input: the ticked
// choices of an mc_multiple, a check_button, a revealed scale, a PWA item's
// status, a recording, an R preset, an upload in a showif, a stored
// location, a rank dragged by touch, … Each case submits through the browser and
// reads the stored answer back. Everything is created here and deleted in
// afterAll: two studies, two runs.

const { request } = require('@playwright/test');
const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { freshParticipant } = require('./helpers/participant');
const { STATE_PATH, ensureAdminState } = require('./helpers/admin');
const { provisioner } = require('./helpers/provision');
const { dbQuery } = require('./helpers/db');
const v2 = require('./helpers/v2Form');

const SFX = Date.now().toString(36);
const NAME = `e2e_itemans_${SFX}`;
const RUN = `e2e-itemans-${SFX}`;
const ABC = { 1: 'A', 2: 'B', 3: 'C' };
const ITEMS = [
    { type: 'mc_multiple', name: 'ia_multi', label: 'Pick some', optional: 1, choice_list: 'abc', choices: ABC },
    { type: 'mc_multiple', name: 'ia_req', label: 'Pick at least one', choice_list: 'abc', choices: ABC },
    { type: 'mc_multiple', name: 'ia_untouched', label: 'Leave me', optional: 1, choice_list: 'abc', choices: ABC },
    { type: 'check_button', name: 'ia_check', label: 'Agree', optional: 1 },
    { type: 'mc', name: 'vs_gate', label: 'Rate it?', optional: 1, choice_list: 'yn', choices: { 1: 'yes', 2: 'no' } },
    { type: 'visual_analog_scale', name: 'vs_scale', label: 'How much?', optional: 1, showif: 'vs_gate == 1', choice_list: 'poles', choices: { 1: 'none', 2: 'a lot' } },
    { type: 'add_to_home_screen', name: 'ia_home', label: 'Install it', optional: 1 },
    { type: 'file', name: 'ia_voice', label: 'Say something', optional: 1, class: 'record_audio' },
    { type: 'file', name: 'ia_photo', label: 'A photo', optional: 1 },
    { type: 'rank', name: 'ia_rank', label: 'Order these', optional: 1, choice_list: 'abc', choices: ABC },
    { type: 'visual_analog_scale', name: 'vr_scale', label: 'Preset in R', optional: 1, value: '40 + 30', choice_list: 'poles', choices: { 1: 'none', 2: 'a lot' } },
    { type: 'submit', name: 'ia_s1', label: 'Next' },
    { type: 'text', name: 'ia_p2', label: 'Page two', optional: 1 },
    { type: 'text', name: 'ia_photo_note', label: 'About your photo', optional: 1, showif: 'answered(ia_photo)' },
    { type: 'submit', name: 'ia_s2', label: 'Done' },
];

// A 1×1 PNG.
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');

// A required geopoint gets its own form: it would hold up the others.
const GEO_NAME = `e2e_itemgeo_${SFX}`;
const GEO_RUN = `e2e-itemgeo-${SFX}`;
const GEO_ITEMS = [
    { type: 'geopoint', name: 'gp_loc', label: 'Where are you?' },
    { type: 'submit', name: 'gp_s1', label: 'Next' },
    { type: 'text', name: 'gp_p2', label: 'Page two', optional: 1 },
    { type: 'submit', name: 'gp_s2', label: 'Done' },
];

const stored = (usid, name) => {
    const rows = dbQuery(`SELECT d.answer FROM survey_items_display d JOIN survey_items i ON i.id = d.item_id `
        + `WHERE d.session_id = ${Number(usid)} AND i.name = '${name}';`);
    return rows.length ? rows[0].answer : undefined;
};

test.describe('form_v2: what an item stores', () => {
    test.skip(RUNNING_ON_BS, 'provisions through the admin');
    const prov = provisioner();
    let admin;

    test.beforeAll(async ({ browser }) => {
        test.setTimeout(120000);
        await ensureAdminState(browser);
        admin = await request.newContext({ ignoreHTTPSErrors: true, storageState: STATE_PATH });
        const study = await prov.uploadStudy(admin, { engine: 'v2', json: { name: NAME, items: ITEMS } });
        await prov.createRun(admin, RUN, [{ type: 'Survey', studyId: study.id }, { type: 'Page', body: `END_${SFX}` }]);
        const geo = await prov.uploadStudy(admin, { engine: 'v2', json: { name: GEO_NAME, items: GEO_ITEMS } });
        await prov.createRun(admin, GEO_RUN, [{ type: 'Survey', studyId: geo.id }, { type: 'Page', body: `END_${SFX}` }]);
    });

    test.afterAll(async () => {
        if (admin) {
            await prov.cleanup(admin);
            await admin.dispose();
        }
    });

    test('an mc_multiple stores the choices ticked, and nothing when none is', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN, { baseURL });
        await v2.waitForBundle(page);
        const usid = await v2.unitSessionId(page);
        await page.locator('input[name="ia_multi[]"][value="2"]').check({ force: true });
        await page.locator('input[name="ia_req[]"][value="1"]').check({ force: true });
        const posted = page.waitForRequest((r) => /\/form-page-submit\/?$/.test(new URL(r.url()).pathname));
        const { status, body } = await v2.submitV2(page);
        expect(status, JSON.stringify(body)).toBe(200);
        const data = (await posted).postDataJSON().data;
        // As a native form posts it: the hidden placeholder, then the ticked box.
        expect(data.ia_multi).toEqual(['', '2']);
        expect(data.ia_untouched).toEqual(['']);
        expect(stored(usid, 'ia_multi')).toBe('2');
        expect(stored(usid, 'ia_req')).toBe('1');
        expect(stored(usid, 'ia_untouched') || null).toBeNull();
        // When it was answered: the browser's stamp, posted with the page.
        const [times] = dbQuery(`SELECT d.answered_relative FROM survey_items_display d JOIN survey_items i ON i.id = d.item_id `
            + `WHERE d.session_id = ${Number(usid)} AND i.name = 'ia_multi';`);
        expect(times.answered_relative, 'answered_relative of a ticked item').not.toBeNull();
    });

    test('a check_button can be seen and tapped, and stores its tick', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN, { baseURL });
        await v2.waitForBundle(page);
        const usid = await v2.unitSessionId(page);
        const btn = page.locator('.item-check_button .btn[data-for]').first();
        await expect(btn).toBeVisible();
        // What a tap at the button's centre reaches: the button itself.
        const reached = await btn.evaluate((b) => {
            const r = b.getBoundingClientRect();
            const el = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
            return !!el && (el === b || b.contains(el));
        });
        expect(reached, 'nothing covers the button and it takes pointer events').toBe(true);
        await btn.click();
        await page.locator('input[name="ia_req[]"][value="1"]').check({ force: true });
        const { status, body } = await v2.submitV2(page);
        expect(status, JSON.stringify(body)).toBe(200);
        expect(stored(usid, 'ia_check')).toBe('1');
    });

    test('a visual analog scale revealed by its showif can be answered', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN, { baseURL });
        await v2.waitForBundle(page);
        const usid = await v2.unitSessionId(page);
        const slider = page.locator('.form-group:has(input[name="vs_scale"]) .vas-display');
        await expect(slider).toBeHidden();
        await page.locator('input[name="vs_gate"][value="1"]').check({ force: true });
        await expect(slider).toBeVisible();
        await expect(slider).toBeEnabled();
        // Off the midpoint: an untouched range already sits there, and a
        // click that changes nothing fires no input.
        const box = await slider.boundingBox();
        await slider.click({ position: { x: box.width * 0.8, y: box.height / 2 } });
        await page.locator('input[name="ia_req[]"][value="1"]').check({ force: true });
        const { status, body } = await v2.submitV2(page);
        expect(status, JSON.stringify(body)).toBe(200);
        expect(Number(stored(usid, 'vs_scale'))).toBeGreaterThan(0);
    });

    test('a scale preset in R shows its preset on the slider', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN, { baseURL });
        await v2.waitForBundle(page);
        const scale = page.locator('.form-group:has(input[name="vr_scale"])');
        // The value resolves when the page is shown (/form-render-page).
        await expect(scale.locator('.vas-controls'), 'shown as answered, not as untouched').toHaveClass(/vas-touched/, { timeout: 20000 });
        expect(await scale.locator('.vas-display').inputValue()).toBe('70');
        expect(await scale.locator('input[name="vr_scale"]').inputValue()).toBe('70');
    });

    test('a recording still running when the page is sent goes with it', async ({ page, baseURL }) => {
        // Local Chromium records from a fake microphone (playwright.config.js).
        await freshParticipant(page, RUN, { baseURL });
        await v2.waitForBundle(page);
        const usid = await v2.unitSessionId(page);
        await page.locator('.record_audio .fmr-recorder-record').click();
        await expect(page.locator('.record_audio .fmr-recorder-record i.fa-stop')).toBeVisible();
        await page.waitForTimeout(1500);
        await page.locator('input[name="ia_req[]"][value="1"]').check({ force: true });
        const { status, body } = await v2.submitV2(page, { timeout: 20000 });
        expect(status, JSON.stringify(body)).toBe(200);
        expect(stored(usid, 'ia_voice') || '', 'the recording is stored with its page').not.toBe('');
    });

    test('an upload counts as answered in a showif, chosen and once stored', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN, { baseURL });
        await v2.waitForBundle(page);
        await page.locator('input[name="ia_photo"]').setInputFiles({ name: 'photo.png', mimeType: 'image/png', buffer: PNG });
        await page.locator('input[name="ia_req[]"][value="1"]').check({ force: true });
        const { status, body } = await v2.submitV2(page, { timeout: 20000 });
        expect(status, JSON.stringify(body)).toBe(200);
        const note = page.locator('.form-group:has([name="ia_photo_note"])');
        await expect(note, 'answered(ia_photo) after choosing a file').toBeVisible();
        await page.reload({ waitUntil: 'domcontentloaded' });
        await v2.waitForBundle(page);
        await expect(note, 'and from the stored upload after a reload').toBeVisible();
    });

    test('a stored location answers a required geopoint after a reload', async ({ page, baseURL }) => {
        await freshParticipant(page, GEO_RUN, { baseURL });
        await v2.waitForBundle(page);
        const usid = await v2.unitSessionId(page);
        // As the geolocator leaves it; the autosave stores it.
        const saved = page.waitForResponse((r) => /\/form-save\/?$/.test(new URL(r.url()).pathname));
        await page.locator('input[name="gp_loc[]"]').evaluate((el) => {
            el.value = 'lat:52.5/long:13.4';
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        });
        expect((await (await saved).json()).status).toBe('saved');
        expect(stored(usid, 'gp_loc')).toBe('lat:52.5/long:13.4');
        await page.reload({ waitUntil: 'domcontentloaded' });
        await v2.waitForBundle(page);
        const { status, body, blockedByClient } = await v2.submitV2(page);
        expect(blockedByClient, 'the required check sees the stored location').toBe(false);
        expect(status, JSON.stringify(body)).toBe(200);
    });

    test('a rank row held under a finger follows it', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN, { baseURL });
        await v2.waitForBundle(page);
        const row = page.locator('.fmr-rank .fmr-rank-item').first();
        // A touch pressed on the row's body (not its handle), held past the
        // hold time, then moved 20 px down.
        const drag = await row.evaluate(async (el) => {
            const r = el.getBoundingClientRect();
            const x = r.left + r.width / 2;
            const y = r.top + r.height / 2;
            const ev = (type, cy) => new PointerEvent(type, { pointerId: 9, pointerType: 'touch', isPrimary: true, clientX: x, clientY: cy, bubbles: true });
            el.dispatchEvent(ev('pointerdown', y));
            await new Promise((done) => setTimeout(done, 400));
            document.dispatchEvent(ev('pointermove', y + 20));
            const out = { lifted: el.classList.contains('fmr-rank-dragging'), top: el.style.top, expected: r.top + 20 };
            document.dispatchEvent(ev('pointerup', y + 20));
            return out;
        });
        expect(drag.lifted).toBe(true);
        expect(parseFloat(drag.top), `top ${drag.top}`).toBeCloseTo(drag.expected, 0);
    });

    test("a home-screen item comes back with its stored status", async ({ page, baseURL }) => {
        await freshParticipant(page, RUN, { baseURL });
        await v2.waitForBundle(page);
        const usid = await v2.unitSessionId(page);
        const status = page.locator('input[name="ia_home"]');
        // As the install handler records it.
        await status.evaluate((el) => { el.value = 'added'; });
        await page.locator('input[name="ia_req[]"][value="1"]').check({ force: true });
        const { status: code, body } = await v2.submitV2(page);
        expect(code, JSON.stringify(body)).toBe(200);
        expect(stored(usid, 'ia_home')).toBe('added');
        // Rendered again with what is stored, not the default the next
        // autosave of page 1 would store over it.
        await page.reload({ waitUntil: 'domcontentloaded' });
        await v2.waitForBundle(page);
        expect(await status.inputValue()).toBe('added');
    });
});
