// Superadmin "Personal Data" overview (admin/advanced/personal_data, v1.10.0).
//
//   npx playwright test --config tests/e2e/playwright.config.js personal-data-audit
//
// Read-only. Auth via the cached admin storage state (helpers/admin.js); the
// dev login must be a superadmin (admin >= 10) for the page to render. The
// report is a heuristic over survey_items, so the spec cross-checks the
// page's totals against a lower bound it computes itself from the DB (the
// by-construction item types) rather than pinning numbers.

const { test, expect } = require('@playwright/test');
const { ADMIN_BASE, STATE_PATH, ensureAdminState } = require('./helpers/admin');
const { dbQuery } = require('./helpers/db');

const NOISE = /xdebug|Deprecated:|Notice:|^Warning:|favicon/i;
const PHP_ERROR = /Fatal error|Parse error|Uncaught|Undefined (variable|array key|property)|Call to (a member|undefined)/i;
const TYPED = ['email', 'tel', 'request_phone', 'geopoint', 'file', 'ip', 'browser', 'server', 'referrer', 'push_notification'];

test.use({ storageState: STATE_PATH });

test.beforeAll(async ({ browser }) => {
    await ensureAdminState(browser);
});

test('personal-data overview renders, sums up, and links every flagged survey', async ({ page }) => {
    const consoleErrors = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error' && !NOISE.test(msg.text())) consoleErrors.push(msg.text());
    });

    const resp = await page.goto(`${ADMIN_BASE}/admin/advanced/personal_data`);
    expect(resp.status()).toBe(200);
    await expect(page.locator('#personal-data-audit h1')).toContainText('Personal Data');
    expect(await page.locator('body').innerText()).not.toMatch(PHP_ERROR);
    await expect(page.locator('.alert-warning')).toHaveCount(0);

    // Menu entry exists for the superadmin (inside the collapsed Advanced dropdown).
    await expect(page.locator('.dropdown-menu a[href*="/admin/advanced/personal_data"]')).toHaveCount(1);

    // Four totals, all integers.
    const totals = {};
    for (const key of ['admins', 'runs', 'surveys', 'items']) {
        const text = await page.locator(`.info-box-number[data-total="${key}"]`).innerText();
        expect(text).toMatch(/^\d+$/);
        totals[key] = parseInt(text, 10);
    }

    // Lower bound: every by-construction item type is flagged regardless of name.
    const typed = dbQuery(
        `SELECT COUNT(*) AS n, COUNT(DISTINCT i.study_id) AS s FROM survey_items i JOIN survey_studies st ON st.id = i.study_id `
        + `WHERE i.type IN (${TYPED.map((t) => `'${t}'`).join(',')})`
    )[0];
    expect(totals.items).toBeGreaterThanOrEqual(parseInt(typed.n, 10));
    expect(totals.surveys).toBeGreaterThanOrEqual(parseInt(typed.s, 10));

    if (totals.items === 0) {
        await expect(page.locator('.alert-success')).toContainText('No item');
        return;
    }

    // Every admin block lists at least one survey with at least one flagged
    // item, and the counts add up to the totals (a survey used in several
    // runs is listed under each, so item rows can only exceed the total).
    const blocks = page.locator('.admin-block');
    expect(await blocks.count()).toBe(totals.admins);
    let listedItems = 0;
    const listedSurveys = new Set();
    const listedRuns = new Set();
    for (const block of await blocks.all()) {
        const surveys = block.locator('td[data-study-id]');
        expect(await surveys.count()).toBeGreaterThan(0);
        for (const td of await surveys.all()) listedSurveys.add(await td.getAttribute('data-study-id'));
        for (const td of await block.locator('td[data-run-id]').all()) listedRuns.add(await td.getAttribute('data-run-id'));
        listedItems += await block.locator('td code').count();
        expect(await block.locator('span.label').count()).toBeGreaterThan(0);
    }
    expect(listedItems).toBeGreaterThanOrEqual(totals.items);
    expect(listedSurveys.size).toBe(totals.surveys);
    expect(listedRuns.size).toBe(totals.runs);

    // Only the logged-in superadmin's own runs/surveys are linked — the admin
    // run/survey pages resolve names against the current user, so a link to
    // someone else's would 401/404.
    const me = dbQuery(`SELECT id FROM survey_users WHERE email = '${process.env.FORMR_DEV_ADMIN_EMAIL || ''}'`)[0];
    if (me) {
        for (const block of await blocks.all()) {
            const own = (await block.getAttribute('data-user-id')) === me.id;
            expect(await block.locator('td[data-run-id] a, td[data-study-id] a').count() > 0).toBe(own && (await block.locator('td[data-run-id], td[data-study-id]').count()) > 0);
        }
    }

    await page.screenshot({ path: '.playwright-mcp/personal-data-audit.png', fullPage: true });
    expect(consoleErrors).toEqual([]);
});
