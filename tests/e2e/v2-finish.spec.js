// End-to-end COMPLETION of the v2 (FormRenderer) all_widgets survey, in both
// solo (one-item-per-screen) and non-solo (default, paged) layout. This is the
// gate the form_v2 finish work is judged by: a participant must be able to walk
// the whole survey to the end (Form unit -> next unit) with every item type —
// including the ones widgets.js skips (geopoint, select_or_add_*, the give-100
// block pair, the altcha bot_check, empty optional file/image/audio/video).
//
// Each layout has its OWN static fixture run (no DB layout-toggling, which
// breaks under the BS SDK): the default run, and the dedicated solo run
// (tests/e2e/setup/create-solo-fixture.sh).

const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v2 = require('./helpers/v2Form');
const v2c = require('./helpers/v2Complete');
const { fillScope, formGone, driveDefault } = require('./helpers/v2Drive');

const RUN_SOLO = () => runName('all_widgets_solo', 'v2');
const RUN_DEFAULT = () => runName('all_widgets', 'v2');
// The committed showcase documentation/example_surveys/all_widgets_v2.json
// (every item type v2 supports bar the PWA items), provisioned by
// tests/e2e/setup/create-v2-fixtures.js.
const RUN_SHOWCASE = () => runName('all_widgets_v2', 'v2');

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


test.describe('v2 survey completes end-to-end', () => {
    test('solo layout: walk every item to the end', async ({ page, baseURL }) => {
        // ~55 single-item steps + a memory-hard bot_check solve; on a real device
        // (BrowserStack) each step pays network latency, so allow much longer.
        test.setTimeout(RUNNING_ON_BS ? 720000 : 300000);
        await freshParticipant(page, RUN_SOLO(), { baseURL });
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
        await freshParticipant(page, RUN_DEFAULT(), { baseURL });
        await v2.waitForBundle(page);
        const r = await driveDefault(page);
        console.log('DEFAULT result', JSON.stringify(r));
        expect(r.completed, `${JSON.stringify(r.serverErrors || r.errors || r)}`).toBe(true);
    });

    test('all_widgets_v2 showcase: walk every page to the end', async ({ page, baseURL }) => {
        test.setTimeout(300000);
        const errors = [];
        page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
        page.on('response', (r) => { if (r.status() >= 400) errors.push(`${r.status()} ${new URL(r.url()).pathname}`); });
        await freshParticipant(page, RUN_SHOWCASE(), { baseURL });
        await v2.waitForBundle(page);
        expect(await v2.pageCount(page), 'five pages').toBe(5);
        const r = await driveDefault(page);
        console.log('SHOWCASE result', JSON.stringify(r));
        expect(r.completed, `${JSON.stringify(r.serverErrors || r.errors || r)}`).toBe(true);
        expect(r.pages, 'every page was submitted').toBe(4);
        expect(errors.filter((e) => !/favicon|ERR_INTERNET_DISCONNECTED/.test(e)), 'no console errors or failed requests').toEqual([]);
    });
});
