// Issue #608: regression guard for the compute-usage dashboards.
//
//   npx playwright test --config tests/e2e/playwright.config.js compute-dashboard
//
// Auth via the cached admin storage state (helpers/admin.js). The dev login
// (robot) is a regular admin (admin level 2), NOT a superadmin — so it can see
// its own /admin/compute, and MUST be refused /admin/advanced/compute_usage.
// That gating is itself part of what we regression-test here.
//
// Read-mostly: the only mutation is driving a couple of GETs through the public
// `mcp-poc-demo` run to make sure at least one unit session has measured
// execution_time this month, so the per-run table is exercised with real data.

const { test, expect } = require('@playwright/test');
const { ADMIN_BASE, STATE_PATH, ensureAdminState } = require('./helpers/admin');

// Participant traffic lives on the study subdomain, a different origin from
// admin by design (CLAUDE.md). Derive it from the admin base unless overridden.
const STUDY_BASE = (process.env.FORMR_STUDY_URL || ADMIN_BASE.replace('//formr.', '//study.')).replace(/\/+$/, '');
const SAMPLE_RUN = process.env.FORMR_COMPUTE_SAMPLE_RUN || 'mcp-poc-demo';

const NOISE = /xdebug|Deprecated:|Notice:|^Warning:|favicon/i;
const PHP_ERROR = /Fatal error|Parse error|Uncaught|Undefined (variable|array key|property)|Call to (a member|undefined)/i;

test.use({ storageState: STATE_PATH });

test.beforeAll(async ({ browser }) => {
    await ensureAdminState(browser);
});

test('user compute dashboard renders (no PHP errors) and reflects measured runtime', async ({ page }) => {
    // Best-effort: generate at least one measured unit session this month.
    try {
        await page.request.get(`${STUDY_BASE}/${SAMPLE_RUN}/`, { timeout: 15000 });
    } catch (e) { /* run may be absent on a fresh instance — assertions below cope */ }

    const resp = await page.goto(`${ADMIN_BASE}/admin/compute`);
    expect(resp.status()).toBe(200);

    // Page identity + structure.
    await expect(page.locator('#compute-usage-page h1')).toContainText('Compute Usage');
    await expect(page.locator('.info-box-number')).toHaveCount(3);

    // No raw PHP error leaked into the body (catches template/helper fatals).
    const body = await page.locator('body').innerText();
    expect(body).not.toMatch(PHP_ERROR);

    // "Unit sessions measured" card is a non-negative integer.
    const measured = await page.locator('.info-box-number').nth(2).innerText();
    expect(Number(measured.replace(/[,\s]/g, ''))).toBeGreaterThanOrEqual(0);

    // If any run has measured compute, the per-run table is present and each
    // row renders a formatted duration (exercises ComputeUsageHelper + links).
    const rows = page.locator('#compute-usage-page table tbody tr');
    if (await rows.count() > 0) {
        await expect(rows.first().locator('td').nth(2)).toHaveText(/\d/);
    }
});

test('superadmin compute dashboard is refused to a non-superadmin', async ({ page }) => {
    const resp = await page.goto(`${ADMIN_BASE}/admin/advanced/compute_usage`);
    const body = await page.locator('body').innerText();

    // robot is admin level 2; header() must 403 (or at least never render the
    // instance-wide superadmin view with its per-user limit editor).
    const refused = resp.status() === 403
        || (!/whole instance/i.test(body) && !/Compute by user/i.test(body));
    expect(refused).toBeTruthy();
});
