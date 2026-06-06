// Required/optional gating across ALL item types (form_v2).
//
// The bug: items whose value lives outside a natively-validated input (a hidden
// carrier — bot_check token, VAS slider, geopoint coords, file/image/audio/video
// recordings — or a readonly field) slipped past the client validator. A
// required-but-empty one returned valid, the page POSTed, the server rejected
// it, and the participant landed back on the same page with NO message.
//
// This is the LIGHTWEIGHT lane: it reuses the existing all_widgets v2 fixture
// (one of each type) and drives the REAL bundled validator (window.fmrValidatePage)
// per item, simulating required+empty (must block with an inline error) and
// optional+empty (must proceed). It forces each group visible so it works in
// either layout without toggling the study (no DB mutation, CI-safe). A true
// end-to-end empty-submit lane lives in required-gating-e2e.spec.js.

const { test, expect } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v2 = require('./helpers/v2Form');

const RUN = () => runName('all_widgets', 'v2');

// Types that render no answerable control — never gated regardless of required.
const DISPLAY_ONLY = ['note', 'note_iframe', 'mc_heading', 'blank', 'submit',
    'hidden', 'get', 'random', 'referrer', 'ip', 'browser', 'calculate', 'server'];

// Types that ALWAYS carry a submittable value, so a required one can't be
// "empty" and correctly does not block (this matches v1 and the server):
//   - range / range_ticks: the slider always has a position
//   - color: defaults to #000000
//   - check / check_button: an unchecked box submits "0", which the server
//     accepts as an answer (Item::validateInput treats "0" as non-empty), so
//     blocking client-side would be the wrong client/server inconsistency.
// They're excluded from the "must block when required" assertion.
const NEVER_EMPTY = ['range', 'range_ticks', 'color', 'check', 'check_button'];

// In-page sweep: for every input-bearing item type, force its group
// visible + empty (+required or not), run the real validator on that single
// group, and record whether it was flagged. Returns {gated, notGated} type lists.
function sweepFn(required) {
    const DISPLAY = ['note', 'note_iframe', 'mc_heading', 'blank', 'submit',
        'hidden', 'get', 'random', 'referrer', 'ip', 'browser', 'calculate', 'server'];
    const typeOf = (g) => (g.className.match(/item-([a-z_0-9]+)/) || [null, '?'])[1];
    document.querySelectorAll('.fmr-page').forEach((s) => s.removeAttribute('hidden'));
    const groups = [...document.querySelectorAll('form.fmr-form-v2 .fmr-page > .form-group')];
    const res = {};
    for (const g of groups) {
        const t = typeOf(g);
        if (DISPLAY.includes(t)) continue;
        document.querySelectorAll('.fmr-solo-current').forEach((x) => x.classList.remove('fmr-solo-current'));
        g.classList.add('fmr-solo-current');
        g.classList.remove('hidden');
        g.classList.toggle('required', required);
        g.classList.toggle('optional', !required);
        // empty every control; toggle native required attr to match
        g.querySelectorAll('input, select, textarea').forEach((el) => {
            if (el.type === 'radio' || el.type === 'checkbox') el.checked = false;
            else if (el.type !== 'file') el.value = '';
            if (!['hidden', 'file'].includes(el.type)) {
                if (required) el.setAttribute('required', 'required'); else el.removeAttribute('required');
            }
        });
        g.querySelectorAll('.vas-controls').forEach((w) => w.classList.remove('vas-touched'));
        g.querySelectorAll('.fmr-botcheck').forEach((w) => { delete w.dataset.state; });
        // clear any prior feedback so each item is judged fresh
        g.querySelectorAll('.fmr-invalid-feedback, .fmr-btn-feedback').forEach((el) => el.remove());
        g.classList.remove('is-invalid');

        const valid = window.fmrValidatePage(g);
        const flagged = g.classList.contains('is-invalid')
            || !!g.querySelector('.is-invalid, .fmr-invalid-feedback, .fmr-btn-feedback');
        // an item counts as "gated" only if it both blocked (valid===false) AND showed a message
        res[t] = (t in res) ? res[t] : { blocked: false, message: false };
        res[t].blocked = res[t].blocked || (valid === false);
        res[t].message = res[t].message || flagged;
    }
    const gated = Object.keys(res).filter((t) => res[t].blocked && res[t].message).sort();
    const notGated = Object.keys(res).filter((t) => !(res[t].blocked && res[t].message)).sort();
    return { gated, notGated, count: Object.keys(res).length };
}

test.describe('required-gating: all item types (validator sweep)', () => {

    test('every required item blocks with a visible error when empty', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        const hasHook = await page.evaluate(() => typeof window.fmrValidatePage === 'function');
        test.skip(!hasHook, 'bundle lacks the window.fmrValidatePage test hook');

        const r = await page.evaluate(sweepFn, true);
        expect(r.count, 'expected the full widget catalogue').toBeGreaterThanOrEqual(25);
        // Exclude types that always carry a value (can't be empty) — see NEVER_EMPTY.
        const realGaps = r.notGated.filter((t) => !NEVER_EMPTY.includes(t));
        expect(realGaps, `required item types that did NOT block with an error: ${realGaps.join(', ')}`).toEqual([]);
        // And the previously-broken hidden-value types MUST now gate.
        for (const t of ['geopoint', 'file', 'image', 'audio', 'video', 'visual_analog_scale', 'bot_check']) {
            if (r.notGated.includes(t)) expect(r.gated, `${t} must gate when required+empty`).toContain(t);
        }
    });

    test('every optional item proceeds when empty', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        const hasHook = await page.evaluate(() => typeof window.fmrValidatePage === 'function');
        test.skip(!hasHook, 'bundle lacks the window.fmrValidatePage test hook');

        const r = await page.evaluate(sweepFn, false);
        // "gated" here would mean an OPTIONAL empty item wrongly blocked.
        expect(r.gated, `optional item types that wrongly blocked when empty: ${r.gated.join(', ')}`).toEqual([]);
    });

});

module.exports = { DISPLAY_ONLY };
