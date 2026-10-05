// form_v2: what the browser knows about the form, and the one function that
// turns it into "where is the participant".
//
// The server renders every item of the study on its page and ships a state
// block (FormRenderer::stateBlock) — per item: id, page, answerable,
// required, answered, saved, value, hidden — plus the stored state of whatever a
// submit just wrote.
// This module holds that copy (createStore) and derives navigation from it
// (pageState): the page to open, the next and previous page, first/last,
// progress. Nothing else decides those; a reload, an online submit and an
// offline submit all go through here, so they land on the same page.
// See documentation/agent_doc/plan_form_v2_navigation_state.md.
//
// Pure: no DOM, no fetch. main.js feeds it a snapshot of the document
// (snapshotPages) and the page it remembered in localStorage.

// The browser's copy of the stored state, keyed by item name.
export function createStore(block) {
    const items = {};
    const src = (block && typeof block === 'object' && block.items) || {};
    Object.keys(src).forEach((name) => { items[name] = Object.assign({}, src[name]); });
    const store = {
        unitSessionId: block && Number(block.unit_session_id) ? Number(block.unit_session_id) : null,
        items,
        // Merge a submit reply's `items` (same shape) into the copy.
        merge(replyItems) {
            if (!replyItems || typeof replyItems !== 'object') return;
            Object.keys(replyItems).forEach((name) => {
                items[name] = Object.assign({}, items[name] || {}, replyItems[name]);
            });
        },
        // Record a submission the server has not seen yet (queued offline):
        // the posted names count as answered so navigation moves on as it
        // would after a live submit; `hiddenIds` as hidden.
        markQueued(data, hiddenIds) {
            Object.keys(data || {}).forEach((name) => {
                const v = data[name];
                const empty = v == null || v === '' || (Array.isArray(v) && v.length === 0);
                if (!items[name]) items[name] = { id: null, page: null, required: false, hidden: false };
                items[name].answered = !empty;
                items[name].saved = true;
                items[name].value = empty ? null : v;
            });
            const hidden = new Set((hiddenIds || []).map(Number));
            Object.keys(items).forEach((name) => {
                if (items[name].id != null) items[name].hidden = hidden.has(Number(items[name].id));
            });
        },
        // name → value, for the showif scope.
        values() {
            const out = {};
            Object.keys(items).forEach((name) => { out[name] = items[name].value == null ? null : items[name].value; });
            return out;
        },
    };
    return store;
}

// Which page to show, from a snapshot of the document.
//
//   snapshot: [{ page: N, items: [{ name, visible, answerable, automatic }] }]
//     in document order — one entry per <section data-fmr-page>; `visible`
//     is the live showif result, `answerable` whether the item takes input,
//     `automatic` an item with nothing to show whose value is still stored
//     (a `hidden` item its showif does not hide).
//   store: createStore()'s result (answered / saved per name).
//   remembered: the page the browser last showed (localStorage), or null.
//   allowPrevious: the study's flag.
export function pageState(snapshot, store, remembered, allowPrevious) {
    // Stored, not answered: an optional item its page was submitted with,
    // left empty, is `saved` with no value (answered implies saved) — testing
    // `answered` reopened the earliest such page on another device's reload.
    const answered = (name) => !!(store && store.items[name] && store.items[name].saved);
    const shown = snapshot.filter((p) => p.items.some((it) => it.visible));
    const shownPages = shown.map((p) => p.page);
    const isShown = (n) => shownPages.includes(Number(n));

    let current = null;
    const items = (store && store.items) || {};
    if (remembered != null && isShown(remembered)) {
        current = Number(remembered);
    } else if (!Object.keys(items).some((name) => items[name].answerable !== false && answered(name))) {
        // Nothing answered yet: a fresh form opens on its first shown page,
        // whatever it holds. The rule below looks for an answer to give and
        // would step over an opening page of notes (an introduction, the
        // consent text), which takes no answer and is never stored. Items
        // stored without the participant (ip, random, …) don't count.
        current = shownPages.length ? shownPages[0] : (snapshot[0] ? snapshot[0].page : null);
    } else {
        const open = shown.find((p) => p.items.some((it) => it.visible && it.answerable && !answered(it.name)));
        current = open ? open.page : (shownPages.length ? shownPages[shownPages.length - 1] : (snapshot[0] ? snapshot[0].page : null));
    }
    // A page with nothing to show but values to store — `hidden` items, the
    // v2 stand-in for `calculate` — is never shown. It goes with a shown
    // page's submit: the last shown page before it, else the first one after
    // it, else (nothing is shown at all) the form's first page.
    const automaticPages = snapshot
        .filter((p) => !p.items.some((it) => it.visible) && p.items.some((it) => it.automatic))
        .map((p) => p.page);
    const hostOf = (q) => {
        const before = shownPages.filter((n) => n < q);
        if (before.length) return before[before.length - 1];
        const after = shownPages.find((n) => n > q);
        if (after != null) return after;
        return snapshot[0] ? snapshot[0].page : null;
    };
    const nextAfter = (p) => {
        const later = shownPages.filter((n) => n > Number(p));
        return later.length ? later[0] : null;
    };
    const previousOf = (p) => {
        if (!allowPrevious) return null;
        const earlier = shownPages.filter((n) => n < Number(p));
        return earlier.length ? earlier[earlier.length - 1] : null;
    };
    return {
        currentPage: current,
        shownPages,
        isShown,
        nextAfter,
        previousOf,
        automaticPages,
        // The automatic pages a submit of page p carries.
        ridersOf: (p) => automaticPages.filter((q) => q !== Number(p) && hostOf(q) === Number(p)),
        isFirstShown: (p) => shownPages.length > 0 && shownPages[0] === Number(p),
        isLastShown: (p) => shownPages.length > 0 && shownPages[shownPages.length - 1] === Number(p),
        // Position of a page among the shown ones: {index (1-based), total}.
        progress: (p) => ({ index: Math.max(0, shownPages.indexOf(Number(p))) + 1, total: shownPages.length }),
    };
}

// The remembered page lives in localStorage, per unit session, so a reload
// (or a closed tab) reopens where the participant was and a repeated Form
// (diary) never inherits an earlier entry's page. Every access is guarded:
// storage can be missing, full or blocked.
const KEY = (unitSessionId) => `fmr:page:${unitSessionId}`;
export function readRememberedPage(unitSessionId) {
    if (!unitSessionId) return null;
    try {
        const v = window.localStorage.getItem(KEY(unitSessionId));
        const n = v == null ? NaN : Number(v);
        return Number.isFinite(n) && n > 0 ? n : null;
    } catch (e) { return null; }
}
export function rememberPage(unitSessionId, page) {
    if (!unitSessionId || !page) return;
    try { window.localStorage.setItem(KEY(unitSessionId), String(page)); } catch (e) { /* storage unavailable */ }
}
export function forgetPage(unitSessionId) {
    if (!unitSessionId) return;
    try { window.localStorage.removeItem(KEY(unitSessionId)); } catch (e) { /* storage unavailable */ }
}
