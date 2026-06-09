// bot_check item (form_v2) — self-hosted Altcha + Argon2id "are you human" gate.
//
// Covers the full surface of the item:
//   - render: an <altcha-widget> wired to a lazy server challenge URL, value empty
//   - client gate: required + unverified blocks the submit with an inline error
//   - solve: a trusted click runs the in-browser Argon2id PoW and writes the
//     base64 Altcha payload into the item's hidden input
//   - accept: a solved submit is accepted and stores `verified`
//   - server gate: a forged token (client gate bypassed) is rejected server-side
//     — the security boundary is the server (BotCheckChallenge::verify), not the UI
//
// Altcha is loaded as a self-hosted standalone module script (not bundled); see
// webroot/assets/form/js/items/bot-check.js. Fixture: persistent public run
// `e2e-botcheck-v2` → Form unit (study `e2e_bot_check`: note, bot_check[required],
// text `feeling`, submit) → Stop. Backend mint/solve/verify coverage lives in
// bin/bot_check_smoke.php.

const { test, expect } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v2 = require('./helpers/v2Form');
const db = require('./helpers/db');

const RUN = () => runName('bot_check', 'v2');
const STUDY = 'e2e_bot_check';
const WIDGET = 'altcha-widget';
// The wrapper, not the inner input: altcha's checkmark svg overlays the input
// and (pre-CSS-fix bundles) intercepts the click point — Playwright's strict
// actionability check then refuses the input. The widget handles bubbled
// wrapper clicks, which is also the path a human pointer takes.
const BOX = 'altcha-widget .altcha-checkbox';

function resultsTable() {
    const rows = db.dbQuery(`SELECT id FROM survey_studies WHERE name = '${STUDY}' ORDER BY id DESC LIMIT 1`);
    if (!rows[0]) throw new Error(`study ${STUDY} not found — run the runbook to provision e2e-botcheck-v2`);
    return `s${rows[0].id}_${STUDY}`;
}

// Wait for the injected standalone altcha script to define the widget, then a
// trusted click solves the PoW (page-JS clicks are isTrusted=false and ignored).
async function solveWidget(page) {
    await page.waitForFunction(() => !!customElements.get('altcha-widget') && !!window.$altcha, null, { timeout: 15000 });
    await page.locator(BOX).click();
    await page.waitForFunction(
        () => document.querySelector('altcha-widget .altcha')?.getAttribute('data-state') === 'verified',
        null,
        { timeout: 30000 }, // Argon2id is memory-hard; allow time
    );
}

test.describe('bot_check (v2, Altcha)', () => {

    test('renders an altcha-widget wired to a lazy server challenge', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        await page.waitForFunction(() => !!customElements.get('altcha-widget'), null, { timeout: 15000 });
        const state = await page.evaluate((sel) => {
            const w = document.querySelector(sel);
            if (!w) return null;
            const hidden = w.querySelector('input[type=hidden]') || document.querySelector('input[name="human_check"]');
            return {
                defined: !!customElements.get('altcha-widget'),
                challengeUrlSet: /form-bot-challenge/.test(w.getAttribute('challenge') || ''),
                hiddenEmpty: hidden ? hidden.value === '' : null,
                argonRegistered: !!(window.$altcha && window.$altcha.algorithms && window.$altcha.algorithms.get('ARGON2ID')),
            };
        }, WIDGET);
        expect(state).not.toBeNull();
        expect(state.defined).toBe(true);
        expect(state.challengeUrlSet).toBe(true);
        expect(state.hiddenEmpty).toBe(true);
        expect(state.argonRegistered).toBe(true);
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
        expect(await v2.isPresent(page)).toBe(true);
        const msgs = await v2.errorMessages(page);
        expect(msgs.join(' ')).toMatch(/verify .*human/i);
    });

    test('a trusted click solves Argon2id and writes the Altcha payload', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        await solveWidget(page);
        const tok = await page.evaluate(() => {
            const hidden = document.querySelector('input[name="human_check"]');
            let p = null; try { p = JSON.parse(atob(hidden.value)); } catch (e) {}
            return {
                verified: document.querySelector('altcha-widget .altcha')?.getAttribute('data-state') === 'verified',
                payloadLen: hidden ? hidden.value.length : 0,
                hasChallenge: !!(p && p.challenge),
                hasSolution: !!(p && p.solution),
            };
        });
        expect(tok.verified).toBe(true);
        expect(tok.payloadLen).toBeGreaterThan(100);
        expect(tok.hasChallenge).toBe(true);
        expect(tok.hasSolution).toBe(true);
    });

    test('a solved submit is accepted and stores "verified"', async ({ page, baseURL }) => {
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
            `SELECT human_check FROM \`${resultsTable()}\` WHERE feeling = '${marker}' LIMIT 1`,
        );
        expect(rows[0]).toBeTruthy();
        expect(rows[0].human_check).toBe('verified');
    });

    test('a forged token is rejected server-side (client gate bypassed)', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        // Forge: drop a bogus base64 Altcha payload into the hidden input AND tick
        // Altcha's required checkbox so the client gate (native required + the
        // generic "answered" check) lets the POST through. The boundary under test
        // is the server (BotCheckChallenge::verify), not the UI — so we must reach
        // it. A programmatic .checked assignment does NOT trigger Altcha's solver
        // (that needs a trusted click), so the bogus payload survives to the wire.
        await page.evaluate(() => {
            const hidden = document.querySelector('input[name="human_check"]');
            const bogus = btoa(JSON.stringify({
                challenge: { parameters: { algorithm: 'ARGON2ID', challenge: '00', salt: 'deadbeef', keyPrefix: '00' }, signature: '0'.repeat(64) },
                solution: { counter: 1, derivedKey: '00' },
            }));
            if (hidden) { hidden.value = bogus; hidden.dispatchEvent(new Event('change', { bubbles: true })); }
            const cb = document.querySelector('altcha-widget input[type=checkbox]');
            if (cb) cb.checked = true; // satisfy native required; don't trigger solve
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
