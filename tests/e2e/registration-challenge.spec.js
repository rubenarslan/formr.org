// Signup anti-bot challenge, driven in a real browser against the dev
// instance — whichever driver the instance has configured
// (registration.challenge = 'altcha' | 'turnstile'; neither → skipped).
//
//   npx playwright test --config tests/e2e/playwright.config.js registration-challenge
//
// What it proves that the unit tests can't: the widget's scripts survive the
// admin CSP (the Altcha Argon2id worker is registered by a nonced inline
// module; Turnstile's api.js must be nonced and its frame/connect origins
// widened), a solved challenge reaches the mailer, an unsolved one is
// refused, and a solved Altcha payload can't be replayed. Turnstile runs
// with Cloudflare's test keys (site 1x…AA always passes; a secret of
// 2x…AA makes every token fail — flip it in settings.php to see the
// refusal path, which the unsolved test covers for both drivers anyway).
//
// The dev instance has no reachable SMTP host, so "reached the mailer" is
// asserted on the guard log — a `verification` row whose outcome is
// anything but a refusal — plus the created account, not on a success
// banner. The 3-second minimum form age is real: each submission waits it
// out.

const { test, expect } = require('@playwright/test');
const { ADMIN_BASE } = require('./helpers/admin');
const { dbQuery, dbExecRaw } = require('./helpers/db');

const REGISTER = `${ADMIN_BASE}/admin/account/register`;
const MIN_FORM_MS = 3500;
const PREFIX = 'e2e-challenge-';

const VIOLATION_HOOK = () => {
    window.__cspViolations = [];
    document.addEventListener('securitypolicyviolation', (e) => {
        window.__cspViolations.push(`${e.effectiveDirective || e.violatedDirective} ${e.blockedURI}`);
    });
};

function uniqueEmail(tag) {
    return `${PREFIX}${tag}-${Date.now()}@researchmixtapes.com`;
}

async function openRegister(page) {
    await page.addInitScript(VIOLATION_HOOK);
    await page.goto(REGISTER);
    const consent = page.locator('#cc-main button.cm__btn').first();
    if (await consent.isVisible().catch(() => false)) {
        await consent.click();
    }
    await expect(page.locator('input[name=_form_minted]')).toHaveCount(1);
    const opened = Date.now();
    if (await page.locator('altcha-widget').count()) return { driver: 'altcha', opened };
    if (await page.locator('.cf-turnstile').count()) return { driver: 'turnstile', opened };
    return { driver: null, opened };
}

async function solve(page, driver) {
    if (driver === 'altcha') {
        // the checkbox input sits under an SVG; the label is the click target
        await page.locator('altcha-widget label').click();
        await expect(page.locator('altcha-widget .altcha[data-state="verified"]')).toHaveCount(1, { timeout: 60 * 1000 });
        return page.locator('input[name=altcha]').inputValue();
    }
    await expect.poll(() => page.locator('input[name="cf-turnstile-response"]').inputValue(), { timeout: 30 * 1000 }).not.toBe('');
    return page.locator('input[name="cf-turnstile-response"]').inputValue();
}

// `challenge`: undefined = submit whatever the widget put in its field;
// '' = strip the field so the server sees no challenge answer at all;
// a string = submit that instead (replay). Done in the same tick as the
// submit, because the Turnstile widget rewrites its hidden input on its own.
async function submit(page, email, opened, challenge) {
    await page.fill('input[name=email]', email);
    await page.fill('input[name=password]', 'E2e-challenge-pass-1!');
    await page.locator('input[name=agree_tos]').check({ force: true });
    const wait = MIN_FORM_MS - (Date.now() - opened);
    if (wait > 0) await page.waitForTimeout(wait);
    // Turnstile keeps a connection open, so 'networkidle' never fires;
    // every outcome re-renders the form (the success path redirects back
    // to it), so the fresh form token is the "response arrived" signal.
    const before = await page.locator('input[name=_form_minted]').inputValue();
    await Promise.all([
        page.waitForEvent('load'),
        page.locator('input[name=_form_minted]').evaluate((el, answer) => {
            if (answer !== undefined) {
                el.form.querySelectorAll('input[name=altcha], input[name="cf-turnstile-response"]').forEach((i) => i.remove());
                if (answer !== '') {
                    const replay = document.createElement('input');
                    replay.type = 'hidden';
                    replay.name = 'altcha';
                    replay.value = answer;
                    el.form.appendChild(replay);
                }
            }
            el.form.submit();
        }, challenge),
    ]);
    await expect(page.locator('input[name=_form_minted]')).not.toHaveValue(before);
}

async function cspViolations(page) {
    return page.evaluate(() => window.__cspViolations || []);
}

function guardRows(email) {
    return dbQuery(`SELECT type, outcome, user_id FROM survey_mail_guard_log WHERE recipient = '${email}' ORDER BY id`);
}

function userRows(email) {
    return dbQuery(`SELECT id, email_verified FROM survey_users WHERE email = '${email}'`);
}

function cleanup() {
    dbExecRaw(`DELETE FROM survey_users WHERE email LIKE '${PREFIX}%@researchmixtapes.com';
        DELETE FROM survey_mail_guard_log WHERE recipient LIKE '${PREFIX}%@researchmixtapes.com';`);
}

test.describe('registration challenge', () => {
    test.afterEach(() => cleanup());

    test('renders under the admin CSP and a solved challenge reaches the mailer', async ({ page }) => {
        const { driver, opened } = await openRegister(page);
        test.skip(!driver, 'registration.challenge is not configured on this instance');
        expect(await cspViolations(page), 'widget scripts blocked by CSP').toEqual([]);

        const payload = await solve(page, driver);
        expect(payload.length).toBeGreaterThan(0);
        expect(await cspViolations(page), 'solving the challenge tripped CSP').toEqual([]);

        const email = uniqueEmail(driver);
        await submit(page, email, opened);

        const rows = guardRows(email);
        expect(rows, 'the guard saw the verification mail').toHaveLength(1);
        expect(rows[0].type).toBe('verification');
        expect(rows[0].outcome).not.toMatch(/^refused/);
        const users = userRows(email);
        expect(users).toHaveLength(1);
        expect(users[0].email_verified).toBe('0');
        await expect(page.locator('.alert')).not.toContainText('anti-bot');
    });

    test('an unsolved challenge is refused and creates no account', async ({ page }) => {
        const { driver, opened } = await openRegister(page);
        test.skip(!driver, 'registration.challenge is not configured on this instance');
        const email = uniqueEmail(`${driver}-unsolved`);
        await submit(page, email, opened, '');

        await expect(page.locator('.alert-danger')).toContainText('anti-bot check could not be verified');
        const rows = guardRows(email);
        expect(rows).toHaveLength(1);
        expect(rows[0]).toMatchObject({ type: 'registration_attempt', outcome: 'refused_challenge' });
        expect(userRows(email)).toHaveLength(0);
    });

    // Altcha only: a solved proof of work is burned for its TTL within the
    // session, so the same payload can't sign up a second account. Turnstile
    // tokens are single-use on Cloudflare's side (not observable with the
    // always-pass test keys).
    test('a solved Altcha payload cannot be replayed', async ({ page }) => {
        const first = await openRegister(page);
        test.skip(first.driver !== 'altcha', 'needs the Altcha driver');
        const payload = await solve(page, 'altcha');
        const email = uniqueEmail('altcha-first');
        await submit(page, email, first.opened);
        expect(guardRows(email)[0]).toMatchObject({ type: 'verification' });

        const second = await openRegister(page);
        const replay = uniqueEmail('altcha-replay');
        await submit(page, replay, second.opened, payload);

        await expect(page.locator('.alert-danger')).toContainText('anti-bot check could not be verified');
        expect(guardRows(replay)[0]).toMatchObject({ type: 'registration_attempt', outcome: 'refused_challenge' });
        expect(userRows(replay)).toHaveLength(0);
    });
});
