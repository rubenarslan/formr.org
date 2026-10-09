// v1 (SpreadsheetRenderer) form helpers.
//
// v1 forms post via classic form submission and reload the page. The form
// selector is `form.main_formr_survey` (see Spreadsheet/SpreadsheetRenderer.php
// line 170: `form-horizontal main_formr_survey [+ ws-validate]`). Errors come
// back inline in `.fmr-error-messages` (template at line 142).

const { RUNNING_ON_BS, bsSafeEvaluate } = require('./test');

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
//
// On BS iOS Safari real device the Playwright-driven `locator.click()` is
// unreliable: the click event reaches the button but addEventListener
// listeners sometimes don't fire (the FakeMediaRecorder lifecycle trace
// confirmed this on the recorder spec). Worse, the actionability chain
// before the click pipelines several CDP calls that the BS-Selenium
// bridge can't always serialize back, surfacing as the cryptic
// `Error: Serialized error must have either an error or a value`. Use
// in-page `el.click()` via bsSafeEvaluate on BS — the bundle's submit
// handler still runs because click bubbles up through the DOM normally.
async function submitV1(page, { timeout = 20000 } = {}) {
    const click = RUNNING_ON_BS
        ? bsSafeEvaluate(page, () => {
              const btns = document.querySelectorAll('form.main_formr_survey button[type=submit], form.main_formr_survey input[type=submit]');
              if (btns.length) btns[btns.length - 1].click();
          })
        : page.locator(`${FORM_SELECTOR} button[type=submit], ${FORM_SELECTOR} input[type=submit]`).last().click();
    await Promise.all([
        page.waitForLoadState('domcontentloaded', { timeout }).catch(() => {}),
        click,
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
