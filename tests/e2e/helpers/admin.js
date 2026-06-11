// Admin-UI helpers for e2e specs that drive the run editor / settings.
//
// Credentials come from ../.env.dev via playwright.config.js's dotenv load
// (FORMR_DEV_ADMIN_EMAIL / FORMR_DEV_ADMIN_PASSWORD). Login state is cached
// to setup/admin-login-state.json (gitignored via setup/*-state.json) so a
// spec can `test.use({ storageState: STATE_PATH })` after calling
// ensureAdminState(browser) in beforeAll.

const path = require('node:path');
const fs = require('node:fs');

const ADMIN_BASE = (process.env.FORMR_DEV_URL || 'https://formr.researchmixtape.com').replace(/\/+$/, '');
const STATE_PATH = path.resolve(__dirname, '../setup/admin-login-state.json');

// `test.use({ storageState: STATE_PATH })` applies to EVERY newContext()
// in the worker — including the one ensureAdminState() uses to log in.
// Seed an empty state so that first context can be created before the
// real cookie jar exists.
if (!fs.existsSync(STATE_PATH)) {
    fs.mkdirSync(path.dirname(STATE_PATH), { recursive: true });
    fs.writeFileSync(STATE_PATH, JSON.stringify({ cookies: [], origins: [] }));
}

// The "Recognize this device again?" consent dialog obstructs inputs until
// answered (see CLAUDE.md). Accept it when present, on admin or study pages.
async function dismissConsent(page) {
    const consent = page.locator('button, a').filter({ hasText: /^(Accept|Accept functional cookies|OK|Agree)/i }).first();
    try { await consent.click({ timeout: 2000 }); } catch (e) { /* not shown */ }
}

async function adminLogin(page) {
    const email = process.env.FORMR_DEV_ADMIN_EMAIL;
    const password = process.env.FORMR_DEV_ADMIN_PASSWORD;
    if (!email || !password) {
        throw new Error('FORMR_DEV_ADMIN_EMAIL / FORMR_DEV_ADMIN_PASSWORD not set — is ../.env.dev in place?');
    }
    await page.goto(ADMIN_BASE + '/admin/account/login');
    await dismissConsent(page);
    // a still-valid session in the cached storage state bounces the login
    // page straight to /admin — no form to fill, nothing to do
    if (await page.locator('input[name=email]').count() === 0) {
        await page.waitForSelector('a[href*="logout"]', { timeout: 10000 });
        return;
    }
    await page.fill('input[name=email]', email);
    await page.fill('input[name=password]', password);
    await page.click('button[type=submit], input[type=submit]');
    await page.waitForSelector('a[href*="logout"]', { timeout: 15000 });
}

// Log in once in beforeAll and persist the cookie jar, so per-test contexts
// (created by the default `page` fixture) start authenticated via
// `test.use({ storageState: STATE_PATH })`.
async function ensureAdminState(browser) {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await adminLogin(page);
    fs.mkdirSync(path.dirname(STATE_PATH), { recursive: true });
    await ctx.storageState({ path: STATE_PATH });
    await ctx.close();
    return STATE_PATH;
}

async function createRun(page, name) {
    await page.goto(ADMIN_BASE + '/admin/run/add_run');
    await page.fill('input[name=run_name], input[name=name]', name);
    await page.click('button[type=submit], input[type=submit]');
    await page.waitForURL('**/admin/run/' + name + '/**', { timeout: 15000 });
}

async function deleteRun(page, name) {
    await page.goto(ADMIN_BASE + '/admin/run/' + name + '/delete_run/');
    // confirmation form: the run name must be typed back
    await page.fill('input[type=text]', name);
    await page.click('button[type=submit], input[type=submit]');
    await page.waitForLoadState('networkidle');
}

// Set the value of an Ace-backed textarea. The visible editor replaces the
// (hidden) textarea, so filling the textarea directly does nothing — write
// through the Ace session, which also fires the editor's change event so
// save buttons enable and the form sync on submit picks the value up.
async function setAceValue(page, textareaSelector, value) {
    await page.evaluate(({ sel, val }) => {
        const ta = document.querySelector(sel);
        if (!ta) throw new Error('textarea not found: ' + sel);
        const editorDiv = ta.previousElementSibling;
        if (editorDiv && window.ace && editorDiv.className.indexOf('ace_editor') !== -1) {
            window.ace.edit(editorDiv).getSession().setValue(val);
        } else {
            ta.value = val;
            ta.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }, { sel: textareaSelector, val: value });
}

module.exports = { ADMIN_BASE, STATE_PATH, adminLogin, ensureAdminState, createRun, deleteRun, setAceValue, dismissConsent };
