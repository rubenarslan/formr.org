// Reproduces the "Validation Error. Message is required" bug when saving
// a PushMessage run unit. The textarea[name=message] is overlaid by an ACE
// editor; the editor session value is only synced back to the textarea
// AFTER client-side validation runs, so validation reads the empty
// textarea and errors out even though the user typed content into ACE.
//
// Pre-fix: this test fails — a `.run_units .alert-danger` containing
// "Message is required" appears, and the Save button stays at
// "Save changes".
//
// Post-fix: no validation error, the AJAX save round-trips, and the
// Save button settles on "Saved".

const path = require('node:path');
const dotenv = require('dotenv');
dotenv.config({ path: path.resolve(__dirname, '../../../.env.dev') });

const { test, expect } = require('./helpers/test');

const ADMIN_URL = process.env.FORMR_DEV_URL || 'https://formr.researchmixtape.com';
const LOGIN_URL = process.env.FORMR_DEV_LOGIN_URL || `${ADMIN_URL}/admin/account/login`;
const EMAIL = process.env.FORMR_DEV_ADMIN_EMAIL;
const PASSWORD = process.env.FORMR_DEV_ADMIN_PASSWORD;

async function loginAdmin(page) {
    await page.goto(LOGIN_URL, { waitUntil: 'domcontentloaded' });
    // vanilla-cookieconsent dialog blocks the form inputs on a fresh
    // context. Dismiss it if present.
    const accept = page.locator('[data-cc="accept-necessary"]').first();
    try {
        await accept.waitFor({ state: 'visible', timeout: 2000 });
        await accept.click();
        await accept.waitFor({ state: 'hidden', timeout: 2000 }).catch(() => {});
    } catch { /* dialog absent */ }
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
        page.locator('form').filter({ has: page.locator('input[name="run_name"]') }).locator('button[type="submit"], input[type="submit"]').first().click(),
    ]);
    await expect(page).toHaveURL(new RegExp(`/admin/run/${runName}/?$`));
}

async function deleteRun(page, runName) {
    // Best-effort cleanup. Don't fail the test if it errors.
    try {
        await page.goto(`${ADMIN_URL}/admin/run/${runName}/delete_run`, { waitUntil: 'domcontentloaded' });
        await page.fill('input[name="delete_confirm"]', runName);
        await Promise.all([
            page.waitForLoadState('domcontentloaded'),
            page.locator('button[name="delete"], input[name="delete"]').first().click(),
        ]);
    } catch (e) {
        // eslint-disable-next-line no-console
        console.warn(`[push-message-save] cleanup of ${runName} failed: ${e.message}`);
    }
}

test.describe('push message save validation', () => {
    test('typed message in ACE editor saves without "Message is required" error', async ({ page }) => {
        test.skip(!EMAIL || !PASSWORD, 'FORMR_DEV_ADMIN_EMAIL/PASSWORD missing from .env.dev');

        const runName = `e2e-pushmsg-${Date.now()}`;

        await loginAdmin(page);
        await createRun(page, runName);

        try {
            // Add Push Notification unit. The button is anchor.add_pushmessage
            // (see templates/admin/run/index.php). Clicking it POSTs to
            // ajax_create_run_unit?type=PushMessage and appends a .run_unit
            // block containing the form.
            const addBtn = page.locator('a.add_pushmessage');
            await expect(addBtn).toBeVisible();
            await addBtn.click();

            // Wait for the new PushMessage unit's ACE editor to mount.
            const aceEditor = page.locator('.run_units .run_unit .ace_editor').first();
            await aceEditor.waitFor({ state: 'visible', timeout: 15000 });

            // Type into the ACE editor via its JS instance so we don't have to
            // deal with focus, IME, or contenteditable quirks. setValue with
            // cursor flag -1 puts cursor at end and fires the change event the
            // RunUnit hooks listen for.
            const messageText = `e2e push message ${Date.now()}`;
            await page.evaluate((text) => {
                const el = document.querySelector('.run_units .run_unit .ace_editor');
                // eslint-disable-next-line no-undef
                const editor = window.ace.edit(el);
                editor.setValue(text, -1);
                editor.focus();
            }, messageText);

            // The change should flip the Save button from "Saved" (disabled)
            // to "Save changes" (enabled). Wait for that, then click.
            const saveBtn = page.locator('.run_units .run_unit a.unit_save').first();
            await expect(saveBtn).toHaveText(/Save changes/i, { timeout: 5000 });
            await expect(saveBtn).not.toHaveAttribute('disabled', /.*/, { timeout: 5000 });

            await saveBtn.click();

            // Bug assertion: the "Message is required" validation alert must
            // NOT appear. validatePushMessage injects this into .run_units via
            // bootstrap_alert(..., '.run_units').
            const errAlert = page.locator('.run_units .alert.alert-danger', {
                hasText: /Message is required/i,
            });
            await expect(errAlert).toHaveCount(0, { timeout: 3000 });

            // Save round-trip success: the AJAX done handler resets button text
            // to "Saved" and disables it again.
            await expect(saveBtn).toHaveText(/Saved/i, { timeout: 15000 });
            await expect(saveBtn).toHaveAttribute('disabled', /.*/);
        } finally {
            await deleteRun(page, runName);
        }
    });
});
