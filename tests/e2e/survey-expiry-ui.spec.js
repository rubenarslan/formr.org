// Phase 5: JS / UI drift tests.
//
// Drives Playwright against the dev URL to observe what the
// participant actually sees during expiry-related interactions:
// - the ExpiryNotifier modal at templates/run/index.php:47-61 (driven
//   by webroot/assets/common/js/components/ExpiryNotifier.js),
// - the lock-timeout reload script at RunSession.php:197,
// - the silent skip-after-expire participant experience,
// - whether the client's `window.unit_session_expires` self-corrects
//   on re-render.

const { test, expect } = require('@playwright/test');
const {
    dbExecRaw, dbQuery, dbState,
    setUnitSessionCreated, setItemsDisplaySaved,
} = require('./helpers/db');
const { provision } = require('./helpers/expiry');
const { holdRunSessionLock } = require('./helpers/lock');

// Read window.unit_session_expires (a JS variable emitted by
// templates/public/head.php:80-93). Returns null when not set.
async function readClientExpires(page) {
    return page.evaluate(() => {
        try {
            return window.unit_session_expires || null;
        } catch (_) { return null; }
    });
}

test.describe('JS / UI drift', () => {

    test('J1 — ExpiryNotifier modal fires when client clock reaches expires', async ({ page, baseURL }) => {
        // Provision X=2min Survey. Visit. Use Playwright's clock control
        // to fast-forward past the 2-minute window without actually
        // waiting. Assert the modal appears.
        const f = await provision({ x: 2, y: 0, z: 0, items: 1, name: 'e2e-expiry-j1' });

        // Install the clock BEFORE navigating so the page sees the
        // controlled time from first script eval.
        await page.clock.install();

        await page.context().clearCookies();
        await page.goto(`${baseURL}/${f.run_name}/?code=${f.code}`,
            { waitUntil: 'load', timeout: 30000 });
        await expect(page.locator('input[name="q1"]')).toBeVisible({ timeout: 10000 });

        // Sanity: window.unit_session_expires should be set from head.php.
        const expires = await readClientExpires(page);
        expect(expires, 'head.php should emit unit_session_expires for X=2min Survey').not.toBeNull();

        // Fast-forward 3 min so the ExpiryNotifier setTimeout fires.
        await page.clock.fastForward(3 * 60 * 1000);

        // The modal injected by bootstrap_modal() at main.js:43 has
        // a `.reload-btn` button. Assert it's visible. Use the visible
        // modal as the scope (the page also contains template <script>
        // blocks with .modal-body text in them).
        const modal = page.locator('.modal:visible').first();
        await expect(modal.locator('.reload-btn')).toBeVisible({ timeout: 5000 });
        await expect(modal.locator('.modal-body')).toContainText('outdated', { timeout: 1000 });
    });

    test('J3 — lock-timeout reload script returned when run-session lock is held', async ({ page, baseURL }) => {
        // RunSession::execute() at :188-198 tries GET_LOCK with a 10 s
        // timeout for user requests. If the lock is held longer than
        // that, it returns the 5-s-reload HTML body. We hold the lock
        // for 15 s from a background subprocess, then trigger a
        // participant GET while the lock is held.
        const f = await provision({ x: 60, y: 0, z: 0, items: 1, name: 'e2e-expiry-j3' });

        // Initial visit (releases lock when done).
        await page.context().clearCookies();
        await page.goto(`${baseURL}/${f.run_name}/?code=${f.code}`,
            { waitUntil: 'load', timeout: 30000 });

        // Acquire the named lock externally for 15 s.
        const handle = holdRunSessionLock(f.run_session_id, 15);
        try {
            await handle.waitAcquired();

            // Now make a request that goes through execute() — a fresh
            // navigation. Server tries GET_LOCK with 10 s timeout; we're
            // holding it for 15 s, so it times out and returns the
            // reload HTML. The reload-script body fits the documented
            // bug-or-feature: any unsubmitted form input is lost when
            // the page reloads.
            const response = await page.request.get(
                `${baseURL}/${f.run_name}/?code=${f.code}`,
                { timeout: 30000 }
            );
            const body = await response.text();
            expect(body).toContain('Will automatically reload');
            expect(body).toContain('window.location.reload()');
        } finally {
            handle.release();
        }
    });

    test('J4 — reload after expire silently lands on next unit', async ({ page, baseURL }) => {
        // Backdate the Survey unit-session to expired. Click anywhere
        // (or just navigate) — server runs queue / END-q logic, expires
        // the Survey, advances to Endpage. Participant lands on Endpage
        // without notification of the discarded Survey state.
        const f = await provision({ x: 1, y: 0, z: 0, items: 1, name: 'e2e-expiry-j4' });

        await page.context().clearCookies();
        await page.goto(`${baseURL}/${f.run_name}/?code=${f.code}`,
            { waitUntil: 'load', timeout: 30000 });
        await expect(page.locator('input[name="q1"]')).toBeVisible({ timeout: 10000 });

        const usRow = dbQuery(
            `SELECT us.id FROM survey_unit_sessions us
             JOIN survey_units u ON u.id = us.unit_id AND u.type = 'Survey'
             WHERE us.run_session_id = ${f.run_session_id}`
        );
        const usId = parseInt(usRow[0].id, 10);

        // Backdate so expires < NOW.
        setUnitSessionCreated(usId, 5);  // 5 min ago, past X=1
        dbExecRaw(`UPDATE survey_unit_sessions SET expires = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ${usId}`);

        // Navigate again. Server sees expired Survey at the run's
        // current position; the END-q branch fires (or the legitimate
        // expire path); participant moves on.
        await page.goto(`${baseURL}/${f.run_name}/?code=${f.code}`,
            { waitUntil: 'load', timeout: 30000 });

        // Expect the Endpage marker, NOT the Survey form.
        await expect(page.locator('[data-marker="e2e-expiry-endpage"]'))
            .toBeVisible({ timeout: 10000 });
        await expect(page.locator('input[name="q1"]')).toHaveCount(0);

        // Confirm DB state: Survey expired.
        const s = dbState(usId);
        expect(s.expired, 'Survey expired by server-side logic').not.toBeNull();
    });

    test('J5 — window.unit_session_expires self-corrects across renders', async ({ page, baseURL }) => {
        // The server's queue() runs on every render and recomputes
        // expires from the algorithm — so a direct UPDATE to expires
        // gets overwritten on next render. To observe the self-correct
        // behaviour we have to mutate something that *changes the
        // algorithm's output*: backdate `created` (= invitation_sent),
        // which shifts the pre-access deadline (invitation+X) earlier.
        const f = await provision({ x: 60, y: 0, z: 0, items: 1, name: 'e2e-expiry-j5' });

        await page.context().clearCookies();
        await page.goto(`${baseURL}/${f.run_name}/?code=${f.code}`,
            { waitUntil: 'load', timeout: 30000 });
        await expect(page.locator('input[name="q1"]')).toBeVisible({ timeout: 10000 });

        const initialExpires = await readClientExpires(page);
        expect(initialExpires, 'first render emits expires').not.toBeNull();

        const usRow = dbQuery(
            `SELECT us.id FROM survey_unit_sessions us
             JOIN survey_units u ON u.id = us.unit_id AND u.type = 'Survey'
             WHERE us.run_session_id = ${f.run_session_id}`
        );
        const usId = parseInt(usRow[0].id, 10);

        // Backdate created by 30 min. New algorithm verdict:
        // expires = invitation+X = (NOW-30min) + 60min = NOW+30min ahead,
        // i.e. 30 min earlier than the original NOW+60min.
        setUnitSessionCreated(usId, 30);

        // Re-navigate. head.php re-reads expires; queue() recomputes.
        await page.goto(`${baseURL}/${f.run_name}/?code=${f.code}`,
            { waitUntil: 'load', timeout: 30000 });

        const updatedExpires = await readClientExpires(page);
        expect(updatedExpires, 'second render reflects new algorithm verdict')
            .not.toBe(initialExpires);
        const delta = new Date(updatedExpires).getTime() - new Date(initialExpires).getTime();
        // Initial: NOW+60min ish. Updated: NOW+30min ish. delta ≈ -30min.
        expect(delta, 'updated expires is ~30min earlier than initial').toBeLessThan(-25 * 60 * 1000);
        expect(delta).toBeGreaterThan(-35 * 60 * 1000);
    });

    test('J2 — client modal fires independently of server state (no cron tick required)', async ({ page, baseURL }) => {
        // Drift characterisation: the client's ExpiryNotifier setTimeout
        // fires purely on the client wall-clock, independent of whether
        // the server has actually expired the unit-session. This is the
        // "stale clock" risk — between renders the server might have
        // re-computed a *later* deadline (e.g., user POSTs an item, Z
        // slides, server stores expires=last_active+Z further out) but
        // the client only knows the originally-rendered deadline.
        //
        // Test approach: Provision X=2 Survey. Visit (server emits
        // unit_session_expires = invitation+2min). Use page.clock to
        // fast-forward 3min. The modal fires on the client side.
        // Confirm that DB state STILL has expired=NULL — no cron tick
        // ran during the test, so server-side the unit is unchanged.
        // Modal fired entirely from client-side timer.
        const f = await provision({ x: 2, y: 0, z: 0, items: 1, name: 'e2e-expiry-j2' });

        await page.clock.install();
        await page.context().clearCookies();
        await page.goto(`${baseURL}/${f.run_name}/?code=${f.code}`,
            { waitUntil: 'load', timeout: 30000 });
        await expect(page.locator('input[name="q1"]')).toBeVisible({ timeout: 10000 });

        const usRow = dbQuery(
            `SELECT us.id FROM survey_unit_sessions us
             JOIN survey_units u ON u.id = us.unit_id AND u.type = 'Survey'
             WHERE us.run_session_id = ${f.run_session_id}`
        );
        const usId = parseInt(usRow[0].id, 10);

        // Pre-condition: server hasn't expired the row.
        let s = dbState(usId);
        expect(s.expired, 'server has not expired yet').toBeNull();

        // Fast-forward client clock. setTimeout fires; modal mounts.
        await page.clock.fastForward(3 * 60 * 1000);
        await expect(page.locator('.modal:visible').first().locator('.reload-btn'))
            .toBeVisible({ timeout: 5000 });

        // Drift point: server-side the row is still alive. Only the
        // client thinks it's expired.
        s = dbState(usId);
        expect(s.expired, 'server still has expired=NULL — modal fired client-only').toBeNull();
    });
});
