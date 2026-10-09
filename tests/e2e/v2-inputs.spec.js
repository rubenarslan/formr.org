// form_v2 input behaviour: deselectable optional choices (#377), pattern and
// email messages (#269, #327), and a study's own button wording (#322).
//
//   npx playwright test --config tests/e2e/playwright.config.js v2-inputs
//
// Fixture: tests/e2e/setup/e2e_v2_inputs.json, provisioned by
// tests/e2e/setup/create-v2-fixtures.js as run `e2e-inputs-v2` (the study's
// next_button_label = 'Onward', submit_button_label = 'Finish now').
//   page 1: i_mc (optional radios), i_mc_req (required radios), i_btn
//           (optional mc_button), i_zip (text [0-9]{5}, message in choice 1),
//           i_email, i_p1 (submit with no label)
//   page 2: i_done (note; no submit item, so the page nav's Submit button)
const { test, expect, bsSafeEvaluate } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v2 = require('./helpers/v2Form');
const db = require('./helpers/db');

const RUN = () => runName('v2_inputs', 'v2');

const alpine = (page, name) => bsSafeEvaluate(page, (n) => {
    const form = document.querySelector('form.fmr-form-v2');
    return window.Alpine ? window.Alpine.$data(form)[n] : undefined;
}, name);

const checked = (page, name) => bsSafeEvaluate(page, (n) => {
    const r = document.querySelector(`form.fmr-form-v2 input[type=radio][name="${n}"]:checked`);
    return r ? r.value : null;
}, name);

async function open(page, baseURL) {
    await freshParticipant(page, RUN(), { baseURL });
    await v2.waitForBundle(page);
}

test.describe('form_v2 inputs', () => {
    test('an optional single choice can be cleared again; a required one cannot (#377)', async ({ page, baseURL }) => {
        await open(page, baseURL);
        const yes = page.locator('input[type=radio][name="i_mc"][value="1"]');
        await yes.click();
        expect(await checked(page, 'i_mc')).toBe('1');
        await yes.click();
        expect(await checked(page, 'i_mc'), 'clicking the picked option clears it').toBeNull();
        expect(await alpine(page, 'i_mc'), 'the showif scope sees it cleared').toBeNull();

        const a = page.locator('input[type=radio][name="i_mc_req"][value="1"]');
        await a.click();
        await a.click();
        expect(await checked(page, 'i_mc_req'), 'a required choice stays picked').toBe('1');

        const left = page.locator('.form-group:has(input[name="i_btn"]) .btn[data-for$="_1"]');
        await left.click();
        expect(await checked(page, 'i_btn')).toBe('1');
        await expect(left).toHaveClass(/btn-checked/);
        await left.click();
        expect(await checked(page, 'i_btn'), 'a button group clears the same way').toBeNull();
        await expect(left).not.toHaveClass(/btn-checked/);
    });

    test('a cleared choice is stored as missing, even after autosave kept it', async ({ page, baseURL }) => {
        await open(page, baseURL);
        const yes = page.locator('input[type=radio][name="i_mc"][value="1"]');
        await yes.click();
        await page.waitForResponse((r) => /\/form-save\/?$/.test(new URL(r.url()).pathname), { timeout: 15000 }).catch(() => null);
        await yes.click();
        await page.locator('input[type=radio][name="i_mc_req"][value="2"]').click();
        const r = await v2.submitV2(page);
        expect(v2.advanced(r.body), JSON.stringify(r.body)).toBeTruthy();
        const sessionId = await v2.unitSessionId(page);
        const study = db.dbQuery(`SELECT results_table FROM survey_studies WHERE user_id = ${db.adminUserId()} AND name = 'e2e_v2_inputs' ORDER BY id DESC LIMIT 1`)[0];
        const row = db.dbResultsRow(study.results_table, sessionId);
        expect(row.i_mc === null || row.i_mc === '', `i_mc stored as ${JSON.stringify(row.i_mc)}`).toBe(true);
        expect(String(row.i_mc_req)).toBe('2');
    });

    test('format errors speak the author\'s words (#269) and email needs a domain dot (#327)', async ({ page, baseURL }) => {
        await open(page, baseURL);
        const feedback = (name) => bsSafeEvaluate(page, (n) => {
            const g = document.querySelector(`form.fmr-form-v2 [name="${n}"]`).closest('.form-group');
            const fb = g.querySelector('.fmr-invalid-feedback');
            return fb ? fb.textContent.trim() : null;
        }, name);
        await page.fill('[name="i_zip"]', '12');
        await page.locator('[name="i_email"]').focus();
        expect(await feedback('i_zip')).toBe('Please enter five digits.');

        await page.fill('[name="i_email"]', 'bla@bla');
        await page.locator('[name="i_zip"]').focus();
        expect(await feedback('i_email')).toMatch(/valid email address/i);

        await page.fill('[name="i_email"]', 'someone@example.org');
        await page.fill('[name="i_zip"]', '12345');
        await page.locator('[name="i_email"]').focus();
        await page.locator('[name="i_zip"]').focus();
        expect(await feedback('i_email')).toBeNull();
        expect(await feedback('i_zip')).toBeNull();
    });

    test('the study\'s own button wording labels the navigation (#322)', async ({ page, baseURL }) => {
        await open(page, baseURL);
        const buttonText = () => bsSafeEvaluate(page, () => {
            const sec = document.querySelector('form.fmr-form-v2 section.fmr-page:not([hidden])');
            const b = sec && sec.querySelector('[data-fmr-next]');
            return b ? b.textContent.trim() : null;
        });
        expect(await buttonText(), 'an unlabelled submit row takes the study\'s Next wording').toBe('Onward');
        await page.locator('input[type=radio][name="i_mc_req"][value="1"]').click();
        const r = await v2.submitV2(page);
        expect(v2.advanced(r.body), JSON.stringify(r.body)).toBeTruthy();
        await page.waitForFunction(() => {
            const sec = document.querySelector('form.fmr-form-v2 section.fmr-page:not([hidden])');
            return sec && sec.dataset.fmrPage === '2';
        });
        expect(await buttonText(), 'the last page\'s Submit').toBe('Finish now');
    });
});
