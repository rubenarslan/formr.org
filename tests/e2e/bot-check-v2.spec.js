// bot_check item (form_v2) — local-only "are you human" proof-of-work gate.
//
// Covers the full surface of the item:
//   - render: the widget + a server-minted, signed PoW challenge
//   - client gate: required + unverified blocks the submit with an inline error
//   - solve: a trusted click runs the in-browser PoW and writes a signed token
//   - accept: a solved submit is accepted and stores `passed`
//   - server gate: a forged token (with the client gate bypassed) is rejected
//     server-side — the security boundary is the server, not the widget
//
// Fixture: persistent public run `e2e-botcheck-v2` → Form unit (study
// `e2e_bot_check`: note, bot_check[required], text, submit) → Stop. See
// tests/e2e/setup/runbook.md to (re)provision it. Backend unit coverage of
// mint/verify lives in bin/bot_check_smoke.php.

const { test, expect } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v2 = require('./helpers/v2Form');
const db = require('./helpers/db');

const RUN = () => runName('bot_check', 'v2');
const STUDY = 'e2e_bot_check';
const WIDGET = '.fmr-botcheck';
const BOX = '.fmr-botcheck-box';

function resultsTable() {
    const rows = db.dbQuery(`SELECT id FROM survey_studies WHERE name = '${STUDY}' ORDER BY id DESC LIMIT 1`);
    if (!rows[0]) throw new Error(`study ${STUDY} not found — run the runbook to provision e2e-botcheck-v2`);
    return `s${rows[0].id}_${STUDY}`;
}

async function solveWidget(page) {
    await page.locator(BOX).click(); // a real (trusted) click — page-JS clicks are isTrusted=false and ignored
    await page.waitForFunction(
        (sel) => document.querySelector(sel)?.dataset.state === 'verified',
        WIDGET,
        { timeout: 15000 },
    );
}

test.describe('bot_check (v2)', () => {

    test('renders the widget with a signed server challenge', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        const state = await page.evaluate((sel) => {
            const w = document.querySelector(sel);
            if (!w) return null;
            const hidden = w.querySelector('input[type=hidden]');
            return {
                state: w.dataset.state,
                hasChallenge: !!(w.dataset.iat && w.dataset.salt && w.dataset.sig && w.dataset.diff),
                hiddenEmpty: hidden ? hidden.value === '' : null,
            };
        }, WIDGET);
        expect(state).not.toBeNull();
        expect(state.state).toBe('idle');
        expect(state.hasChallenge).toBe(true);
        expect(state.hiddenEmpty).toBe(true);
    });

    test('required + unverified blocks the submit with an inline error', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        await page.evaluate(() => {
            const f = document.querySelector('input[name="feeling"]');
            if (f) { f.value = 'curious'; f.dispatchEvent(new Event('input', { bubbles: true })); }
        });
        const res = await v2.submitV2(page);
        expect(res.blockedByClient).toBe(true); // client gate prevents the POST
        expect(await v2.isPresent(page)).toBe(true); // still on the form
        const msgs = await v2.errorMessages(page);
        expect(msgs.join(' ')).toMatch(/verify .*human/i);
    });

    test('a trusted click solves the PoW and writes a signed token', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        await solveWidget(page);
        const tok = await page.evaluate((sel) => {
            const w = document.querySelector(sel);
            const box = w.querySelector('.fmr-botcheck-box');
            let t = null; try { t = JSON.parse(w.querySelector('input[type=hidden]').value); } catch (e) {}
            return {
                state: w.dataset.state,
                checked: box ? box.getAttribute('aria-checked') : null,
                hasSig: !!(t && t.sig),
                hasNonce: !!(t && t.nonce),
            };
        }, WIDGET);
        expect(tok.state).toBe('verified');
        expect(tok.checked).toBe('true');
        expect(tok.hasSig).toBe(true);
        expect(tok.hasNonce).toBe(true);
    });

    test('a solved submit is accepted and stores "passed"', async ({ page, baseURL }) => {
        const marker = 'ok-' + Date.now();
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        await solveWidget(page);
        await page.evaluate((m) => {
            const f = document.querySelector('input[name="feeling"]');
            if (f) { f.value = m; f.dispatchEvent(new Event('input', { bubbles: true })); }
        }, marker);
        const res = await v2.submitV2(page);
        expect(res.blockedByClient).toBe(false);
        expect(res.status).toBe(200);
        expect(res.body && res.body.status).not.toBe('errors');

        const rows = db.dbQuery(
            `SELECT human_check FROM \`${resultsTable()}\` WHERE feeling = '${marker}' LIMIT 1`
        );
        expect(rows[0]).toBeTruthy();
        expect(rows[0].human_check).toBe('passed');
    });

    test('a forged token is rejected server-side (client gate bypassed)', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        // Forge: mark the widget verified (so the client gate skips it) and drop
        // in a bogus token. The server must still reject it.
        await page.evaluate(() => {
            const w = document.querySelector('.fmr-botcheck');
            w.dataset.state = 'verified';
            const hidden = w.querySelector('input[type=hidden]');
            hidden.value = JSON.stringify({ iat: Math.floor(Date.now() / 1000), salt: 'deadbeef', diff: 15, sig: '0'.repeat(64), nonce: '1', el: 500 });
            const f = document.querySelector('input[name="feeling"]');
            if (f) { f.value = 'forged'; f.dispatchEvent(new Event('input', { bubbles: true })); }
        });
        const res = await v2.submitV2(page);
        expect(res.blockedByClient).toBe(false); // reached the server
        expect(res.body && res.body.status).toBe('errors');
        expect(res.body.errors).toHaveProperty('human_check');
        expect(await v2.isPresent(page)).toBe(true);
    });

});
