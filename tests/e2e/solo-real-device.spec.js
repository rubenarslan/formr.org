// Solo layout across ALL item types — real-device smoke (BrowserStack).
//
// Runs under `npm run test:bs` on real iPhone Safari (browserstack.yml), and
// also locally under `npm run test:e2e`. It toggles the all_widgets v2 study to
// `solo` (via DB — no admin-UI browser context, BS-friendly) and verifies that
// every catalogued item type renders correctly in the one-item-per-screen solo
// layout on the device:
//   - the form is in solo mode with a seated step + Back/OK nav;
//   - every choice item (mc / mc_multiple / sex / rating_button / …) renders
//     its options (radios/checkboxes) — incl. items on later [hidden] pages,
//     which is the FormRenderer later-page-choices fix on a real browser;
//   - every other interactive item renders a usable control;
//   - a real tap on a single-choice card auto-advances to the next step.
//
// v2 renders all pages into one document, so the per-type rendering check needs
// no multi-page walk (page 1's readonly required geopoint can't be satisfied
// headlessly — see all-widgets-v2.spec).

const { test, expect, bsSafeEvaluate, clearBrowserState } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v2 = require('./helpers/v2Form');
const db = require('./helpers/db');

const RUN = () => runName('all_widgets', 'v2');
const STUDY_ID = () => {
    const rows = db.dbQuery(
        "SELECT su.form_study_id AS id FROM survey_units su " +
        "JOIN survey_run_units sru ON sru.unit_id = su.id " +
        "JOIN survey_runs r ON r.id = sru.run_id " +
        "WHERE r.name = 'e2e-aw-v2' AND su.type = 'Form' LIMIT 1",
    );
    return rows[0] && rows[0].id;
};

function setLayout(value) {
    const id = STUDY_ID();
    if (!id) throw new Error('could not resolve e2e-aw-v2 form study id');
    db.dbExecRaw(`UPDATE survey_studies SET layout = '${value}' WHERE id = ${parseInt(id, 10)};`);
}

test.describe('solo layout: all item types on a real device', () => {
    test.beforeAll(() => setLayout('solo'));
    test.afterAll(() => setLayout('default'));
    test.beforeEach(async ({ page }) => clearBrowserState(page));

    test('solo renders with a seated step and Back/OK nav', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        const r = await bsSafeEvaluate(page, () => {
            const form = document.querySelector('form.fmr-form-v2');
            return {
                layout: form && form.getAttribute('data-layout'),
                current: document.querySelectorAll('.fmr-solo-current').length,
                hasNav: !!document.querySelector('.fmr-solo-nav'),
                hasOk: !!document.querySelector('.fmr-solo-ok'),
            };
        });
        expect(r.layout).toBe('solo');
        expect(r.current).toBe(1);
        expect(r.hasNav).toBe(true);
        expect(r.hasOk).toBe(true);
    });

    test('every catalogued item type renders an appropriate control', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);

        const report = await bsSafeEvaluate(page, () => {
            // Types that legitimately render no interactive control.
            const DISPLAY_ONLY = ['note', 'note_iframe', 'mc_heading', 'blank', 'submit',
                'hidden', 'get', 'random', 'referrer', 'ip', 'browser', 'calculate', 'server'];
            const groups = [...document.querySelectorAll('form.fmr-form-v2 .form-group[class*="item-"]')];
            const seen = {};
            const failures = [];
            for (const g of groups) {
                const m = g.className.match(/item-([a-z_0-9]+)/);
                const type = m ? m[1] : '?';
                if (DISPLAY_ONLY.includes(type)) { seen[type] = true; continue; }
                let ok;
                if (g.querySelector('.mc-table')) {
                    // choice item — must render its options (the later-page fix)
                    ok = g.querySelectorAll('.mc-table input[type=radio], .mc-table input[type=checkbox]').length > 0;
                } else if (g.querySelector('.fmr-botcheck')) {
                    ok = true; // bot_check widget
                } else {
                    ok = !!g.querySelector(
                        'input:not([type=hidden]), select, textarea, button, canvas'
                    );
                }
                seen[type] = seen[type] || ok;
                if (!ok && !seen[type]) failures.push(type);
            }
            // de-dupe: a type fails only if NO instance of it rendered a control
            const reallyFailed = [...new Set(failures)].filter((t) => !seen[t]);
            return { types: Object.keys(seen).sort(), failed: reallyFailed };
        });

        expect(report.types.length, 'expected the full widget catalogue').toBeGreaterThanOrEqual(30);
        expect(report.failed, `item types that rendered no usable control: ${report.failed.join(', ')}`).toEqual([]);
    });

    test('tapping a single-choice card auto-advances on the device', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        const r = await bsSafeEvaluate(page, () => {
            return new Promise((resolve) => {
                const sleep = (ms) => new Promise((res) => setTimeout(res, ms));
                const cur = () => document.querySelector('.fmr-solo-current');
                const typeOf = (g) => (g ? (g.className.match(/item-[a-z_0-9]+/) || ['?'])[0] : null);
                (async () => {
                    for (let i = 0; i < 8; i++) {
                        const g = cur();
                        if (!g) break;
                        const single = typeOf(g) === 'item-mc'
                            && g.querySelector('input[type=radio]:not([disabled])')
                            && !g.querySelector('input[type=checkbox], select, textarea');
                        if (single) {
                            const before = g;
                            (g.querySelector('.mc-table > label') || g.querySelector('input[type=radio]')).click();
                            await sleep(900);
                            resolve({ reached: true, advanced: cur() !== before });
                            return;
                        }
                        const inp = g.querySelector('input:not([type=hidden]):not([disabled]),textarea');
                        if (inp) { inp.value = inp.value || 'x'; inp.dispatchEvent(new Event('input', { bubbles: true })); }
                        document.querySelector('.fmr-solo-ok').click();
                        await sleep(700);
                    }
                    resolve({ reached: false });
                })();
            });
        });
        expect(r.reached, 'expected a single-choice mc step').toBe(true);
        expect(r.advanced, 'tapping a card should auto-advance').toBe(true);
    });

});
