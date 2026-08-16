/**
 * Result coverage — `no_sender`.
 *
 * An Email unit with no sending account. That cannot be built with the
 * ordinary admin account, because `Email::changeSettings()` substitutes the
 * first of `Site::getCurrentUser()->getEmailAccounts()` whenever `account_id`
 * is null — so as long as the author has ANY account, one gets picked. The
 * result therefore needs an author with none, which is an authoring state a
 * real study hits on day one, before anyone has configured SMTP.
 *
 * So this fixture has a different owner: a second admin account with zero
 * email accounts, created with
 *
 *   docker exec formr_app php bin/add_user.php \
 *     -e rescov-nosender@researchmixtapes.com -p <generated> -l 1
 *
 * (level 1 — `-l 2` is refused once any user exists: "Cannot create
 * superadmins when users already exist".) Its credentials live in
 * tests/e2e/setup/nosender-user-state.json, gitignored like the other
 * setup state. The run `rescov-nosender` is owned by that user and holds one
 * Email with `account_id` NULL and an R-literal recipient, built through the
 * run editor as that user.
 *
 * The run is private, so the participant enters it the way its owner would:
 * the "Test Run" button mints a session and redirects to the participant URL
 * with its code.
 *
 * See documentation/agent_doc/LOG_STATE_AUDIT.md.
 */
const fs = require('node:fs');
const path = require('node:path');
const { test, expect } = require('./helpers/test');
const { ADMIN_BASE, dismissConsent } = require('./helpers/admin');
const { sessionRows, runExists, recordObserved } = require('./helpers/results');

const RUN = 'rescov-nosender';
const CREDS_PATH = path.resolve(__dirname, 'setup/nosender-user-state.json');

test('an Email whose author has no email account records no_sender', async ({ page }) => {
    test.skip(!runExists(RUN), `fixture run '${RUN}' is missing — see the recipe above`);
    let creds = null;
    try { creds = JSON.parse(fs.readFileSync(CREDS_PATH, 'utf8')); } catch (e) { /* absent */ }
    test.skip(!creds, `no credentials at ${CREDS_PATH} — create the user as described above`);

    await page.goto(`${ADMIN_BASE}/admin/account/login`);
    await dismissConsent(page);
    await page.fill('input[name=email]', creds.email);
    await page.fill('input[name=password]', creds.password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}),
        page.click('button[type=submit], input[type=submit]'),
    ]);
    await page.waitForSelector('a[href*="logout"]', { timeout: 15000 });

    await page.goto(`${ADMIN_BASE}/admin/run/${RUN}/create_new_test_code`);
    await page.waitForLoadState('networkidle');
    expect(page.url(), 'the Test Run button should land on the participant URL')
        .toContain(`/${RUN}/`);

    const email = sessionRows(RUN).pop();
    expect(email.result,
        'with no account to send from, the unit must say so on the row — the '
        + 'other copy of this message goes to alert(), which a daemon-driven '
        + 'execute discards entirely')
        .toBe('no_sender');
    expect(email.log).toMatch(/did not set up an email account/i);

    recordObserved(['no_sender']);
});
