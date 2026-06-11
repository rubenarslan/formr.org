// form_v2 layout-modes spec alignment.
//
// Covers the functionality added for the layout-modes spec (paged=solo,
// scrolling=default). These run against the default-layout all_widgets v2 run
// (e2e-aw-v2) — no layout toggle needed; the behaviours under test (group
// a11y, top-aligned labels, on-blur validation, double-submit guard,
// incremental autosave, later-page choice rendering) all apply in default mode.
//
// v2 renders ALL pages into one document (later pages [hidden]), so the
// "choices render on later pages" fix is checked directly on a hidden section
// without driving a multi-page submit (page 1 of this fixture has a readonly
// required geopoint that the harness can't satisfy — see all-widgets-v2.spec).

const { test, expect } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v2 = require('./helpers/v2Form');
const db = require('./helpers/db');

const RUN = () => runName('all_widgets', 'v2');

// This suite asserts DEFAULT-layout behaviour, but other suites toggle the
// shared all_widgets study's layout and v2-finish deliberately leaves it on
// 'solo' (the user's preferred manual-fixture state). Pin it ourselves so
// execution order can't break us. workers:1 makes this race-free.
const STUDY_ID = 1602; // all_widgets — bound to e2e-aw-v2 via survey_units.form_study_id
test.beforeAll(() => db.dbExecRaw(`UPDATE survey_studies SET layout='default' WHERE id=${STUDY_ID}`));
test.afterAll(() => db.dbExecRaw(`UPDATE survey_studies SET layout='solo' WHERE id=${STUDY_ID}`));

test.describe('form_v2 spec: a11y group semantics', () => {

    test('radio sets get role=radiogroup + aria-labelledby the stem; text is not grouped', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);

        const r = await page.evaluate(() => {
            const pick = (sel) => document.querySelector(sel);
            const grp = (g) => g ? {
                role: g.querySelector('.controls')?.getAttribute('role') || null,
                labelledby: g.querySelector('.controls')?.getAttribute('aria-labelledby') || null,
                stemId: g.querySelector('.control-label')?.id || null,
            } : null;
            return {
                mc: grp(pick('.form-group.item-mc')),
                mcMultiple: grp(pick('.form-group.item-mc_multiple')),
                textRole: pick('.form-group.item-text .controls')?.getAttribute('role') || null,
            };
        });
        expect(r.mc, 'an mc item should exist').not.toBeNull();
        expect(r.mc.role).toBe('radiogroup');
        expect(r.mc.labelledby).toBe(r.mc.stemId);
        expect(r.mc.stemId).toMatch(/^item\d+-label$/);
        if (r.mcMultiple) {
            expect(r.mcMultiple.role).toBe('group');         // checkboxes: group, not radiogroup
            expect(r.mcMultiple.labelledby).toBe(r.mcMultiple.stemId);
        }
        expect(r.textRole, 'a single text input is not a group').toBeNull();
    });

});

test.describe('form_v2 spec: scrolling-mode labels', () => {

    test('default mode renders labels above inputs (single column)', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        expect(await v2.form(page).getAttribute('data-layout')).toBe('default');

        const r = await page.evaluate(() => {
            // an item that has both a stem label and an input side
            const g = [...document.querySelectorAll('.form-group.item-text, .form-group.item-email, .form-group.item-number')]
                .find((x) => x.querySelector('.control-label') && x.querySelector('.controls'));
            if (!g) return null;
            const cl = g.querySelector('.control-label').getBoundingClientRect();
            const cc = g.querySelector('.controls').getBoundingClientRect();
            return { labelBottom: cl.bottom, controlsTop: cc.top };
        });
        expect(r, 'an item with label + input should exist').not.toBeNull();
        // label sits ABOVE the input (stacked), not beside it
        expect(r.labelBottom).toBeLessThanOrEqual(r.controlsTop + 3);
    });

});

test.describe('form_v2 spec: choices render on later pages', () => {

    test('a choice item in a hidden (later) page section still renders its options', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);

        const r = await page.evaluate(() => {
            const laterPages = [...document.querySelectorAll('form.fmr-form-v2 section.fmr-page[hidden]')];
            // find an mc (not heading) on a later page; before the FormRenderer
            // fix these rendered an empty .mc-table (zero radios).
            for (const sec of laterPages) {
                const mc = sec.querySelector('.form-group.item-mc:not(.item-mc_heading)');
                if (mc) {
                    return {
                        foundLaterPage: true,
                        radios: mc.querySelectorAll('.mc-table input[type=radio]').length,
                    };
                }
            }
            return { foundLaterPage: false };
        });
        test.skip(!r.foundLaterPage, 'fixture has no mc item on a later page');
        expect(r.radios, 'later-page mc must render its radio options').toBeGreaterThan(0);
    });

});

test.describe('form_v2 spec: on-blur validation', () => {

    test('format error shows on blur of a filled field; empty does not nag; fix clears', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);

        const r = await page.evaluate(async () => {
            const sleep = (ms) => new Promise((res) => setTimeout(res, ms));
            const email = document.querySelector('.form-group.item-email input[type=email]');
            if (!email) return { noEmail: true };
            const wrap = email.closest('.form-group');
            const fb = () => [...wrap.querySelectorAll('.fmr-invalid-feedback')].map((f) => f.textContent);
            // invalid + blur -> error
            email.focus(); email.value = 'not-an-email';
            email.dispatchEvent(new Event('input', { bubbles: true }));
            email.blur(); await sleep(80);
            const afterInvalid = fb();
            // fix + blur -> cleared
            email.focus(); email.value = 'ok@example.com';
            email.dispatchEvent(new Event('input', { bubbles: true }));
            email.blur(); await sleep(80);
            const afterValid = fb();
            // empty + blur -> no nag (required is for submit)
            email.focus(); email.value = '';
            email.dispatchEvent(new Event('input', { bubbles: true }));
            email.blur(); await sleep(80);
            const afterEmpty = fb();
            return { afterInvalid, afterValid, afterEmpty };
        });
        test.skip(r.noEmail, 'fixture has no email item on page 1');
        expect(r.afterInvalid.length, 'invalid+blur surfaces a format error').toBeGreaterThan(0);
        expect(r.afterValid, 'valid value clears the error').toEqual([]);
        expect(r.afterEmpty, 'empty required must NOT nag on blur').toEqual([]);
    });

});

test.describe('form_v2 spec: double-submit guard', () => {

    test('a rapid double-click on Next fires at most one page POST', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);

        let posts = 0;
        page.on('request', (req) => {
            if (/\/form-page-submit\/?$/.test(new URL(req.url()).pathname)) posts += 1;
        });
        // Two synchronous clicks on the page Next button. The single-flight
        // guard must collapse these to one in-flight submit (or zero if the
        // client gate blocks an unfilled page) — never two.
        await page.evaluate(() => {
            const btn = document.querySelector('form.fmr-form-v2 section.fmr-page:not([hidden]) [data-fmr-next]');
            if (btn) { btn.click(); btn.click(); }
        });
        await page.waitForTimeout(2500);
        expect(posts).toBeLessThanOrEqual(1);
    });

});

test.describe('form_v2 spec: incremental autosave', () => {

    test('answering a field autosaves to the server and persists in the DB', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);

        const probe = 'autosave_e2e_' + Date.now();
        const set = await page.evaluate((val) => {
            const abode = document.querySelector('input[name="abode"]');
            if (!abode) return false;
            abode.value = val;
            abode.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
        }, probe);
        test.skip(!set, 'fixture has no abode text item');

        // Poll the DB for the autosaved value rather than racing the network
        // response: autosave is rate-limited to <=1 req/20s with a trailing
        // flush, so an init-time change could defer this save up to ~20s. The
        // poll covers both the immediate and the deferred (trailing) save.
        const query =
            "SELECT sid.answer AS answer FROM survey_items_display sid " +
            "JOIN survey_items si ON si.id = sid.item_id " +
            "WHERE si.name = 'abode' AND sid.session_id = (" +
            "  SELECT MAX(us.id) FROM survey_unit_sessions us " +
            "  JOIN survey_run_sessions rs ON rs.id = us.run_session_id " +
            "  JOIN survey_runs r ON r.id = rs.run_id WHERE r.name = 'e2e-aw-v2')";
        let answer = null;
        for (let i = 0; i < 16; i++) {            // up to ~24s (covers the 20s rate limit)
            const rows = db.dbQuery(query);
            answer = rows[0] && rows[0].answer;
            if (answer === probe) break;
            await page.waitForTimeout(1500);
        }
        expect(answer, 'abode answer should be persisted by autosave').toBe(probe);
    });

});

test.describe('form_v2 spec: layout recorded per response', () => {

    test('the response paradata records the layout mode (default)', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        expect(await v2.form(page).getAttribute('data-layout')).toBe('default');

        // createSurveyStudyRecord stamps SurveyStudy.layout onto the unit
        // session at first render (patch 068). The most recent e2e-aw-v2 unit
        // session is this participant's.
        await page.waitForTimeout(300);
        const rows = db.dbQuery(
            "SELECT us.layout AS layout FROM survey_unit_sessions us " +
            "JOIN survey_run_sessions rs ON rs.id = us.run_session_id " +
            "JOIN survey_runs r ON r.id = rs.run_id " +
            "WHERE r.name = 'e2e-aw-v2' ORDER BY us.id DESC LIMIT 1",
        );
        expect(rows[0] && rows[0].layout, 'layout mode must be recorded in paradata').toBe('default');
    });

});

test.describe('form_v2 spec: beforeunload guard (bfcache-scoped)', () => {

    test('the leave guard is armed only while a change is pending', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);

        const r = await page.evaluate(async () => {
            const sleep = (ms) => new Promise((res) => setTimeout(res, ms));
            // A synthetic cancelable beforeunload reveals whether the guard
            // handler is attached: our handler calls preventDefault().
            const probe = () => {
                const e = new Event('beforeunload', { cancelable: true });
                window.dispatchEvent(e);
                return e.defaultPrevented;
            };
            const setAbode = (v) => {
                const a = document.querySelector('input[name="abode"]');
                if (!a) return false;
                a.value = v;
                a.dispatchEvent(new Event('change', { bubbles: true }));
                return true;
            };
            const initial = probe();                 // no change yet → no guard
            if (!setAbode('guard_e1')) return { noAbode: true };
            setAbode('guard_e2'); await sleep(80);   // 2 changes → a save is pending (armed)
            const pending = probe();
            // the throttle flushes (<=20s); the guard must come back off.
            let cleared = false;
            for (let i = 0; i < 18; i++) { if (!probe()) { cleared = true; break; } await sleep(1500); }
            return { initial, pending, cleared };
        });
        test.skip(r.noAbode, 'fixture has no abode text item');
        expect(r.initial, 'no guard before any change keeps the page bfcache-eligible').toBe(false);
        expect(r.pending, 'guard armed while a change is pending').toBe(true);
        expect(r.cleared, 'guard removed once the change is flushed (bfcache-eligible again)').toBe(true);
    });

});

test.describe('form_v2 spec: visual analog scale has no visible default', () => {

    // A native range always paints a thumb at the midpoint, which reads as a
    // pre-chosen answer. The v1 hide rule (::-*-thumb{visibility:hidden}) is
    // INERT on a native control — WebKit/Blink ignore thumb styling unless the
    // input is appearance:none. The v2 fix takes .vas-display to appearance:none
    // (+ custom track/thumb) so the hide-until-touched actually applies.
    // getComputedStyle on the thumb pseudo is unreliable, so we assert the
    // precondition that makes the hide work (appearance:none) plus the markup
    // "no default" contract (display input carries no name and no value attr,
    // wrapper not yet .vas-touched). v2 renders all pages, so the VAS is in the
    // document even if it's on a later (hidden) page.
    test('vas-display is appearance:none and submits no default', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        const r = await page.evaluate(() => {
            const disp = document.querySelector('.vas-display');
            if (!disp) return { present: false };
            const wrap = disp.closest('.vas-controls');
            const cs = getComputedStyle(disp);
            const hidden = wrap ? wrap.querySelector('input[type=hidden]') : null;
            return {
                present: true,
                appearance: cs.getPropertyValue('appearance') || cs.getPropertyValue('-webkit-appearance'),
                displayHasName: disp.hasAttribute('name'),       // must NOT own the form name
                displayHasValueAttr: disp.hasAttribute('value'), // no default value baked in
                touched: wrap ? wrap.classList.contains('vas-touched') : null,
                hiddenName: hidden ? hidden.getAttribute('name') : null,
                hiddenEmpty: hidden ? (hidden.value === '') : null,
            };
        });
        test.skip(!r.present, 'fixture has no visual_analog_scale item');
        expect(r.appearance, 'vas-display must be appearance:none so the thumb-hide rule applies').toBe('none');
        expect(r.displayHasName, 'the visible range must not own the form name (it always submits a value)').toBe(false);
        expect(r.displayHasValueAttr, 'the visible range must not bake in a default value').toBe(false);
        expect(r.touched, 'wrapper starts un-touched (thumb hidden)').toBe(false);
        expect(r.hiddenName, 'the hidden sibling owns the form name').toBeTruthy();
        expect(r.hiddenEmpty, 'the submitted value is empty until the participant moves the slider').toBe(true);
    });

    // A required VAS owns its value in a hidden input (barred from native
    // constraint validation), so without explicit handling the v2 client lets an
    // untouched required VAS advance and only the server rejects it. Assert the
    // real bundled validator (window.fmrValidatePage) blocks it with an inline
    // message and that moving the slider clears the block. Uses the in-document
    // VAS (the fixture's is optional, so we mark it required for the check).
    test('a required, untouched VAS is blocked client-side; touching it clears the block', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        const r = await page.evaluate(async () => {
            const sleep = (ms) => new Promise((res) => setTimeout(res, ms));
            const grp = document.querySelector('.item-visual_analog_scale');
            if (!grp || typeof window.fmrValidatePage !== 'function') return { present: false };
            // make the group visible + required + untouched
            const sec = grp.closest('.fmr-page'); if (sec) sec.removeAttribute('hidden');
            grp.classList.add('fmr-solo-current');             // harmless in default layout
            document.documentElement.classList.remove('fmr-solo-locked');
            const wrap = grp.querySelector('.vas-controls');
            const hidden = wrap.querySelector('input[type=hidden]');
            grp.classList.remove('optional'); grp.classList.add('required');
            hidden.value = ''; wrap.classList.remove('vas-touched');

            const blockedUntouched = window.fmrValidatePage(grp) === false;
            const messageShown = !!grp.querySelector('.fmr-vas-feedback');

            const disp = grp.querySelector('.vas-display');
            disp.value = 42; disp.dispatchEvent(new Event('input', { bubbles: true }));
            await sleep(60);
            const passesAfterTouch = window.fmrValidatePage(grp) === true;
            const messageCleared = !grp.querySelector('.fmr-vas-feedback');
            return { present: true, visible: grp.offsetParent !== null, blockedUntouched, messageShown, touched: wrap.classList.contains('vas-touched'), passesAfterTouch, messageCleared };
        });
        test.skip(!r.present, 'fixture has no visual_analog_scale item or no validate hook');
        expect(r.visible, 'group must be displayed for the gate to apply').toBe(true);
        expect(r.blockedUntouched, 'untouched required VAS must block submit client-side').toBe(true);
        expect(r.messageShown, 'an inline "choose a point" message is surfaced').toBe(true);
        expect(r.touched, 'moving the slider marks the VAS touched').toBe(true);
        expect(r.passesAfterTouch, 'once touched, the VAS no longer blocks').toBe(true);
        expect(r.messageCleared, 'the inline message clears once touched').toBe(true);
    });

});
