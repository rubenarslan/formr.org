// Stepping helpers for the solo layout, shared by solo-layout.spec.js (local
// Chromium) and solo-real-device.spec.js (BrowserStack iOS). All in-page
// functions are no-arg so they survive the BS arg-mangling bridge.

const { bsSafeEvaluate } = require('./test');

/* eslint-disable no-undef */
// Index of the seated step among the visible page's direct form-groups, plus
// its item type. Index lets the caller detect "didn't advance" (gated step)
// without smuggling a DOM ref out of the page.
function stepIndexFn() {
    const groups = [...document.querySelectorAll(
        'form.fmr-form-v2 .fmr-page:not([hidden]) > .form-group',
    )];
    const cur = document.querySelector('.fmr-solo-current');
    return {
        idx: cur ? groups.indexOf(cur) : -1,
        total: groups.length,
        type: cur ? (cur.className.match(/item-[a-z_0-9]+/) || ['item-?'])[0] : null,
    };
}

// Advance one step: tap a card on a single-choice (auto-advancing) step,
// otherwise fill any free-text control and click OK. Mirrors what the existing
// solo specs do inline.
function advanceFn() {
    const cur = document.querySelector('.fmr-solo-current');
    if (!cur) return { advanced: false, type: null };
    const type = (cur.className.match(/item-[a-z_0-9]+/) || ['item-?'])[0];
    const single = type === 'item-mc'
        && cur.querySelector('input[type=radio]:not([disabled])')
        && !cur.querySelector('input[type=checkbox], select, textarea');
    if (single) {
        (cur.querySelector('.mc-table > label') || cur.querySelector('input[type=radio]')).click();
        return { advanced: true, type, mode: 'tap' };
    }
    const inp = cur.querySelector('input:not([type=hidden]):not([disabled]),textarea');
    if (inp) {
        const t = (inp.type || inp.tagName).toLowerCase();
        if (['text', 'number', 'email', 'url', 'tel', 'search', 'textarea'].includes(t)) {
            inp.value = inp.value || 'x';
            inp.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }
    const ok = document.querySelector('.fmr-solo-ok');
    if (ok) ok.click();
    return { advanced: true, type, mode: 'ok' };
}
/* eslint-enable no-undef */

async function currentStep(page) { return bsSafeEvaluate(page, stepIndexFn); }

// Walk seated steps, calling `onStep({ index, type, i })` at each one BEFORE it
// advances (so the caller can assert geometry / screenshot the settled step).
// Stops at maxSteps, when no step is seated, or when a step doesn't advance
// (a required gate — its geometry has already been captured). Returns the list
// of step types visited.
async function walkSolo(page, { maxSteps = 8, settle = 800, onStep } = {}) {
    const visited = [];
    for (let i = 0; i < maxSteps; i++) {
        const info = await currentStep(page);
        if (!info || info.idx < 0) break;
        if (onStep) await onStep({ index: info.idx, type: info.type, i });
        visited.push(info.type);
        await bsSafeEvaluate(page, advanceFn);
        await page.waitForTimeout(settle);
        const after = await currentStep(page);
        if (!after || after.idx < 0 || after.idx === info.idx) break; // gated; stop
    }
    return visited;
}

module.exports = { stepIndexFn, advanceFn, currentStep, walkSolo };
