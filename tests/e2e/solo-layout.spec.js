// Solo-layout tests (step controller — replaced the patch-066 scroll-snap skin).
//
// `survey_studies.layout` has these effects this suite covers:
//   1. FormRenderer emits `data-layout="solo|default"` on the form root.
//   2. js/solo/controller.js shows ONE .form-group at a time as
//      `.fmr-solo-current` (no scroll-snap container) with a fixed Back/OK nav.
//   3. Single-choice picks auto-advance to the next step; fitting steps lock
//      page scroll so the entrance animation can't bounce.
//
// Both modes share one persistent run (`e2e-aw-v2`) and one study
// (`e2e_all_widgets_v2`). Rather than maintaining a second study/run
// fixture, the suite toggles the study's `layout` column between
// default and solo via the admin UI (using global-setup's storageState)
// and resets in `afterAll`. Safe because playwright.config.js pins
// `workers: 1`, so nothing else races against the toggle.

const path = require('node:path');
const { test, expect } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v2 = require('./helpers/v2Form');
const db = require('./helpers/db');
const geo = require('./helpers/geometry');
const { walkSolo } = require('./helpers/solo');

// The short-phone viewport that was NOT covered when the iOS overlap/lock-trap
// bug shipped: the only mobile size previously tested was 375x812 (tall). At
// 375x667 (iPhone SE / 8, and the effective height of a 6.x phone once the
// Safari toolbars are showing) the fixed footer+nav eat enough of the bottom
// that an un-centred or scroll-locked control lands under them. Geometry is
// measured against visualViewport, so a real keyboard/toolbar offset is
// reflected the same way it is on a device.
const SHORT = { width: 375, height: 667 };

const RUN = () => runName('all_widgets', 'v2');
// The `e2e-aw-v2` run's Form unit binds to a study named `all_widgets`
// via survey_units.form_study_id — not `e2e_all_widgets_v2`. Toggling
// `layout` on `all_widgets` is what actually flows through to the rendered
// page. (Verified by inspecting survey_units.form_study_id → survey_studies.id.)
const STUDY = 'all_widgets';
const ADMIN_URL = process.env.FORMR_DEV_URL || 'https://formr.researchmixtape.com';
const ADMIN_STATE = path.resolve(__dirname, 'setup/admin-state.json');

// Walk through the survey-settings admin UI to set the study's layout
// column. Uses the persistent admin storageState. Returns true on
// success; throws on failure so beforeAll/afterAll surface the issue.
async function setStudyLayout(browser, study, value) {
    const ctx = await browser.newContext({
        storageState: ADMIN_STATE,
        ignoreHTTPSErrors: true,
    });
    const page = await ctx.newPage();
    try {
        await page.goto(`${ADMIN_URL}/admin/survey/${study}/`, { waitUntil: 'domcontentloaded' });
        const sel = page.locator('select[name="layout"]');
        await expect(sel).toBeVisible({ timeout: 15000 });
        if ((await sel.inputValue()) === value) return; // already there
        await sel.selectOption(value);
        await Promise.all([
            page.waitForLoadState('domcontentloaded'),
            page.getByRole('button', { name: /save settings/i }).click(),
        ]);
        await page.goto(`${ADMIN_URL}/admin/survey/${study}/`, { waitUntil: 'domcontentloaded' });
        expect(await page.locator('select[name="layout"]').inputValue()).toBe(value);
    } finally {
        await ctx.close().catch(() => {});
    }
}

test.describe('solo-layout: default mode (regression)', () => {

    test('default-layout study emits data-layout="default"', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await expect(page.locator(v2.FORM_SELECTOR).first()).toBeVisible({ timeout: 20000 });
        expect(await v2.form(page).getAttribute('data-layout')).toBe('default');
    });

});

test.describe('solo-layout: solo mode', () => {

    test.beforeAll(async ({ browser }) => {
        await setStudyLayout(browser, STUDY, 'solo');
    });

    test.afterAll(async ({ browser }) => {
        await setStudyLayout(browser, STUDY, 'default');
    });

    test('emits data-layout="solo"', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await expect(page.locator(v2.FORM_SELECTOR).first()).toBeVisible({ timeout: 20000 });
        expect(await v2.form(page).getAttribute('data-layout')).toBe('solo');
    });

    test('shows exactly one step at a time (.fmr-solo-current)', async ({ page, baseURL }) => {
        // The step controller (js/solo/controller.js) replaced the patch-066
        // scroll-snap skin: instead of a scroll container with snap points, it
        // displays one .form-group at a time as .fmr-solo-current.
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);

        const r = await page.evaluate(() => {
            const groups = Array.from(document.querySelectorAll(
                'form.fmr-form-v2[data-layout="solo"] section.fmr-page:not([hidden]) > .form-group'
            ));
            const visible = groups.filter((g) => getComputedStyle(g).display !== 'none');
            return {
                total: groups.length,
                visible: visible.length,
                currentCount: document.querySelectorAll('.fmr-solo-current').length,
            };
        });
        expect(r.total).toBeGreaterThan(1);   // a multi-item page
        expect(r.visible).toBe(1);            // only one step shown
        expect(r.currentCount).toBe(1);       // exactly one seated step
    });

    test('current step fills the viewport and shows the Back/OK nav', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);

        const r = await page.evaluate(() => {
            const cur = document.querySelector('.fmr-solo-current');
            const back = document.querySelector('.fmr-solo-back');
            return {
                hasCurrent: !!cur,
                minH: cur ? parseFloat(getComputedStyle(cur).minHeight) : 0,
                vh: window.innerHeight,
                hasNav: !!document.querySelector('.fmr-solo-nav'),
                hasOk: !!document.querySelector('.fmr-solo-ok'),
                backHiddenOnFirst: back ? getComputedStyle(back).visibility === 'hidden' : null,
            };
        });
        expect(r.hasCurrent).toBe(true);
        expect(r.minH).toBeGreaterThanOrEqual(r.vh - 60);   // ~100vh / 100svh
        expect(r.hasNav).toBe(true);
        expect(r.hasOk).toBe(true);
        expect(r.backHiddenOnFirst).toBe(true);             // no Back on step 1
    });

    test('a fitting step locks page scroll (no phantom over-scroll)', async ({ page, baseURL }) => {
        // Bug #1: a step is min-height:100vh, so when its content fits it
        // shouldn't scroll. The controller adds html.fmr-solo-locked on a
        // fitting step so the entrance animation's transient transform can't
        // produce a wheel-bounce; tall steps (note/plot) stay scrollable.
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);

        const r = await page.evaluate(async () => {
            const sleep = (ms) => new Promise((res) => setTimeout(res, ms));
            const de = document.documentElement;
            // Step forward until a settled step fits the viewport (skip the
            // tall intro note). Notes are optional, so OK advances freely.
            for (let i = 0; i < 6; i++) {
                await sleep(700);
                const cur = document.querySelector('.fmr-solo-current');
                if (cur && cur.offsetHeight <= window.innerHeight + 2) {
                    return {
                        found: true,
                        locked: de.classList.contains('fmr-solo-locked'),
                        // overflow:hidden on html+body is what blocks the user's
                        // wheel/touch bounce. (Programmatic scrollTo can still move
                        // an overflow:hidden element, so we don't assert via scroll.)
                        htmlOverflowHidden: getComputedStyle(de).overflowY === 'hidden',
                        bodyOverflowHidden: getComputedStyle(document.body).overflowY === 'hidden',
                    };
                }
                const inp = cur && cur.querySelector('input:not([type=hidden]):not([disabled]),textarea');
                if (inp) { inp.value = inp.value || 'x'; inp.dispatchEvent(new Event('input', { bubbles: true })); }
                const ok = document.querySelector('.fmr-solo-ok');
                if (ok) ok.click();
            }
            return { found: false };
        });
        expect(r.found, 'expected a fitting step within the first few').toBe(true);
        // The lock is what prevents the phantom over-scroll/wheel-bounce: even
        // if non-step in-flow chrome (e.g. the audio/video "unverified" banner)
        // makes document scrollHeight exceed the viewport, overflow:hidden keeps
        // the page pinned so the participant can't bounce-scroll a fitting step.
        expect(r.locked).toBe(true);
        expect(r.htmlOverflowHidden).toBe(true);
        expect(r.bodyOverflowHidden).toBe(true);
    });

    test('single-choice auto-advances to the next step (OK hidden)', async ({ page, baseURL }) => {
        // Bug #4: picking a single-choice radio card advances to the next step
        // (Typeform-style) and OK is hidden on such steps.
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);

        const r = await page.evaluate(async () => {
            const sleep = (ms) => new Promise((res) => setTimeout(res, ms));
            const cur = () => document.querySelector('.fmr-solo-current');
            const typeOf = (g) => (g ? (g.className.match(/item-[a-z_0-9]+/) || ['?'])[0] : null);
            for (let i = 0; i < 8; i++) {
                const g = cur();
                if (!g) break;
                const isSingleRadio = typeOf(g) === 'item-mc'
                    && g.querySelector('input[type=radio]:not([disabled])')
                    && !g.querySelector('input[type=checkbox], select, textarea');
                if (isSingleRadio) {
                    const okHidden = getComputedStyle(document.querySelector('.fmr-solo-ok')).display === 'none';
                    const before = g;
                    const lbl = g.querySelector('.mc-table > label') || g.querySelector('input[type=radio]');
                    lbl.click();
                    await sleep(900);   // 450ms auto-advance + 150ms leave + entrance
                    return { reached: true, okHidden, advanced: cur() !== before };
                }
                const inp = g.querySelector('input:not([type=hidden]):not([disabled]),textarea');
                if (inp) { inp.value = inp.value || 'x'; inp.dispatchEvent(new Event('input', { bubbles: true })); }
                document.querySelector('.fmr-solo-ok').click();
                await sleep(700);
            }
            return { reached: false };
        });
        expect(r.reached, 'expected to reach a single-choice mc step').toBe(true);
        expect(r.okHidden).toBe(true);   // single-choice hides OK (picking advances)
        expect(r.advanced).toBe(true);   // picking auto-advanced
    });

    test('items fill the mobile viewport at 375x812', async ({ page, baseURL }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);

        const measured = await page.evaluate(() => {
            const item = document.querySelector(
                'form.fmr-form-v2[data-layout="solo"] section.fmr-page:not([hidden]) .form-group'
            );
            if (!item) return null;
            return {
                minHeightPx: parseFloat(getComputedStyle(item).minHeight),
                vh: window.innerHeight,
            };
        });
        expect(measured).not.toBeNull();
        // _solo.scss: min-height 100vh, 100svh where supported (svh can
        // be smaller than vh by browser-chrome offset — tolerate ±60px).
        expect(measured.minHeightPx).toBeGreaterThanOrEqual(measured.vh - 60);
    });

    test('records layout="solo" in the response paradata', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        expect(await v2.form(page).getAttribute('data-layout')).toBe('solo');
        await page.waitForTimeout(300);
        const rows = db.dbQuery(
            "SELECT us.layout AS layout FROM survey_unit_sessions us " +
            "JOIN survey_run_sessions rs ON rs.id = us.run_session_id " +
            "JOIN survey_runs r ON r.id = rs.run_id " +
            "WHERE r.name = 'e2e-aw-v2' ORDER BY us.id DESC LIMIT 1",
        );
        expect(rows[0] && rows[0].layout, 'solo response must record layout=solo').toBe('solo');
    });

    test('progress bar is monotonic (never jumps backward)', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        const seq = await page.evaluate(async () => {
            const sleep = (ms) => new Promise((res) => setTimeout(res, ms));
            const bar = document.querySelector('[data-fmr-progress-bar]');
            const read = () => parseFloat(bar.getAttribute('aria-valuenow') || '0');
            const vals = [read()];
            // advance several steps and sample the bar after each
            for (let i = 0; i < 5; i++) {
                const cur = document.querySelector('.fmr-solo-current');
                if (!cur) break;
                const inp = cur.querySelector('input:not([type=hidden]):not([disabled]),textarea');
                if (inp) { inp.value = inp.value || 'x'; inp.dispatchEvent(new Event('input', { bubbles: true })); }
                document.querySelector('.fmr-solo-ok').click();
                await sleep(650);
                vals.push(read());
            }
            return vals;
        });
        // never decreases between consecutive samples
        for (let i = 1; i < seq.length; i++) {
            expect(seq[i], `progress went backward: ${seq.join(' -> ')}`).toBeGreaterThanOrEqual(seq[i - 1]);
        }
        expect(seq[seq.length - 1], 'progress should advance').toBeGreaterThan(seq[0]);
    });

    // --- GEOMETRY at 375x667 (the regression the iOS bug exposed) ----------
    // These assert pixels, not DOM presence: the seated step's interactive
    // controls must not intersect the fixed chrome (progress bar / Back-OK nav
    // / pinned run footer) and must be reachable inside the visual viewport.
    // The shipped bug — first text item painted under the footer, controls
    // trapped by the scroll-lock — passed every DOM-only check but fails these.

    test('first step is clear of fixed chrome and reachable (375x667)', async ({ page, baseURL }) => {
        await page.setViewportSize(SHORT);
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        await page.waitForTimeout(400); // let the seat/lock/focus settle
        await geo.assertSoloStepGeometry(page, { label: 'first step @375x667' });
    });

    test('every walked step stays clear of fixed chrome and reachable (375x667)', async ({ page, baseURL }) => {
        await page.setViewportSize(SHORT);
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        const visited = await walkSolo(page, {
            maxSteps: 8,
            settle: 800,
            onStep: async ({ type, index }) => {
                await page.waitForTimeout(250); // settle entrance animation
                await geo.assertSoloStepGeometry(page, { label: `step ${index} (${type}) @375x667` });
            },
        });
        expect(visited.length, 'expected to walk several solo steps').toBeGreaterThan(2);
    });

    // Regression: the v2 "unverified types" heads-up is rendered above the
    // <form>, so in flow it sat above EVERY step, pushing each one down ~190px
    // and making a step that fits the viewport scrollable (reported as "select
    // screens scroll though everything fits"). The controller now moves it into
    // the first step; later steps must start at the top with no phantom scroll.
    test('above-form notice does not push later steps (no phantom scroll) @375x667', async ({ page, baseURL }) => {
        await page.setViewportSize(SHORT);
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        // step off the first step (which legitimately holds the relocated notice)
        await walkSolo(page, { maxSteps: 2, settle: 800, onStep: async () => {} });
        await page.waitForTimeout(300);
        const m = await page.evaluate(() => {
            const cur = document.querySelector('.fmr-solo-current');
            const absTop = (el) => { let t = 0; for (let n = el; n; n = n.offsetParent) t += n.offsetTop; return t; };
            const de = document.documentElement;
            const notice = document.querySelector('.fmr-unverified-types');
            return {
                stepAbsTop: Math.round(absTop(cur)),
                scrollBy: Math.round(de.scrollHeight - window.innerHeight),
                locked: de.classList.contains('fmr-solo-locked'),
                noticeAbove: notice ? absTop(notice) < absTop(cur) - 1 : false,
            };
        });
        expect(m.noticeAbove, 'the notice must not sit above a later step').toBe(false);
        expect(m.stepAbsTop, 'a later step should start at the top (nothing above it)').toBeLessThanOrEqual(8);
        // a later step that fits should lock (no phantom scroll); if it genuinely
        // overflows it may stay scrollable, so only assert the no-banner-push.
    });

    // Regression for the iOS keyboard hand-off (the "cue OK as a tab-to-next"
    // idea): advancing via the OK button to a text-field step must seat that
    // step and focus its field SYNCHRONOUSLY inside the click handler — that is
    // the only thing iOS accepts as the user gesture that raises the keyboard.
    // Verified structurally (no real keyboard needed): focus lands in the same
    // tick as the click. The real-device delta is checked in solo-real-device.
    test('OK on a text step focuses the next text field synchronously (iOS keyboard hand-off)', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        const r = await page.evaluate(async () => {
            const sleep = (ms) => new Promise((res) => setTimeout(res, ms));
            const typeOf = (g) => (g ? (g.className.match(/item-[a-z_0-9]+/) || ['?'])[0] : null);
            const isText = (g) => !!(g && g.querySelector('input[type=text],input[type=email],input[type=number],input[type=url],input[type=tel],textarea'));
            const advance = () => {
                const cur = document.querySelector('.fmr-solo-current');
                const single = typeOf(cur) === 'item-mc' && cur.querySelector('input[type=radio]:not([disabled])') && !cur.querySelector('input[type=checkbox],select,textarea');
                if (single) { (cur.querySelector('.mc-table > label') || cur.querySelector('input[type=radio]')).click(); return; }
                const inp = cur.querySelector('input:not([type=hidden]):not([disabled]),textarea');
                if (inp) { const t = (inp.type || inp.tagName).toLowerCase(); if (['text', 'number', 'email', 'url', 'tel', 'search', 'textarea'].includes(t)) { inp.value = inp.value || 'x'; inp.dispatchEvent(new Event('input', { bubbles: true })); } }
                const ok = document.querySelector('.fmr-solo-ok'); if (ok) ok.click();
            };
            for (let i = 0; i < 14; i++) {
                const cur = document.querySelector('.fmr-solo-current');
                if (isText(cur)) {
                    const inp = cur.querySelector('input:not([type=hidden]):not([disabled]),textarea');
                    if (inp) { const t = (inp.type || inp.tagName).toLowerCase(); inp.value = t === 'email' ? 'a@b.co' : (t === 'url' ? 'https://x.co' : (t === 'number' ? '3' : 'x')); inp.dispatchEvent(new Event('input', { bubbles: true })); }
                    document.querySelector('.fmr-solo-ok').click();      // user-gesture path
                    const ae = document.activeElement;                   // SYNCHRONOUS read
                    const next = document.querySelector('.fmr-solo-current');
                    return { advancedSync: next !== cur, nextIsText: isText(next), activeInNext: !!(next && ae && next.contains(ae)) };
                }
                advance(); await sleep(550);
            }
            return { reached: false };
        });
        expect(r.advancedSync, 'OK should seat the next step synchronously').toBe(true);
        if (r.nextIsText) {
            expect(r.activeInNext, 'next text field must be focused in the same tick as the OK click').toBe(true);
        }
    });

});

test.describe('solo-layout: admin round-trip', () => {

    test('Layout dropdown persists across reload', async ({ browser }) => {
        // Uses storageState. Idempotent: starts at default, flips to
        // solo, asserts, flips back to default, asserts. If something
        // crashes mid-way, the next test run's afterAll in the solo
        // describe will reset.
        const ctx = await browser.newContext({
            storageState: ADMIN_STATE,
            ignoreHTTPSErrors: true,
        });
        const page = await ctx.newPage();
        try {
            await page.goto(`${ADMIN_URL}/admin/survey/${STUDY}/`, { waitUntil: 'domcontentloaded' });
            await expect(page.locator('select[name="layout"]')).toBeVisible({ timeout: 10000 });
            expect(await page.locator('select[name="layout"]').inputValue()).toBe('default');

            await page.locator('select[name="layout"]').selectOption('solo');
            await Promise.all([
                page.waitForLoadState('domcontentloaded'),
                page.getByRole('button', { name: /save settings/i }).click(),
            ]);
            await page.goto(`${ADMIN_URL}/admin/survey/${STUDY}/`, { waitUntil: 'domcontentloaded' });
            expect(await page.locator('select[name="layout"]').inputValue()).toBe('solo');

            await page.locator('select[name="layout"]').selectOption('default');
            await Promise.all([
                page.waitForLoadState('domcontentloaded'),
                page.getByRole('button', { name: /save settings/i }).click(),
            ]);
            await page.goto(`${ADMIN_URL}/admin/survey/${STUDY}/`, { waitUntil: 'domcontentloaded' });
            expect(await page.locator('select[name="layout"]').inputValue()).toBe('default');
        } finally {
            await ctx.close().catch(() => {});
        }
    });

});
