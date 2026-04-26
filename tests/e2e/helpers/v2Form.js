// v2 (FormRenderer + form.bundle.js) form helpers.
//
// v2 forms submit via fetch to /{run}/form-page-submit (or /form-sync from
// the offline queue). Server returns:
//   - {status: "ok", next_page: N}            multi-page, more pages remain
//   - {status: "ok", redirect: "/{run}/"}     last page; client navigates
//   - {status: "errors", errors: {name: msg}} validation failed; stay on page
//
// Page sections are <section class="fmr-page" data-fmr-page="N" [hidden]>.
// The visible one has no `hidden` attribute. Inline errors land as
// `.is-invalid` + `.fmr-invalid-feedback`; unplaced errors render in
// `.fmr-error-banner`. Initial server-side render of validation errors uses
// `.fmr-error-messages` (alert at the form top) — same class as v1.

const FORM_SELECTOR = 'form.fmr-form-v2';

function form(page) {
    return page.locator(FORM_SELECTOR).first();
}

async function isPresent(page) {
    return (await page.locator(FORM_SELECTOR).count()) > 0;
}

async function visiblePageNum(page) {
    return page.evaluate(() => {
        const sec = document.querySelector('form.fmr-form-v2 section.fmr-page:not([hidden])');
        return sec ? Number(sec.getAttribute('data-fmr-page')) : null;
    });
}

async function pageCount(page) {
    return page.locator('form.fmr-form-v2 section.fmr-page').count();
}

async function allowsPrevious(page) {
    const f = form(page);
    if (!(await f.count())) return false;
    return (await f.getAttribute('data-allow-previous')) === 'on';
}

// Click the page's Next/Submit button. If the v2 client posts to
// /form-page-submit (or /form-sync from offline drain), return the parsed
// JSON body and HTTP status. If client-side native validation blocks the
// submit (e.g. blank required field), the request never fires — return
// `{ blockedByClient: true }` after the wait window. Callers branch on
// either shape.
async function submitV2(page, { timeout = 8000 } = {}) {
    let blocked = true;
    const respPromise = page.waitForResponse(
        (resp) => /\/(form-page-submit|form-sync)\/?$/.test(new URL(resp.url()).pathname),
        { timeout },
    ).then((resp) => { blocked = false; return resp; }).catch(() => null);

    // Click via page.evaluate to bypass Playwright's actionability waits.
    // The all_widgets fixture renders an OpenCPU error banner at the top that
    // sometimes obscures Playwright's actionability heuristics, hanging the
    // click for the full 30s default. Programmatic .click() is what real users
    // would experience after the bundle's submit-event handler attaches.
    await page.evaluate(() => {
        const btn = document.querySelector('form.fmr-form-v2 section.fmr-page:not([hidden]) [data-fmr-next]');
        if (btn) btn.click();
    });

    const resp = await respPromise;
    if (!resp) {
        return { blockedByClient: blocked, status: null, body: null };
    }
    let body = null;
    try {
        body = await resp.json();
    } catch {
        body = { _raw: await resp.text() };
    }
    return { blockedByClient: false, status: resp.status(), body };
}

async function goPrevious(page) {
    const prev = page.locator(`${FORM_SELECTOR} section.fmr-page:not([hidden]) [data-fmr-prev]`).first();
    await prev.click();
    // No network on Previous; just wait for the page-section swap.
    await page.waitForTimeout(50);
}

// All currently-visible inline error messages on the active page, plus any
// banner errors that didn't have a target input.
async function errorMessages(page) {
    return page.evaluate(() => {
        const sec = document.querySelector('form.fmr-form-v2 section.fmr-page:not([hidden])');
        if (!sec) return [];
        const inline = Array.from(sec.querySelectorAll('.fmr-invalid-feedback')).map((el) => el.textContent.trim());
        const banner = Array.from(sec.querySelectorAll('.fmr-error-banner')).flatMap(
            (b) => Array.from(b.querySelectorAll('div')).map((d) => d.textContent.trim()),
        );
        return [...inline, ...banner].filter(Boolean);
    });
}

// Server-side initial-render error block (renders only when an immediate
// reload re-shows the form with prior errors — rare, but FormRenderer
// supports it via $validationErrors).
async function topBannerErrors(page) {
    const banner = page.locator(`${FORM_SELECTOR} .fmr-error-messages`).first();
    if (!(await banner.count())) return [];
    return banner.locator('li').allInnerTexts();
}

async function progressPercent(page) {
    return page.evaluate(() => {
        const bar = document.querySelector('form.fmr-form-v2 [data-fmr-progress-bar]');
        if (!bar) return null;
        const v = bar.getAttribute('aria-valuenow');
        return v == null ? null : Number(v);
    });
}

// Wait until the form bundle has booted (Alpine bound, navigation handler
// installed). Without this, very early submits race the bundle and the page
// does a native form-POST instead of the JSON path.
async function waitForBundle(page, { timeout = 15000 } = {}) {
    // Wait for [data-fmr-next] (DOM is up) AND `window.fmrFormReady` (init
    // ran to completion — every listener attached including the submit
    // handler that intercepts the default form-POST). On real-device
    // BrowserStack the submit handler attaches LATE; without the
    // fmrFormReady wait, an early click defaults to a real form-POST
    // and the test sees a near-blank page after the navigation.
    await page.waitForFunction(
        () => !!document.querySelector('form.fmr-form-v2 section.fmr-page:not([hidden]) [data-fmr-next]')
            && window.fmrFormReady === true,
        null,
        { timeout },
    );
}

module.exports = {
    FORM_SELECTOR,
    form,
    isPresent,
    visiblePageNum,
    pageCount,
    allowsPrevious,
    submitV2,
    goPrevious,
    errorMessages,
    topBannerErrors,
    progressPercent,
    waitForBundle,
};
