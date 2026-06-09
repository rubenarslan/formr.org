// End-to-end COMPLETION of the v2 (FormRenderer) all_widgets survey, in both
// solo (one-item-per-screen) and non-solo (default, paged) layout. This is the
// gate the form_v2 finish work is judged by: a participant must be able to walk
// the whole survey to the end (Form unit -> next unit) with every item type —
// including the ones widgets.js skips (geopoint, select_or_add_*, the give-100
// block pair, the altcha bot_check, empty optional file/image/audio/video).
//
// Both modes drive the SAME study (1602, run e2e-aw-v2): `layout` is a per-study
// column, so we toggle it in the DB around each test and restore 'solo' after.

const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { freshParticipant } = require('./helpers/participant');
const v2 = require('./helpers/v2Form');
const v2c = require('./helpers/v2Complete');
const db = require('./helpers/db');

const RUN = 'e2e-aw-v2';
const STUDY_ID = 1602;

function setLayout(layout) {
    db.dbExecRaw(`UPDATE survey_studies SET layout='${layout}' WHERE id=${STUDY_ID}`);
}

test.afterAll(() => setLayout('solo')); // leave the fixture as the user expects

// A stable signature of the current solo step so we can tell "advanced" from
// "stuck" and avoid double-advancing an auto-advancing (radio/select) step.
async function soloSig(page) {
    try {
        return await page.evaluate(() => {
            const cur = document.querySelector('.fmr-solo-current');
            if (!cur) return 'none';
            const all = [...document.querySelectorAll('.fmr-page > .form-group')];
            return all.indexOf(cur) + ':' + ((cur.className.match(/item-[a-z_0-9]+/) || [''])[0]);
        });
    } catch (e) {
        return 'nav'; // execution context destroyed = a navigation (likely completion)
    }
}

async function fillScope(page, scopeSelector) {
    // Swallow "execution context destroyed" — a submit can navigate to the next
    // unit mid-fill; the caller's formGone() check handles completion.
    try {
        await v2c.solveBotCheckIfPresent(page, scopeSelector);
        await v2c.fillAll(page, scopeSelector);
    } catch (e) { /* navigation mid-fill */ }
}

async function formGone(page) {
    // The final submit navigates to the next unit; let any in-flight nav settle,
    // then the form is absent on the end/Stop page.
    await page.waitForLoadState('domcontentloaded').catch(() => {});
    try {
        return (await page.locator('form.fmr-form-v2').count()) === 0;
    } catch (e) {
        return false;
    }
}

// ---- solo driver: one item per screen ----
async function driveSolo(page) {
    for (let i = 0; i < 80; i++) {
        if (await formGone(page)) return { completed: true, steps: i };
        if (!(await page.locator('.fmr-solo-current').count().catch(() => 0))) { await page.waitForTimeout(250); continue; }
        const sig = await soloSig(page);
        await fillScope(page, '.fmr-solo-current');
        await page.waitForTimeout(350); // let an auto-advancing (radio/select/range) step move on its own
        if ((await soloSig(page)) === sig && !(await formGone(page))) {
            // manual step (text/checkbox/submit/bot_check/file): click OK / big
            // submit / Enter. Blur first to close any open tom-select dropdown
            // (select_or_add's openOnFocus) — otherwise the first OK click is
            // consumed closing it. Retry once for robustness.
            for (let attempt = 0; attempt < 2; attempt++) {
                await page.evaluate(() => {
                    if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
                    const ok = document.querySelector('.fmr-solo-ok');
                    const sub = document.querySelector('.fmr-solo-current .fmr-solo-bigsubmit, .fmr-solo-current button[type=submit], .fmr-solo-current [data-fmr-next]');
                    if (ok && ok.offsetParent !== null) ok.click();
                    else if (sub) sub.click();
                    else document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
                }).catch(() => {}); // click may navigate (final submit)
                await page.waitForTimeout(500);
                if (await formGone(page)) return { completed: true, steps: i };
                if ((await soloSig(page)) !== sig) break; // advanced
            }
            if ((await soloSig(page)) === sig && !(await formGone(page))) {
                let dump;
                try {
                    dump = await page.evaluate(() => {
                    const cur = document.querySelector('.fmr-solo-current');
                    if (!cur) return null;
                    const form = document.querySelector('form.fmr-form-v2');
                    const alp = form && form._x_dataStack && form._x_dataStack[0];
                    return {
                        showif: cur.getAttribute('x-showif') || cur.getAttribute('data-showif'),
                        hiddenClass: cur.classList.contains('hidden'),
                        alpine: alp ? { the_needy: alp.the_needy, suffering_animals: alp.suffering_animals,
                            tn_type: typeof alp.the_needy, sa_type: typeof alp.suffering_animals } : 'no-alpine',
                        inputs: [...cur.querySelectorAll('input,select,textarea')].map((e) => ({
                            type: e.type, name: e.name, checked: e.checked, val: (e.value || '').slice(0, 18),
                            dis: e.disabled, valid: e.willValidate ? e.checkValidity() : null,
                        })),
                    };
                });
                } catch (e) {
                    // the dump raced a navigation — the final submit redirected to
                    // the next unit. Settle and confirm completion.
                    await page.waitForTimeout(700);
                    if (await formGone(page)) return { completed: true, steps: i };
                    continue;
                }
                return { completed: false, stuckAt: sig, steps: i, errors: await v2.errorMessages(page), dump };
            }
        }
    }
    return { completed: false, stuckAt: await soloSig(page), steps: 80 };
}

// ---- non-solo driver: paged, many items per page ----
async function driveDefault(page) {
    for (let p = 0; p < 12; p++) {
        if (await formGone(page)) return { completed: true, pages: p };
        const scope = 'form.fmr-form-v2 section.fmr-page:not([hidden])';
        const before = await v2.visiblePageNum(page).catch(() => null);
        // Two passes: the first answers what's visible; picking a choice can REVEAL
        // a conditional required item via Alpine (async), so a second pass after a
        // tick fills those (in solo each item is its own step, so reveals are caught
        // naturally — here every item is on screen at once).
        await fillScope(page, scope);
        await page.waitForTimeout(350);
        await fillScope(page, scope);
        const res = await v2.submitV2(page, { timeout: RUNNING_ON_BS ? 60000 : 12000 });
        // Detect advancement by the visible page CHANGING (or navigating away), NOT
        // by submitV2's blockedByClient flag: under the BS service worker the
        // form-page-submit POST is intercepted, so waitForResponse never fires and
        // submitV2 falsely reports "blocked" even though the submit succeeded and
        // the next page is already showing. (Solo never hit this — it detects
        // advancement by the step changing.)
        await page.waitForTimeout(RUNNING_ON_BS ? 2500 : 1200);
        if (await formGone(page)) return { completed: true, pages: p }; // last page redirected to the next unit
        const after = await v2.visiblePageNum(page).catch(() => null);
        if (after !== before) continue; // advanced to the next page

        // Looks unchanged — but a final-page redirect can still be navigating.
        // Give it one more beat and re-check before declaring a real failure.
        await page.waitForTimeout(RUNNING_ON_BS ? 2000 : 1000);
        if (await formGone(page)) return { completed: true, pages: p };

        // Genuinely stuck → a server-validation error or a real client gate.
        const serverErrors = (res.body && res.body.status === 'errors') ? res.body.errors : null;
        const diag = await page.evaluate(function () {
            const form = document.querySelector('form.fmr-form-v2');
            const alp = form && form._x_dataStack && form._x_dataStack[0];
            const sections = Array.prototype.map.call(document.querySelectorAll('form.fmr-form-v2 section.fmr-page'), function (s) {
                return { page: s.getAttribute('data-fmr-page'), hidden: s.hasAttribute('hidden') };
            });
            const root = document.querySelector('form.fmr-form-v2 section.fmr-page:not([hidden])') || document;
            const unanswered = [];
            root.querySelectorAll('.form-group.required').forEach(function (g) {
                if (g.hasAttribute('data-fmr-hidden') || g.classList.contains('hidden') || g.offsetParent === null) return;
                const t = (g.className.match(/item-([a-z_0-9]+)/) || [null, '?'])[1];
                const answered = !!g.querySelector('input:checked, input[type=hidden][value]:not([value=""])')
                    || Array.prototype.some.call(g.querySelectorAll('input:not([type=checkbox]):not([type=radio]):not([type=hidden]):not([type=file]),textarea,select'), function (e) { return !e.readOnly && String(e.value || '').trim() !== ''; });
                if (!answered) unanswered.push({ t: t, page: g.closest('section.fmr-page') ? g.closest('section.fmr-page').getAttribute('data-fmr-page') : null });
            });
            return { sections: sections, alpine: alp ? 'yes' : 'no', unanswered: unanswered };
        }).catch(function (e) { return { error: String(e && e.message || e) }; });
        return { completed: false, pages: p, serverErrors, errors: await v2.errorMessages(page), diag };
    }
    return { completed: await formGone(page), pages: 12 };
}

test.describe('v2 survey completes end-to-end', () => {
    test('solo layout: walk every item to the end', async ({ page, baseURL }) => {
        // ~55 single-item steps + a memory-hard bot_check solve; on a real device
        // (BrowserStack) each step pays network latency, so allow much longer.
        test.setTimeout(RUNNING_ON_BS ? 720000 : 300000);
        setLayout('solo');
        await freshParticipant(page, RUN, { baseURL });
        await v2.waitForBundle(page);
        const r = await driveSolo(page);
        console.log('SOLO result', JSON.stringify(r));
        expect(r.completed, `stuck at ${r.stuckAt} :: ${JSON.stringify(r.errors || r)}`).toBe(true);
    });

    test('non-solo (default) layout: walk every page to the end', async ({ page, baseURL }) => {
        // Headroom over the ~20s happy path: when an item gate fails (as the
        // svg-intercepted bot_check click once did), each loop round burns the
        // 12s submit timeout — 120s expired mid-loop before driveDefault's
        // stuck-diagnostics could fire, leaving an opaque timeout instead of
        // the helpful {unanswered, serverErrors} dump.
        test.setTimeout(300000);
        setLayout('default');
        await freshParticipant(page, RUN, { baseURL });
        await v2.waitForBundle(page);
        const r = await driveDefault(page);
        console.log('DEFAULT result', JSON.stringify(r));
        expect(r.completed, `${JSON.stringify(r.serverErrors || r.errors || r)}`).toBe(true);
    });
});
