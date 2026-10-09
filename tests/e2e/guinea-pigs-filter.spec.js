/**
 * "Old Guinea Pigs" — the run menu's test-session view.
 *
 * The link used to be `user_overview?session=XXX`, which only ever worked
 * because the session search was a substring match: a test code is
 * <animalname>XXX<token>, so XXX never occurs at the start. 890ba81e dropped
 * the leading wildcard so that search could use the index on `session`, and
 * the link silently became "codes beginning with XXX" — empty on every run,
 * with no error to explain it. The view now filters on the `testing` flag.
 *
 * Browser-first on purpose (see documentation/agent_doc/LOG_STATE_AUDIT.md):
 * the defect was invisible to the PHP — every layer returned exactly what it
 * was asked for — and only showed up as an empty table an admin was looking
 * at. So the assertions are on what the page renders, cross-checked against
 * the database for the counts a page can't prove on its own.
 *
 * Read-only: it picks an existing run and never writes.
 */
const { test, expect } = require('./helpers/test');
const { dbQuery } = require('./helpers/db');
const { ensureAdminState, STATE_PATH, ADMIN_BASE } = require('./helpers/admin');

test.use({ storageState: STATE_PATH });

/** A run owned by the admin holding both guinea pigs and real sessions. */
let RUN = null;
let COUNTS = null;

test.beforeAll(async ({ browser }) => {
    await ensureAdminState(browser);
    const rows = dbQuery(`
        SELECT r.name AS name,
               SUM(s.testing = 1) AS guinea,
               SUM(s.testing = 0 OR s.testing IS NULL) AS real_users
          FROM survey_run_sessions s
          JOIN survey_runs r ON r.id = s.run_id
         GROUP BY r.id
        HAVING guinea > 0 AND real_users > 0
         ORDER BY guinea DESC
         LIMIT 1;
    `);
    if (rows.length) {
        RUN = rows[0].name;
        COUNTS = {
            guinea: parseInt(rows[0].guinea, 10),
            real: parseInt(rows[0].real_users, 10),
        };
    }
});

/**
 * One row per session; the toggle button's icon is the rendered truth about
 * the flag (fa-stethoscope = testing, fa-heartbeat = not). Reading the icon
 * rather than the DB is the point: it is what the admin sees.
 */
async function rowFlags(page) {
    return page.$$eval('table.has-actions tbody tr', (trs) => trs.map((tr) => {
        const icon = tr.querySelector('a[href*="ajax_toggle_testing"] i');
        if (!icon) return 'none';
        return icon.classList.contains('fa-stethoscope') ? 'testing' : 'real';
    }));
}

test.describe('Old Guinea Pigs', () => {
    test('the menu link filters by the testing flag and lists only guinea pigs', async ({ page }) => {
        test.skip(!RUN, 'no run on this instance has both test and real sessions');

        await page.goto(`${ADMIN_BASE}/admin/run/${RUN}/overview`);

        // The link itself is half the regression: it carried the filter as a
        // session-code substring, which is the thing that stopped working.
        const href = await page.getAttribute('a:has-text("Old Guinea Pigs")', 'href');
        expect(href, 'the menu must filter by flag, not by a code substring')
            .toContain('testing=1');
        expect(href).not.toContain('session=XXX');

        await page.click('a:has-text("Old Guinea Pigs")');
        await page.waitForLoadState('networkidle');

        const flags = await rowFlags(page);
        expect(flags.length, 'the guinea-pig view must not be empty').toBeGreaterThan(0);
        expect(flags.filter((f) => f !== 'testing'),
            'every row under testing=1 must carry the testing flag').toEqual([]);

        // The heading counts the whole filtered set, not just this page, so it
        // catches a filter that silently applies to the page but not the count.
        const heading = await page.textContent('.box-title:has-text("User Overview")');
        expect(heading).toContain(`${COUNTS.guinea} users`);

        // The filter is visible in the form, so an admin can see why the table
        // looks different and switch it off.
        expect(await page.inputValue('select[name=testing]')).toBe('1');
    });

    test('the inverse filter lists real participants and excludes guinea pigs', async ({ page }) => {
        test.skip(!RUN, 'no run on this instance has both test and real sessions');

        await page.goto(`${ADMIN_BASE}/admin/run/${RUN}/user_overview?testing=0`);
        await page.waitForLoadState('networkidle');

        const flags = await rowFlags(page);
        expect(flags.length).toBeGreaterThan(0);
        expect(flags.filter((f) => f !== 'real')).toEqual([]);

        const heading = await page.textContent('.box-title:has-text("User Overview")');
        expect(heading).toContain(`${COUNTS.real} users`);
    });

    test('the unfiltered view still shows everyone', async ({ page }) => {
        test.skip(!RUN, 'no run on this instance has both test and real sessions');

        await page.goto(`${ADMIN_BASE}/admin/run/${RUN}/user_overview`);
        await page.waitForLoadState('networkidle');

        const heading = await page.textContent('.box-title:has-text("User Overview")');
        expect(heading).toContain(`${COUNTS.guinea + COUNTS.real} users`);
        expect(await page.inputValue('select[name=testing]')).toBe('');
    });

    test('session=XXX finds nothing — the prefix semantics this replaced', async ({ page }) => {
        test.skip(!RUN, 'no run on this instance has both test and real sessions');

        // Not a bug being reported: the prefix match is deliberate (it is what
        // makes the search use the index on `session`). Pinned so that if the
        // wildcard ever comes back, whoever does it sees that this view no
        // longer depends on it — and so the reason the link changed stays on
        // the record rather than living only in a commit message.
        await page.goto(`${ADMIN_BASE}/admin/run/${RUN}/user_overview?session=XXX`);
        await page.waitForLoadState('networkidle');

        expect(await rowFlags(page), 'test codes carry XXX in the middle, not at the start')
            .toEqual([]);
    });
});
