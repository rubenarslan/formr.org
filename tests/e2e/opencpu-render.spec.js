// E2E: Page-with-inline-R rendering through the OpenCPU pipeline.
//
// Pins two regressions from commit abb26652 that fixed in df59548d +
// 04b654a2:
//
//   1. opencpu_knit_iframe was missing the closing ``` for its settings
//      chunk, so the user-facing markdown (description / body /
//      footer_text) leaked into the open R code block. knitr fell over
//      with "Error in parse(text = input)" and the iframe showed the
//      R source instead of the rendered page.
//
//   2. opencpu_get's caller in RunUnit::getParsedBody:491 passed four
//      args to a function whose signature had been narrowed to three.
//      The dropped null landed on $return_session (falsy), the function
//      returned a JSON-object string instead of an OpenCPU_Session, and
//      the next line ($ocpu->hasError()) fataled with "Call to a member
//      function hasError() on string" on every cache-hit Page render.
//
// Coverage: a single Page (Endpage) unit with inline R + a fenced R
// chunk. Visit once for the cache-miss path (opencpu_knit_iframe),
// then revisit for the cache-hit path (opencpu_get + the same Page
// rendering through the cached survey_reports.opencpu_url branch).

const path = require('node:path');
const dotenv = require('dotenv');
dotenv.config({ path: path.resolve(__dirname, '../../../.env.dev') });

const { test, expect } = require('./helpers/test');

const ADMIN_URL = process.env.FORMR_DEV_URL || 'https://formr.researchmixtape.com';
const LOGIN_URL = process.env.FORMR_DEV_LOGIN_URL || `${ADMIN_URL}/admin/account/login`;
const EMAIL = process.env.FORMR_DEV_ADMIN_EMAIL;
const PASSWORD = process.env.FORMR_DEV_ADMIN_PASSWORD;

// Inline R AND a fenced R chunk so a missing-fence regression leaks
// "library(knitr)" / "opts_chunk" into the visible output, and so the
// inline `r 1 + 1` renders to the literal "2".
const PAGE_BODY = [
    '## Hello world',
    '',
    'Inline: the answer is `r 1 + 1`.',
    '',
    '```{r}',
    'cat("chunk-output-marker-XYZ\\n")',
    '```',
].join('\n');

async function loginAdmin(page) {
    await page.goto(LOGIN_URL, { waitUntil: 'domcontentloaded' });
    // vanilla-cookieconsent blocks form inputs on a fresh context.
    const accept = page.locator('[data-cc="accept-necessary"]').first();
    try {
        await accept.waitFor({ state: 'visible', timeout: 2000 });
        await accept.click();
        await accept.waitFor({ state: 'hidden', timeout: 2000 }).catch(() => {});
    } catch { /* no dialog */ }
    await page.fill('input[name="email"]', EMAIL);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        page.click('button[type="submit"], input[type="submit"]'),
    ]);
    await expect(page).toHaveURL(/\/admin\//);
}

async function createRun(page, runName) {
    await page.goto(`${ADMIN_URL}/admin/run/add_run`, { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="run_name"]', runName);
    await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        page.locator('form').filter({ has: page.locator('input[name="run_name"]') })
            .locator('button[type="submit"], input[type="submit"]').first().click(),
    ]);
    await expect(page).toHaveURL(new RegExp(`/admin/run/${runName}/?$`));
}

async function deleteRun(page, runName) {
    // Best-effort cleanup. Run delete cascades survey_run_units +
    // survey_run_sessions in the admin handler; nothing else relies on
    // this succeeding for a green test.
    try {
        await page.goto(`${ADMIN_URL}/admin/run/${runName}/delete_run`, { waitUntil: 'domcontentloaded' });
        await page.fill('input[name="delete_confirm"]', runName);
        await Promise.all([
            page.waitForLoadState('domcontentloaded'),
            page.locator('button[name="delete"], input[name="delete"]').first().click(),
        ]);
    } catch (e) {
        // eslint-disable-next-line no-console
        console.warn(`[opencpu-render] cleanup of ${runName} failed: ${e.message}`);
    }
}

async function addPageUnitWithBody(page, runName, body) {
    // We're on /admin/run/{runName} after createRun.
    const addBtn = page.locator('a.add_page');
    await expect(addBtn).toBeVisible();
    await addBtn.click();

    // ajax_create_run_unit injects a new .run_unit block with its ACE
    // editor mounted over a hidden textarea[name="body"].
    const aceEditor = page.locator('.run_units .run_unit .ace_editor').first();
    await aceEditor.waitFor({ state: 'visible', timeout: 15000 });

    await page.evaluate((text) => {
        const el = document.querySelector('.run_units .run_unit .ace_editor');
        // eslint-disable-next-line no-undef
        const editor = window.ace.edit(el);
        editor.setValue(text, -1);
        editor.focus();
    }, body);

    const saveBtn = page.locator('.run_units .run_unit a.unit_save').first();
    await expect(saveBtn).toHaveText(/Save changes/i, { timeout: 5000 });
    await saveBtn.click();
    await expect(saveBtn).toHaveText(/Saved/i, { timeout: 15000 });
}

async function startTestSession(page, runName) {
    // create_new_test_code mints a session row and redirects to the
    // participant URL (run_url with ?code=…).
    await page.goto(`${ADMIN_URL}/admin/run/${runName}/create_new_test_code`,
        { waitUntil: 'domcontentloaded' });
    return page.url();
}

test.describe('OpenCPU Page rendering', () => {
    test.skip(!EMAIL || !PASSWORD, 'FORMR_DEV_ADMIN_EMAIL/PASSWORD missing from .env.dev');

    test('inline R is evaluated and cache-hit replay does not fatal', async ({ page }) => {
        const runName = `e2e-ocpu-${Date.now()}`;
        await loginAdmin(page);
        await createRun(page, runName);

        try {
            await addPageUnitWithBody(page, runName, PAGE_BODY);
            const participantUrl = await startTestSession(page, runName);

            // First visit — cache-miss path (opencpu_knit_iframe).
            const iframe = page.frameLocator('.rmarkdown_iframe iframe');

            // The user-facing markdown must have been processed as markdown,
            // not as the body of the open settings chunk: "Hello world" lands
            // in an <h2>, inline `r 1 + 1` resolves to "2", and the {r}
            // chunk's cat() emits its marker into the chunk output.
            await expect(iframe.locator('h2')).toContainText('Hello world', { timeout: 30000 });
            await expect(iframe.locator('body')).toContainText('the answer is 2');
            await expect(iframe.locator('body')).toContainText('chunk-output-marker-XYZ');

            // Bug shapes that this asserts AGAINST:
            //   - Missing settings-chunk fence (abb26652) → R rejects the
            //     markdown with "Error in parse(text = input)" /
            //     "unexpected symbol" and the iframe shows that error
            //     instead of "the answer is 2".
            //   - The literal "`r 1 + 1`" surviving into rendered output
            //     would also mean knitr never evaluated the inline R.
            await expect(iframe.locator('body')).not.toContainText('Error in parse');
            await expect(iframe.locator('body')).not.toContainText('unexpected symbol');
            await expect(iframe.locator('body')).not.toContainText('`r 1 + 1`');

            // Second visit — cache-hit path. survey_reports.opencpu_url was
            // written above, so this render goes through
            // RunUnit::getParsedBody:491's opencpu_get($opencpu_url, '', true)
            // branch. Regression-guards the abb26652 caller-arity bug that
            // fataled with "Call to a member function hasError() on string"
            // on every cache-hit Page render.
            await page.goto(participantUrl, { waitUntil: 'domcontentloaded' });
            await expect(iframe.locator('h2')).toContainText('Hello world', { timeout: 30000 });
            await expect(iframe.locator('body')).toContainText('the answer is 2');
            await expect(iframe.locator('body')).not.toContainText('Error in parse');
        } finally {
            await deleteRun(page, runName);
        }
    });
});
