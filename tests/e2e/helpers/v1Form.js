// v1 (SpreadsheetRenderer) form helpers.
//
// v1 forms post via classic form submission and reload the page. The form
// selector is `form.main_formr_survey` (see Spreadsheet/SpreadsheetRenderer.php
// line 170: `form-horizontal main_formr_survey [+ ws-validate]`). Errors come
// back inline in `.fmr-error-messages` (template at line 142).

const FORM_SELECTOR = 'form.main_formr_survey';

function form(page) {
    return page.locator(FORM_SELECTOR).first();
}

async function isPresent(page) {
    return (await page.locator(FORM_SELECTOR).count()) > 0;
}

// Submit the visible v1 form. v1 reloads the whole page on success and
// re-renders inline on validation errors. `domcontentloaded` is the right
// settle: it fires on both reload and re-render. `networkidle` is unsafe
// because OpenCPU showif pings keep the network busy indefinitely.
async function submitV1(page, { timeout = 20000 } = {}) {
    const submit = page.locator(`${FORM_SELECTOR} button[type=submit], ${FORM_SELECTOR} input[type=submit]`).last();
    await Promise.all([
        page.waitForLoadState('domcontentloaded', { timeout }).catch(() => {}),
        submit.click(),
    ]);
    // Brief settle so any client-side error rendering has time to land.
    await page.waitForTimeout(400);
}

async function errorMessages(page) {
    const banner = page.locator('.fmr-error-messages').first();
    if (!(await banner.count())) return [];
    const text = (await banner.innerText()).trim();
    if (!text) return [];
    return text.split(/\n+/).map((s) => s.trim()).filter(Boolean);
}

async function progressPercent(page) {
    const bar = page.locator('.progress .progress-bar').first();
    if (!(await bar.count())) return null;
    const style = (await bar.getAttribute('style')) || '';
    const m = style.match(/width:\s*([\d.]+)%/);
    return m ? Number(m[1]) : null;
}

module.exports = { FORM_SELECTOR, form, isPresent, submitV1, errorMessages, progressPercent };
