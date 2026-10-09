// Geometric assertions for the solo layout — the layer that was missing when
// the iOS short-screen bugs slipped through.
//
// Why this exists: the earlier solo tests asserted on the DOM and on proxies
// (`offsetHeight <= innerHeight`, `document.activeElement === input`). Those
// pass even when, on a real phone, the input is painted UNDER the fixed
// Back/OK footer or a control sits off the visible viewport with scroll
// locked. The only checks that catch those are *pixel-geometry* ones, run
// against `visualViewport` (the keyboard/toolbar-aware viewport) rather than
// `window.innerHeight` (which on iOS includes area the browser chrome covers).
//
// Two probes, both no-arg so they survive the BrowserStack arg-mangling
// bridge (see helpers/test.js — bsSafeEvaluate serialises the function body
// and calls it with no args):
//   - overlapProbeFn : does any interactive control on the seated step
//     intersect a fixed/sticky chrome element (progress bar, Back/OK nav,
//     pinned run footer)?  Catches "text box overlaps footer".
//   - reachProbeFn   : after scrollIntoView, does every interactive control
//     land inside the safe band (between the top-anchored and bottom-anchored
//     chrome, within the visual viewport)?  Catches "clickable target not
//     reachable through scroll" (the lock-trap bug).

const { expect, bsSafeEvaluate } = require('./test');

// Interactive controls a participant must be able to see and tap on a solo
// step. Radios/checkboxes are reached via their wrapping `.mc-table > label`
// card; `.item-check .checkbox` covers the lone-checkbox `check` item.
// `.ts-control` is tom-select's visible proxy.
const CTRL_SEL =
    'input:not([type=hidden]):not([type=radio]):not([type=checkbox]),'
    + 'textarea,select,.mc-table > label,.btn[data-for],.ts-control,.item-check .checkbox';

/* eslint-disable no-undef */
// Runs in the page. Measures the CURRENT (un-scrolled) seated step.
function overlapProbeFn() {
    const EPS = 2;
    const SEL = 'input:not([type=hidden]):not([type=radio]):not([type=checkbox]),'
        + 'textarea,select,.mc-table > label,.btn[data-for],.ts-control,.item-check .checkbox';
    const vv = window.visualViewport;
    const vvTop = vv ? vv.offsetTop : 0;
    const vvH = vv ? vv.height : window.innerHeight;
    const vvW = vv ? vv.width : window.innerWidth;
    const vvBottom = vvTop + vvH;
    const isVisible = (el) => {
        const cs = getComputedStyle(el);
        if (cs.display === 'none' || cs.visibility === 'hidden' || +cs.opacity === 0) return false;
        const r = el.getBoundingClientRect();
        return r.width > 1 && r.height > 1;
    };
    const cls = (el) => ((el.className && el.className.toString ? el.className.toString() : '')
        .trim().split(/\s+/).filter(Boolean).slice(0, 3).join('.')) || el.tagName.toLowerCase();

    const step = document.querySelector('.fmr-solo-current');
    if (!step) return { ok: false, reason: 'no .fmr-solo-current seated', overlaps: [], controls: [], chrome: [] };
    const stepType = (step.className.match(/item-[a-z_0-9]+/) || ['item-?'])[0];

    // fixed/sticky chrome painted OUTSIDE the seated step
    const chrome = [];
    for (const el of document.querySelectorAll('body *')) {
        const cs = getComputedStyle(el);
        if (cs.position !== 'fixed' && cs.position !== 'sticky') continue;
        if (el.closest('.fmr-solo-current')) continue;
        if (!isVisible(el)) continue;
        const r = el.getBoundingClientRect();
        if (r.width < 1 || r.height < 1) continue;
        chrome.push({ cls: cls(el), top: r.top, bottom: r.bottom, left: r.left, right: r.right });
    }
    const hits = (a, b) =>
        a.left < b.right - EPS && a.right > b.left + EPS
        && a.top < b.bottom - EPS && a.bottom > b.top + EPS;

    const controls = [];
    for (const el of step.querySelectorAll(SEL)) {
        if (!isVisible(el)) continue;
        const r = el.getBoundingClientRect();
        const rect = { top: r.top, bottom: r.bottom, left: r.left, right: r.right };
        const hit = chrome.find((c) => hits(rect, c));
        controls.push({
            sel: cls(el),
            top: Math.round(r.top), bottom: Math.round(r.bottom),
            overlapsChrome: !!hit,
            chromeHit: hit ? hit.cls : null,
            offViewport: r.bottom <= vvTop + EPS || r.top >= vvBottom - EPS,
        });
    }
    return {
        ok: true,
        step: stepType,
        viewport: { vvTop: Math.round(vvTop), vvH: Math.round(vvH), vvW: Math.round(vvW), innerH: window.innerHeight },
        chrome,
        controls,
        overlaps: controls.filter((c) => c.overlapsChrome),
    };
}
/* eslint-enable no-undef */

/* eslint-disable no-undef */
// Runs in the page. For each interactive control: scrollIntoView, then check it
// lands inside the safe band (between top-anchored and bottom-anchored fixed
// chrome, within the visual viewport). Async so it can settle each scroll.
async function reachProbeFn() {
    const EPS = 2;
    const SEL = 'input:not([type=hidden]):not([type=radio]):not([type=checkbox]),'
        + 'textarea,select,.mc-table > label,.btn[data-for],.ts-control,.item-check .checkbox';
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    const isVisible = (el) => {
        const cs = getComputedStyle(el);
        if (cs.display === 'none' || cs.visibility === 'hidden' || +cs.opacity === 0) return false;
        const r = el.getBoundingClientRect();
        return r.width > 1 && r.height > 1;
    };
    const cls = (el) => ((el.className && el.className.toString ? el.className.toString() : '')
        .trim().split(/\s+/).filter(Boolean).slice(0, 3).join('.')) || el.tagName.toLowerCase();

    const step = document.querySelector('.fmr-solo-current');
    if (!step) return { ok: false, reason: 'no .fmr-solo-current seated', offenders: [], count: 0 };

    // safe band relative to the *current* visual viewport, recomputed per control
    // (scrolling can move the keyboard-aware viewport on a real device).
    const band = () => {
        const vv = window.visualViewport;
        const vvTop = vv ? vv.offsetTop : 0;
        const vvBottom = vvTop + (vv ? vv.height : window.innerHeight);
        let topEdge = vvTop;
        let bottomEdge = vvBottom;
        for (const el of document.querySelectorAll('body *')) {
            const cs = getComputedStyle(el);
            if (cs.position !== 'fixed') continue;
            if (el.closest('.fmr-solo-current')) continue;
            if (!isVisible(el)) continue;
            const r = el.getBoundingClientRect();
            if (r.width < 1 || r.height < 1) continue;
            if (r.top <= vvTop + 8 && r.bottom > topEdge) topEdge = r.bottom;            // top-anchored
            if (r.bottom >= vvBottom - 8 && r.top < bottomEdge) bottomEdge = r.top;      // bottom-anchored
        }
        return { topEdge, bottomEdge };
    };

    const controls = [...step.querySelectorAll(SEL)].filter(isVisible);
    const offenders = [];
    for (const el of controls) {
        el.scrollIntoView({ block: 'center', behavior: 'auto' });
        await sleep(70);
        const { topEdge, bottomEdge } = band();
        const r = el.getBoundingClientRect();
        const bandH = bottomEdge - topEdge;
        // A control taller than the band only needs to intersect it; otherwise it
        // must sit fully inside. Either way "unreachable" means no overlap at all
        // with the band after centering (trapped above the top chrome or below the
        // footer) — the lock-trap / overlap bug.
        const reachable = r.height > bandH
            ? (r.top < bottomEdge - EPS && r.bottom > topEdge + EPS)
            : (r.top >= topEdge - EPS && r.bottom <= bottomEdge + EPS);
        if (!reachable) {
            offenders.push({
                sel: cls(el),
                top: Math.round(r.top), bottom: Math.round(r.bottom),
                topEdge: Math.round(topEdge), bottomEdge: Math.round(bottomEdge),
            });
        }
    }
    return { ok: true, count: controls.length, offenders };
}
/* eslint-enable no-undef */

// --- assertion wrappers (BS-aware via bsSafeEvaluate) -----------------------

async function chromeOverlap(page) { return bsSafeEvaluate(page, overlapProbeFn); }
async function reachability(page) { return bsSafeEvaluate(page, reachProbeFn); }

async function assertNoChromeOverlap(page, { label = '' } = {}) {
    const rep = await chromeOverlap(page);
    expect(rep.ok, `${label}: ${rep.reason || 'overlap probe failed'}`).toBe(true);
    const off = rep.overlaps.map((c) => `${c.sel}[${c.top}-${c.bottom}] under ${c.chromeHit}`);
    expect(off, `${label} step ${rep.step}: controls overlapping fixed chrome: ${off.join('; ') || 'none'}`).toEqual([]);
    return rep;
}

async function assertControlsReachable(page, { label = '' } = {}) {
    const rep = await reachability(page);
    expect(rep.ok, `${label}: ${rep.reason || 'reach probe failed'}`).toBe(true);
    const off = rep.offenders.map((o) => `${o.sel}[${o.top}-${o.bottom}] outside band[${o.topEdge}-${o.bottomEdge}]`);
    expect(off, `${label}: controls unreachable within the viewport: ${off.join('; ') || 'none'}`).toEqual([]);
    return rep;
}

// One geometry gate for a seated step: no overlap AND every control reachable.
async function assertSoloStepGeometry(page, { label = '' } = {}) {
    const overlap = await assertNoChromeOverlap(page, { label });
    const reach = await assertControlsReachable(page, { label });
    return { overlap, reach };
}

/* eslint-disable no-undef */
// State of the currently-focused field relative to the visual viewport. Read
// AFTER a tap to tell whether the soft keyboard rose (vvH shrank vs the
// pre-tap value the caller recorded) and whether the focused field stayed
// above it (bottom within the shrunken visualViewport). No-arg → BS-safe.
function focusedFieldGeomFn() {
    const vv = window.visualViewport;
    const vvTop = vv ? vv.offsetTop : 0;
    const vvH = vv ? vv.height : window.innerHeight;
    const vvBottom = vvTop + vvH;
    const ae = document.activeElement;
    const tag = ae ? ae.tagName.toLowerCase() : null;
    const editable = ae && ['input', 'textarea', 'select'].includes(tag);
    const r = editable ? ae.getBoundingClientRect() : null;
    return {
        vvH: Math.round(vvH),
        vvTop: Math.round(vvTop),
        innerH: window.innerHeight,
        activeTag: tag,
        activeType: (ae && ae.type) || null,
        activeRect: r ? { top: Math.round(r.top), bottom: Math.round(r.bottom) } : null,
        // focused field fully inside the keyboard-aware viewport?
        aboveKeyboard: r ? (r.bottom <= vvBottom + 2 && r.top >= vvTop - 2) : null,
    };
}

// Is there a free-text field (one that would raise a keyboard) on the seated
// step? Used to walk to a tappable field. No-arg → BS-safe.
function hasFreeTextFn() {
    const cur = document.querySelector('.fmr-solo-current');
    return !!(cur && cur.querySelector(
        'input[type=text],input[type=email],input[type=number],input[type=url],input[type=tel],input[type=search],textarea',
    ));
}
/* eslint-enable no-undef */

module.exports = {
    CTRL_SEL,
    overlapProbeFn,
    reachProbeFn,
    chromeOverlap,
    reachability,
    focusedFieldGeomFn,
    hasFreeTextFn,
    assertNoChromeOverlap,
    assertControlsReachable,
    assertSoloStepGeometry,
};
