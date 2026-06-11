// Regression e2e for run-level custom R functions, run secrets, and the
// OpenCPU debugger R Fiddle links (PR #693).
//
// The redaction assertions exist because of a real leak found in manual
// browser testing: the debugger shows the generated R source, where the
// secret appears in its R-escaped form (' -> \'), which a literal match
// on the raw value misses. Every leak probe below therefore checks for
// the distinctive suffix 'key<>123', which survives both HTML escaping
// and R escaping of the full value — if that suffix shows up anywhere,
// some variant of the secret got through.

const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { ADMIN_BASE, STATE_PATH, ensureAdminState, createRun, deleteRun, setAceValue, dismissConsent } = require('./helpers/admin');

test.skip(RUNNING_ON_BS, 'admin-UI spec; nothing device-specific — local chromium only');

const RUN = 'e2e-secrets-' + Date.now().toString(36);
const SETTINGS_URL = () => `${ADMIN_BASE}/admin/run/${RUN}/settings`;

// 15 chars; hostile to HTML escaping, R escaping, and the old innerHTML path
const SECRET_VALUE = 'it\'s&a"key<>123';
const LEAK_MARKER = 'key<>123';
const LEAK_MARKER_HTML = 'key&lt;&gt;123'; // HTML-escaped form of the marker

const CUSTOM_R = 'my_score <- function(x) {\n  # docs: https://example.org/scoring\n  mean(x, na.rm = TRUE) / 2\n}';
const PAGE_BODY = '# E2E test\n\nScore: `r my_score(c(1, 2, 3))`\n\nSecret length: `r nchar(.formr$secret_api_key)`\n';

test.describe.serial('run secrets & custom R functions', () => {
    test.use({ storageState: STATE_PATH });

    test.beforeAll(async ({ browser }) => {
        await ensureAdminState(browser);
        const ctx = await browser.newContext({ storageState: STATE_PATH });
        const page = await ctx.newPage();
        await createRun(page, RUN);
        await ctx.close();
    });

    test.afterAll(async ({ browser }) => {
        const ctx = await browser.newContext({ storageState: STATE_PATH });
        const page = await ctx.newPage();
        await deleteRun(page, RUN).catch(() => {});
        await ctx.close();
    });

    async function openTab(page, tab) {
        await page.goto(SETTINGS_URL());
        await page.click(`a[href="#${tab}"]`);
        await page.waitForTimeout(300); // bootstrap tab transition
    }

    test('invalid custom R shows the parse error and an LLM export button', async ({ page }) => {
        await openTab(page, 'r-functions');
        await setAceValue(page, '#r-functions textarea[name="custom_r"]', 'broken <- function(x) {\n  mean(x,,\n');
        await page.click('#r-functions .btn-save-test-r-code');
        const result = page.locator('#r-code-parse-result');
        await expect(result).toContainText(/unexpected/i, { timeout: 30000 });
        await expect(result.locator('button', { hasText: 'Copy for LLM' })).toBeVisible();
    });

    test('custom R with division and a URL in a comment validates and saves', async ({ page }) => {
        // PHP's default json_encode escaped / as \/ — invalid in R string
        // literals — so any '/' broke every evaluation before the fix.
        await openTab(page, 'r-functions');
        await setAceValue(page, '#r-functions textarea[name="custom_r"]', CUSTOM_R);
        await page.click('#r-functions .btn-save-test-r-code');
        await expect(page.locator('#r-code-parse-result')).toContainText('R syntax is valid', { timeout: 30000 });
        await expect(page.locator('#r-functions .alert').first()).toContainText('Settings saved');
    });

    test('secret names outside [A-Za-z0-9_] are rejected client-side', async ({ page }) => {
        await openTab(page, 'secrets');
        let dialogMessage = null;
        page.once('dialog', async (d) => { dialogMessage = d.message(); await d.accept(); });
        await page.fill('#new-secret-name', 'bad name!');
        await page.fill('#new-secret-value', 'whatever-value');
        await page.click('#add-secret-btn');
        await expect.poll(() => dialogMessage).toMatch(/letters, digits and underscores/);
        await expect(page.locator('#secrets-tbody tr')).toHaveCount(0);
    });

    test('secrets are write-only: stored value never returns to the browser', async ({ page }) => {
        await openTab(page, 'secrets');
        await page.fill('#new-secret-name', 'api_key');
        await page.fill('#new-secret-value', SECRET_VALUE);
        const saved = page.waitForResponse((r) => r.url().includes('ajax_save_settings') && r.ok());
        await page.click('#add-secret-btn');
        await saved;
        await expect(page.locator('#secrets-tbody code')).toHaveText('secret_api_key');

        await page.reload();
        await page.click('a[href="#secrets"]');
        const valueInput = page.locator('#secrets-tbody .secret-value').first();
        await expect(valueInput).toHaveValue('');
        await expect(valueInput).toHaveAttribute('type', 'password');
        await expect(valueInput).toHaveAttribute('placeholder', /unchanged/);

        const html = await page.content();
        expect(html).not.toContain(LEAK_MARKER);
        expect(html).not.toContain(LEAK_MARKER_HTML);
    });

    test('blurring an untouched secret field does not overwrite or save', async ({ page }) => {
        await openTab(page, 'secrets');
        let saveFired = false;
        page.on('request', (r) => { if (r.url().includes('ajax_save_settings')) saveFired = true; });
        const valueInput = page.locator('#secrets-tbody .secret-value').first();
        await valueInput.focus();
        await valueInput.blur();
        await page.waitForTimeout(1500);
        expect(saveFired).toBe(false);
    });

    test('custom function and secret are injected into a participant render', async ({ page }) => {
        test.setTimeout(180000); // two OpenCPU knits, first one cold

        // a Page unit whose body calls the custom function and references
        // the secret — only the literal .formr$secret_<name> reference
        // triggers injection, so this also covers the gating
        await page.goto(`${ADMIN_BASE}/admin/run/${RUN}/`);
        await page.evaluate(() => document.querySelector('.add_page').click());
        // the textarea is display:none behind its Ace editor — wait for
        // attachment, not visibility
        await page.waitForSelector('.run_units textarea[name=body]', { state: 'attached', timeout: 15000 });
        await setAceValue(page, '.run_units textarea[name=body]', PAGE_BODY);
        const unitSaved = page.waitForResponse((r) => r.url().includes('ajax_save_run_unit') && r.ok());
        await page.click('.run_units .unit_save');
        await unitSaved;

        // run it as a test participant
        await page.goto(`${ADMIN_BASE}/admin/run/${RUN}/create_new_test_code/`);
        await dismissConsent(page);
        const frame = page.frameLocator('.rmarkdown_iframe iframe');
        // mean(c(1,2,3)) / 2 = 1 proves the custom function (with its '/'
        // and URL comment) ran; nchar = 15 proves the secret arrived
        // byte-exact — the old innerHTML path stripped &"'<> and would
        // have produced 10
        await expect(frame.locator('body')).toContainText('Score: 1', { timeout: 90000 });
        await expect(frame.locator('body')).toContainText('Secret length: 15');

        const html = await page.content();
        expect(html).not.toContain(LEAK_MARKER);
        expect(html).not.toContain(LEAK_MARKER_HTML);
    });

    test('debugger and R Fiddle link redact the secret — including its escaped form', async ({ page }) => {
        test.setTimeout(180000);

        // the admin Test button forces a fresh knit, so the debugger shows
        // the full generated R Markdown incl. the secret assignment line
        await page.goto(`${ADMIN_BASE}/admin/run/${RUN}/`);
        await page.evaluate(() => document.querySelector('.run_units .unit_test').click());
        const modal = page.locator('.modal', { hasText: 'Test Results' });
        const debugSource = modal.locator('textarea').first();
        await expect(debugSource).toBeVisible({ timeout: 90000 });

        // the R source contains the injected assignment — redacted, with
        // neither the raw nor the R-escaped (\' ) form surviving
        const sourceText = await debugSource.inputValue();
        expect(sourceText).toContain('[SECRET REDACTED]');
        expect(sourceText).not.toContain(LEAK_MARKER);
        expect(sourceText).toContain('my_score'); // custom R is shown un-redacted

        // the R Fiddle link carries the same source base64url-encoded in
        // the fragment — decode and sweep it too (this is what leaked)
        const fiddleHref = await modal.locator('a[href*="#code="]').first().getAttribute('href');
        expect(fiddleHref).toMatch(/\?lang=rmd#code=/);
        const b64 = fiddleHref.split('#code=')[1].replace(/-/g, '+').replace(/_/g, '/');
        const decoded = Buffer.from(b64, 'base64').toString('utf8');
        expect(decoded).toContain('[SECRET REDACTED]');
        expect(decoded).toContain('my_score');
        expect(decoded).not.toContain(LEAK_MARKER);

        // whole-modal sweep: no panel (console, stdout, request, …) may
        // carry any variant of the value
        const modalHtml = await modal.evaluate((m) => m.innerHTML);
        expect(modalHtml).not.toContain(LEAK_MARKER);
        expect(modalHtml).not.toContain(LEAK_MARKER_HTML);
    });

    test('deleting a secret removes it permanently', async ({ page }) => {
        await openTab(page, 'secrets');
        const saved = page.waitForResponse((r) => r.url().includes('ajax_save_settings') && r.ok());
        await page.click('#secrets-tbody .delete-secret');
        await saved;
        await expect(page.locator('#secrets-tbody tr')).toHaveCount(0);
        await page.reload();
        await page.click('a[href="#secrets"]');
        await expect(page.locator('#secrets-tbody tr')).toHaveCount(0);
    });
});
