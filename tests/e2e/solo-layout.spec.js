// Solo-layout skin tests (patch 066).
//
// `survey_studies.layout` has three effects this suite covers:
//   1. FormRenderer emits `data-layout="solo|default"` on the form root.
//   2. _solo.scss applies scroll-snap rules under
//      `.fmr-form-v2[data-layout="solo"]`.
//   3. main.js's gated `change`-event handler auto-advances scroll to
//      the next visible item.
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

    test('.form-group items get scroll-snap-align: start', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);

        const snapAligns = await page.evaluate(() => {
            const items = document.querySelectorAll(
                'form.fmr-form-v2[data-layout="solo"] section.fmr-page:not([hidden]) .form-group'
            );
            return Array.from(items).slice(0, 5).map((el) => getComputedStyle(el).scrollSnapAlign);
        });
        expect(snapAligns.length).toBeGreaterThan(0);
        for (const v of snapAligns) {
            expect(v).toMatch(/^start( start)?$/);
        }
    });

    test('.fmr-page is the scroll-snap container', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);

        const pageStyle = await page.evaluate(() => {
            const sec = document.querySelector('form.fmr-form-v2[data-layout="solo"] section.fmr-page:not([hidden])');
            if (!sec) return null;
            const cs = getComputedStyle(sec);
            return { snap: cs.scrollSnapType, overflow: cs.overflowY };
        });
        expect(pageStyle).not.toBeNull();
        expect(pageStyle.snap).toMatch(/y\s+mandatory/);
        expect(pageStyle.overflow).toBe('auto');
    });

    test('showif-hidden items drop scroll-snap-align', async ({ page, baseURL }) => {
        // x-showif toggles `.hidden` on the wrapper. We don't depend on a
        // real showif rule firing — inject `.hidden` on the first item
        // and confirm the CSS short-circuits the snap target.
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);

        const align = await page.evaluate(() => {
            const item = document.querySelector(
                'form.fmr-form-v2[data-layout="solo"] section.fmr-page:not([hidden]) .form-group'
            );
            if (!item) return null;
            item.classList.add('hidden');
            return getComputedStyle(item).scrollSnapAlign;
        });
        expect(align).toBe('none');
    });

    test('change event auto-advances scroll to next visible item', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);

        const advance = await page.evaluate(async () => {
            const sec = document.querySelector(
                'form.fmr-form-v2[data-layout="solo"] section.fmr-page:not([hidden])'
            );
            if (!sec) return { ok: false, reason: 'no visible page' };
            const items = Array.from(sec.querySelectorAll('.form-group')).filter(
                (el) => !el.classList.contains('hidden') && el.offsetParent !== null
            );
            if (items.length < 2) return { ok: false, reason: `only ${items.length} visible items` };

            // Find the first item that has an interactive input AND a
            // next-item to advance to. Skips note/instruction items.
            let currentIdx = -1, inp = null;
            for (let i = 0; i < items.length - 1; i++) {
                const candidate = items[i].querySelector('input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled])');
                if (candidate) { currentIdx = i; inp = candidate; break; }
            }
            if (!inp) return { ok: false, reason: 'no item with interactive input + next-item found' };

            items[currentIdx].scrollIntoView({ behavior: 'instant', block: 'start' });
            await new Promise((r) => setTimeout(r, 50));
            const before = items[currentIdx + 1].getBoundingClientRect().top;
            if (inp.tagName === 'SELECT') {
                const opt = inp.querySelector('option[value]:not([value=""])');
                if (opt) inp.value = opt.value;
            } else if (inp.type === 'radio' || inp.type === 'checkbox') {
                inp.checked = true;
            } else if ('value' in inp) {
                inp.value = inp.value || '42';
            }
            inp.dispatchEvent(new Event('change', { bubbles: true }));

            // Past 400ms debounce + smooth-scroll easing.
            await new Promise((r) => setTimeout(r, 1200));
            const after = items[currentIdx + 1].getBoundingClientRect().top;
            return { ok: true, before, after, currentIdx };
        });
        expect(advance.ok, advance.reason).toBe(true);
        expect(Math.abs(advance.after)).toBeLessThan(Math.abs(advance.before));
        expect(Math.abs(advance.after)).toBeLessThan(80);
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
