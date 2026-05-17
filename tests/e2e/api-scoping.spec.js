// End-to-end test for the API-scoping feature.
//
// Drives the admin UI at /admin/account#api to create OAuth credentials
// with a specific scope+run-allowlist selection, mints an access token
// via the public OAuth endpoint with those credentials, then exercises
// /v1/* to confirm:
//   - read/write scope strings gate verbs (run:read alone -> PATCH 403)
//   - the run-allowlist gates per-run access
//   - the run-allowlist also gates surveys reachable through that run
//   - the /v1/runs index is filtered to allowlisted runs only
//
// Credentials and URLs come from /home/admin/formr-docker/.env.dev
// (loaded by playwright.config.js):
//   FORMR_DEV_URL              -> admin host (e.g. https://formr.researchmixtape.com)
//   FORMR_DEV_LOGIN_URL        -> admin login URL
//   FORMR_DEV_ADMIN_EMAIL/PWD  -> dev admin who has canAccessApi()
//   FORMR_DEV_2FA_SECRET       -> optional TOTP base32 secret. If unset
//                                 and 2FA is required on the user, the
//                                 suite skips with a clear message.
//
// Fixture assumption: scoping fixtures (scope-run-a, scope-run-b,
// scope_survey_a/b/orphan) are seeded by tests/APIV1_bruno_tests/
// run_security.sh — run it once before this spec or pre-seed them
// some other way. If the fixtures are missing the spec skips rather
// than fails.

const { test, expect, request: pwRequest } = require('@playwright/test');

const ADMIN_URL = process.env.FORMR_DEV_URL;
const LOGIN_URL = process.env.FORMR_DEV_LOGIN_URL || (ADMIN_URL && `${ADMIN_URL}/admin/account/login`);
const ADMIN_EMAIL = process.env.FORMR_DEV_ADMIN_EMAIL;
const ADMIN_PWD = process.env.FORMR_DEV_ADMIN_PASSWORD;
const TOTP_SECRET = process.env.FORMR_DEV_2FA_SECRET;
// API host: bru tests resolve this from DEV_API_HOST; same default here.
const API_HOST = process.env.DEV_API_HOST || (ADMIN_URL && `${ADMIN_URL}/api`);

const RUN_A = process.env.SCOPING_RUN_A || 'scope-run-a';
const RUN_B = process.env.SCOPING_RUN_B || 'scope-run-b';
const SURVEY_IN_A = process.env.SCOPING_SURVEY_A || 'scope_survey_a';
const SURVEY_IN_B = process.env.SCOPING_SURVEY_B || 'scope_survey_b';
const SURVEY_ORPHAN = process.env.SCOPING_SURVEY_ORPHAN || 'scope_orphan';

const ENV_OK = !!(ADMIN_URL && LOGIN_URL && ADMIN_EMAIL && ADMIN_PWD && API_HOST);

test.describe('API scoping', () => {
    test.skip(!ENV_OK, 'FORMR_DEV_URL / FORMR_DEV_ADMIN_EMAIL / FORMR_DEV_ADMIN_PASSWORD not set in .env.dev; skipping');

    let runAId, runBId;

    test.beforeAll(async ({ browser }) => {
        const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
        const page = await ctx.newPage();
        await loginAsAdmin(page);

        // Discover run ids — the scope form uses run ids as option values, not names.
        await page.goto(`${ADMIN_URL}/admin/account/#api`);
        const select = page.locator('select[name="api_run_ids[]"]');
        await expect(select).toBeVisible();
        runAId = await select.locator(`option`).filter({ hasText: new RegExp(`^\\s*${escapeRegex(RUN_A)}\\s*$`) }).first().getAttribute('value');
        runBId = await select.locator(`option`).filter({ hasText: new RegExp(`^\\s*${escapeRegex(RUN_B)}\\s*$`) }).first().getAttribute('value');

        await ctx.storageState({ path: 'tests/e2e/setup/api-scoping-state.json' });
        await ctx.close();

        test.skip(!runAId || !runBId,
            `scoping fixtures ${RUN_A} and ${RUN_B} not found; run tests/APIV1_bruno_tests/run_security.sh once to seed them`);
    });

    // --- helpers ---------------------------------------------------------

    async function loginAsAdmin(page) {
        await page.goto(LOGIN_URL);
        // Already-logged-in carry-over: navigating to /login may redirect to
        // /admin/account if a session cookie survived from storageState.
        if (!page.url().includes('login')) {
            return;
        }
        await page.fill('input[name="email"]', ADMIN_EMAIL);
        await page.fill('input[name="password"]', ADMIN_PWD);
        await Promise.all([
            page.waitForLoadState('networkidle'),
            page.click('button[type="submit"]'),
        ]);

        // 2FA handling: if the user lands on /two-factor, we need a TOTP.
        if (page.url().includes('/two-factor')) {
            test.skip(!TOTP_SECRET,
                '2FA is required for the dev admin but FORMR_DEV_2FA_SECRET is not set in .env.dev; cannot proceed');
            const code = computeTOTP(TOTP_SECRET);
            const input = page.locator('input[name="code"], input[name="totp"], input[type="text"]').first();
            await input.fill(code);
            await Promise.all([
                page.waitForLoadState('networkidle'),
                page.click('button[type="submit"]'),
            ]);
        }
        expect(page.url()).toContain('/admin');
    }

    async function issueCredentials(browser, scopes, runIds) {
        const ctx = await browser.newContext({
            storageState: 'tests/e2e/setup/api-scoping-state.json',
            ignoreHTTPSErrors: true,
        });
        const page = await ctx.newPage();
        await page.goto(`${ADMIN_URL}/admin/account/#api`);

        // Listen for the alert that fires when we ask "no scopes? continue?"
        // and dismiss any unrelated confirm. Always click "yes" because we
        // explicitly want to proceed.
        page.on('dialog', d => d.accept());

        // Uncheck every scope first, then check the ones we want.
        const allScopes = await page.locator('input[name="api_scope[]"]').all();
        for (const cb of allScopes) {
            if (await cb.isChecked()) await cb.uncheck();
        }
        for (const s of scopes) {
            await page.check(`input[name="api_scope[]"][value="${s}"]`);
        }

        // Reset and apply run allowlist.
        const select = page.locator('select[name="api_run_ids[]"]');
        if (runIds.length > 0) {
            await select.selectOption(runIds.map(String));
        } else {
            await select.selectOption([]);
        }

        // Either rotate (if a client already exists) or create.
        const rotateBtn = page.locator('#api-rotate-btn');
        const createBtn = page.locator('#api-create-btn');
        const responsePromise = page.waitForResponse(
            r => r.url().includes('/admin/account/api-credentials') && r.request().method() === 'POST'
        );
        if (await rotateBtn.isVisible()) {
            await rotateBtn.click();
        } else {
            await createBtn.click();
        }
        const response = await responsePromise;
        const json = await response.json();
        expect(json.success, JSON.stringify(json)).toBe(true);

        await ctx.close();
        return { clientId: json.data.client_id, clientSecret: json.data.client_secret };
    }

    async function mintToken({ clientId, clientSecret }) {
        const apiCtx = await pwRequest.newContext({ ignoreHTTPSErrors: true });
        const res = await apiCtx.post(`${API_HOST}/oauth/access_token`, {
            form: {
                grant_type: 'client_credentials',
                client_id: clientId,
                client_secret: clientSecret,
            },
        });
        expect(res.status(), `oauth token mint should succeed for ${clientId}`).toBe(200);
        const body = await res.json();
        return { token: body.access_token, scope: body.scope, apiCtx };
    }

    function bearer(token) {
        return pwRequest.newContext({
            ignoreHTTPSErrors: true,
            extraHTTPHeaders: { Authorization: `Bearer ${token}` },
        });
    }

    // --- tests -----------------------------------------------------------

    test('Read-only token allows GET, denies PATCH on allowlisted run', async ({ browser }) => {
        const creds = await issueCredentials(browser, ['run:read'], [runAId]);
        const { token, scope } = await mintToken(creds);
        expect(scope).toBe('run:read');

        const api = await bearer(token);
        const getRes = await api.get(`${API_HOST}/v1/runs/${RUN_A}`);
        expect(getRes.status()).toBe(200);

        const patchRes = await api.patch(`${API_HOST}/v1/runs/${RUN_A}`, {
            data: { title: 'scoping-e2e-should-not-land' },
            headers: { 'Content-Type': 'application/json' },
        });
        expect(patchRes.status()).toBe(403);
        const patchBody = await patchRes.json();
        expect(patchBody.message).toMatch(/run:write/);
    });

    test('Run-allowlisted token cannot reach other runs or their surveys', async ({ browser }) => {
        const creds = await issueCredentials(browser, ['run:read', 'run:write', 'survey:read'], [runAId]);
        const { token } = await mintToken(creds);
        const api = await bearer(token);

        expect((await api.get(`${API_HOST}/v1/runs/${RUN_A}`)).status()).toBe(200);
        const otherRun = await api.get(`${API_HOST}/v1/runs/${RUN_B}`);
        expect(otherRun.status()).toBe(403);
        const otherRunBody = await otherRun.json();
        expect(otherRunBody.message).toMatch(/not authorized for run/);

        // Survey gate inherited from run allowlist.
        const surveyInA = await api.get(`${API_HOST}/v1/surveys/${SURVEY_IN_A}`);
        expect(surveyInA.status()).toBe(200);
        const surveyInB = await api.get(`${API_HOST}/v1/surveys/${SURVEY_IN_B}`);
        expect(surveyInB.status()).toBe(403);
        const orphan = await api.get(`${API_HOST}/v1/surveys/${SURVEY_ORPHAN}`);
        expect(orphan.status()).toBe(403);

        // List endpoints filtered. respond() emits the data array bare,
        // not wrapped in an envelope — see ApiController::respond.
        const runs = await (await api.get(`${API_HOST}/v1/runs`)).json();
        const runNames = runs.map(r => r.name);
        expect(runNames).toContain(RUN_A);
        expect(runNames).not.toContain(RUN_B);

        const surveys = await (await api.get(`${API_HOST}/v1/surveys`)).json();
        const surveyNames = surveys.map(s => s.name);
        expect(surveyNames).toContain(SURVEY_IN_A);
        expect(surveyNames).not.toContain(SURVEY_IN_B);
        expect(surveyNames).not.toContain(SURVEY_ORPHAN);
    });

    test('Unrestricted token (empty allowlist) sees everything user owns', async ({ browser }) => {
        const creds = await issueCredentials(browser, ['run:read', 'survey:read'], []);
        const { token } = await mintToken(creds);
        const api = await bearer(token);

        expect((await api.get(`${API_HOST}/v1/runs/${RUN_B}`)).status()).toBe(200);
        expect((await api.get(`${API_HOST}/v1/surveys/${SURVEY_ORPHAN}`)).status()).toBe(200);
    });

    test('Rotating invalidates the old secret', async ({ browser }) => {
        const first = await issueCredentials(browser, ['run:read'], [runAId]);
        const second = await issueCredentials(browser, ['run:read'], [runAId]);
        expect(second.clientId).toBe(first.clientId);
        expect(second.clientSecret).not.toBe(first.clientSecret);

        // Old secret cannot mint new tokens (hash mismatch).
        const apiCtx = await pwRequest.newContext({ ignoreHTTPSErrors: true });
        const res = await apiCtx.post(`${API_HOST}/oauth/access_token`, {
            form: {
                grant_type: 'client_credentials',
                client_id: first.clientId,
                client_secret: first.clientSecret,
            },
        });
        expect(res.status()).toBeGreaterThanOrEqual(400);
    });
});

// Tiny RFC 6238 TOTP. Avoid adding `otplib` as a dependency just for
// one optional skip path. base32 decoder + HMAC-SHA1 are short enough
// to inline.
function computeTOTP(base32Secret, step = 30) {
    const key = base32Decode(base32Secret.replace(/\s+/g, '').toUpperCase());
    const counter = Math.floor(Date.now() / 1000 / step);
    const buf = Buffer.alloc(8);
    buf.writeBigInt64BE(BigInt(counter));
    const crypto = require('crypto');
    const hmac = crypto.createHmac('sha1', key).update(buf).digest();
    const offset = hmac[hmac.length - 1] & 0xf;
    const code = (((hmac[offset] & 0x7f) << 24) |
                  ((hmac[offset + 1] & 0xff) << 16) |
                  ((hmac[offset + 2] & 0xff) << 8) |
                   (hmac[offset + 3] & 0xff)) % 1000000;
    return String(code).padStart(6, '0');
}

function base32Decode(s) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of s) {
        const i = alphabet.indexOf(c);
        if (i < 0) continue;
        bits += i.toString(2).padStart(5, '0');
    }
    const out = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) {
        out.push(parseInt(bits.slice(i, i + 8), 2));
    }
    return Buffer.from(out);
}

function escapeRegex(s) {
    return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
