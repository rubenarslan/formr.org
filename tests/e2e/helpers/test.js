// BrowserStack-aware test fixture.
//
// On BrowserStack iOS Safari real devices, the bridge permits exactly ONE
// browser context per device session. Playwright's default `page` fixture
// creates a new context per test; the second test in any file therefore
// fails with `browser.newContext: Failed to execute newContext.
// browserstack_error: Only one browser context is allowed`, which then
// closes the page and cascades into "Target page, context or browser has
// been closed" on every later test in the file.
//
// Fix: under BS, define a worker-scoped `bsPage` (one context + one page
// per worker) and have the test-scoped `page` fixture hand it back. With
// `workers: 1` in playwright.config.js this means one context per BS
// device session — what BS allows. We can't directly re-scope the
// built-in `context`/`page` fixtures (Playwright errors with "fixture
// already registered as a { scope: 'test' } fixture"), so we wrap them.
//
// Local-Chromium keeps Playwright's default per-test isolation: each test
// gets its own fresh context and page, so state never leaks between tests.
//
// State leakage on shared context: navigating fresh in `freshParticipant`
// is enough for v1/v2 form tests because formr issues a new user_code per
// request when there is no current session. For tests that DO need a
// pristine cookie jar (admin auth, sticky PWA install state),
// `freshParticipant` calls `clearBrowserState(page)` before navigating.

const base = require('@playwright/test');

const RUNNING_ON_BS = process.env.BROWSERSTACK_AUTOMATION === 'true';

const test = RUNNING_ON_BS
    ? base.test.extend({
        // Worker-scoped context + page. Created once per worker, torn
        // down at worker exit. Survives across every test in the worker.
        bsPage: [async ({ browser }, use) => {
            const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
            const page = await ctx.newPage();
            await use(page);
            await ctx.close().catch(() => {});
        }, { scope: 'worker' }],

        // Override the test-scoped `page` fixture to return the SAME
        // worker-scoped page. Re-scoping `page` directly throws
        // "already registered as { scope: 'test' }" — proxying it like
        // this keeps the scope and just changes the value.
        page: async ({ bsPage }, use) => {
            await use(bsPage);
        },
    })
    : base.test;

// page.evaluate workaround for BrowserStack Selenium bridge.
//
// On BS iOS Safari real devices, the bridge mangles args passed to
// page.evaluate. Vanilla Playwright sends the arg directly; the bridge
// wraps it in a tagged structure like
//
//     [{ k: "fields", v: { a: [{ o: [{ k: "name", v: { s: "geburtsdatum" } }, …] }] } }]
//
// (where k=key, v=value, s=string, n=number, a=array, o=object) and then
// hands that wrapped structure to the function as its first parameter.
// Neither `(names) => names.forEach(…)` nor `({ fields }) => fields.forEach(…)`
// receives what they expect — `names`/`fields` is undefined, the test
// blows up with `undefined is not a function`.
//
// `page.evaluate(<string>)` works around the arg-serializer but the
// bridge's return-value channel for string-form evaluate appears to
// return null for object results on iOS. Wrap the call in a no-arg
// Function instead — Playwright serializes the function body, the
// bridge needs no args to mangle, and the return value goes through
// the normal function-evaluate channel that does serialize objects.
//
// Local-Chromium goes through the normal `page.evaluate(fn, arg)` path —
// arg serialization works there.
async function bsSafeEvaluate(page, fn, args) {
    if (!RUNNING_ON_BS) return page.evaluate(fn, args);
    const literal = args === undefined ? 'undefined' : JSON.stringify(args);
    const wrapper = new Function(`return (${fn.toString()})(${literal});`);
    return page.evaluate(wrapper);
}

async function clearBrowserState(page) {
    if (!RUNNING_ON_BS) return; // local: each test already gets a fresh context
    try { await page.context().clearCookies(); } catch {}
    // localStorage/sessionStorage live per-origin and need a real document.
    // about:blank evaluates to no-op storage; only call when on an http origin.
    try {
        const url = page.url();
        if (url && /^https?:/.test(url)) {
            await page.evaluate(() => {
                try { localStorage.clear(); } catch {}
                try { sessionStorage.clear(); } catch {}
            });
        }
    } catch {}
}

module.exports = { test, expect: base.expect, RUNNING_ON_BS, clearBrowserState, bsSafeEvaluate };
