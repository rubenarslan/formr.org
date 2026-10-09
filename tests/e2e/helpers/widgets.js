// The one item filler for e2e specs: answers every item type on a page (v1 or
// v2) with a known-valid value, in ONE in-page evaluate.
//
// Every caller goes through here — fillAllVisible (all-widgets-*, v2-polish,
// v2-queue-drain, pwa-low-v2, required-gating-e2e) and v2Complete.fillAll
// (v2-finish / v2-preview drivers). There is deliberately no second, node-side
// (locator-per-item) strategy table: that copy drifted (it called CSS.escape,
// which doesn't exist in Node, so its select fillers threw and nobody noticed),
// and a per-item CDP walk is slow locally and unworkable on BrowserStack iOS.
//
// Container convention (Item.php): every item wraps in
// `<div class="form-group ... item-<type> ...">`, so `.item-<type>` targets
// an item's wrapper in both v1 and v2 chrome.
//
// Gotchas baked in (formr_source/CLAUDE.md):
//   - Programmatic `radio.checked = true` doesn't uncheck same-name siblings;
//     clear them first.
//   - tom-select doesn't react to `select.value = …`; go through
//     `select.tomselect` (addItem / createItem) instead.
//   - Button groups (mc_button, check_button, …) are driven by clicking the
//     visible `.btn[data-for]`, which does the sibling-clear + btn-checked
//     toggle. A checkbox group toggles, so an already-answered item is left
//     alone — the filler is idempotent (v2Drive runs it twice per page).

const { bsSafeEvaluate } = require('./test');

// v2's visible page section — the scope most callers want.
const V2_PAGE = 'form.fmr-form-v2 section.fmr-page:not([hidden])';

// Name -> value overrides applied after the per-type fill. The two "spend"
// numbers must sum to exactly 100 or the no_less_than_100 / no_more_than_100
// block guards bar the final submit. Names absent from the page are ignored.
const VALUE_OVERRIDES = {
    the_needy: '40',
    suffering_animals: '60',
    teeth: '20',                       // number 0..32
    creditcard: '4242424242424242',    // valid Luhn (Visa test)
    // all_widgets_v2 showcase
    t_pattern: '12345',                // pattern [0-9]{5}
    t_cc: '4242424242424242',
    n_teeth: '20',
    r_needy: '40',
    r_animals: '60',
};

// In-page filler. Self-contained (no closure over module scope): it is
// serialised by page.evaluate / bsSafeEvaluate / locator.evaluate.
//   fillInPage({ scopeSelector, overrides })   — page.evaluate form
//   fillInPage(element, { overrides })          — locator.evaluate form
//   fillInPage({ listTypes: true })             — node-side: the handled types
// The scope element itself counts as an item when it carries an item-* class
// (solo layout: `.fmr-solo-current` IS the item).
// Returns [{ type, name, ok, skipped?, error? }] per item it had a strategy for;
// ok=false means the strategy found nothing it could set (e.g. no options).
function fillInPage(a, b) {
    const isEl = !!(a && typeof a === 'object' && a.nodeType === 1);
    const opts = (isEl ? b : a) || {};
    const fire = (el, t) => el.dispatchEvent(new Event(t, { bubbles: true }));
    const settable = (el) => !!el && !el.disabled;
    const empty = (el) => String(el.value == null ? '' : el.value).trim() === '';
    const notViews = (el) => !(el.name || '').startsWith('_item_views');

    // Set a text-like field if it is empty and editable.
    function setText(c, sel, v) {
        const el = c.querySelector(sel);
        if (!settable(el) || el.readOnly) return false;
        if (empty(el)) { el.value = v; fire(el, 'input'); fire(el, 'change'); }
        return true;
    }
    // As setText, but clamp the default into any min/max (the "time <= 12:00"
    // item, a number range, a "past date") so it can't trip native validation.
    function setBounded(c, sel, def) {
        const el = c.querySelector(sel);
        if (!settable(el) || el.readOnly) return false;
        if (!empty(el)) return true;
        let v = def;
        const min = el.min || el.getAttribute('min');   // attr fallback: year is a text input
        const max = el.max || el.getAttribute('max');
        const lt = (x, y) => (el.type === 'number' ? Number(x) < Number(y) : String(x) < String(y));
        if (min && lt(v, min)) v = min;
        if (max && lt(max, v)) v = max;
        el.value = String(v); fire(el, 'input'); fire(el, 'change');
        return true;
    }
    // Ranges always carry a value; set the midpoint and fire input so a VAS
    // mirrors it into its hidden carrier (what a real slider move does).
    function setRange(c, sel) {
        const el = c.querySelector(sel || 'input[type=range]');
        if (!settable(el)) return false;
        el.value = String((Number(el.min || 0) + Number(el.max || 100)) / 2);
        fire(el, 'input'); fire(el, 'change');
        return true;
    }
    function setRadio(c) {
        const rs = [...c.querySelectorAll('input[type=radio]')];
        if (rs.some((r) => r.checked)) return true;
        const t = rs.find((r) => r.value !== '' && !r.disabled);
        if (!t) return false;
        rs.forEach((r) => { r.checked = false; });
        t.checked = true; fire(t, 'input'); fire(t, 'change');
        return true;
    }
    // A check item is a hidden "0" + the visible checkbox sharing its name.
    function setLastCheckbox(c) {
        const bs = c.querySelectorAll('input[type=checkbox]');
        const el = bs[bs.length - 1];
        if (!settable(el)) return false;
        if (!el.checked) { el.checked = true; fire(el, 'input'); fire(el, 'change'); }
        return true;
    }
    function setFirstCheckbox(c) {
        const bs = [...c.querySelectorAll('input[type=checkbox]')];
        if (bs.some((x) => x.checked)) return true;
        const t = bs.find((x) => x.value && x.value !== '0' && !x.disabled);
        if (!t) return false;
        t.checked = true; fire(t, 'input'); fire(t, 'change');
        return true;
    }
    // Button groups: click the visible .btn[data-for] of the first choice.
    function clickBtn(c) {
        const inputs = [...c.querySelectorAll('input[type=radio],input[type=checkbox]')];
        if (inputs.some((i) => i.checked)) return true;
        const inp = inputs.find((i) => !i.disabled);
        if (!inp) return false;
        const btn = inp.id ? c.querySelector(`[data-for="${inp.id}"]`) : null;
        if (btn) { btn.click(); return true; }
        if (inp.type === 'radio') inputs.forEach((r) => { r.checked = false; });
        inp.checked = true; fire(inp, 'input'); fire(inp, 'change');
        return true;
    }
    // tom-select: an existing option if there is one, else create one
    // (create-enabled select_or_add). settings.valueField keeps addOption right.
    function tsPick(ts) {
        if ((ts.items || []).some((v) => v !== '')) return true;
        const existing = Object.keys(ts.options || {}).filter((v) => v !== '');
        if (existing.length) ts.addItem(existing[0], false);
        else if (ts.settings && ts.settings.create) ts.createItem('Option A', false);
        else {
            const d = {};
            d[ts.settings.valueField] = 'Option A';
            d[ts.settings.labelField] = 'Option A';
            ts.addOption(d); ts.addItem('Option A', false);
        }
        return true;
    }
    function setSelect(c) {
        const sel = c.querySelector('select');
        if (sel && sel.disabled) return false;
        if (sel && sel.tomselect) { tsPick(sel.tomselect); fire(sel, 'input'); fire(sel, 'change'); return true; }
        if (sel) {
            if ([...sel.selectedOptions].some((o) => o.value !== '')) return true;
            const real = [...sel.options].map((o) => o.value).filter((v) => v !== '');
            if (!real.length) return false;
            [...sel.options].forEach((o) => { o.selected = o.value === real[0]; });
            fire(sel, 'input'); fire(sel, 'change');
            return true;
        }
        // select_or_add rendered as a text input (tom-select'd or plain)
        // Named inputs only: v1's select2 inserts its own unnamed search box
        // before the original, and an input event there opens the dropdown
        // (its drop-mask then swallows the submit click).
        const txt = c.querySelector('input.select2add[name]')
            || [...c.querySelectorAll('input[type=text][name]')].find(notViews);
        if (!settable(txt)) return false;
        if (txt.tomselect) return tsPick(txt.tomselect);
        if (empty(txt)) { txt.value = 'Option A'; fire(txt, 'input'); fire(txt, 'change'); }
        return true;
    }
    // Geopoint: readonly visible field + a hidden value carrier (the one
    // hidden input that is neither _item_views bookkeeping nor name[]).
    // Fill both, as a manual entry after a geolocate click would.
    function setGeo(c) {
        const vis = c.querySelector('input[type=text]');
        const carrier = [...c.querySelectorAll('input[type=hidden]')]
            .find((h) => h.name && notViews(h) && !h.name.endsWith('[]'));
        if (!vis && !carrier) return false;
        if (vis && !vis.disabled && empty(vis)) {
            vis.removeAttribute('readonly');
            vis.value = 'lat:52.52/long:13.405'; fire(vis, 'input'); fire(vis, 'change');
        }
        if (carrier && empty(carrier)) {
            carrier.value = JSON.stringify({ coords: { latitude: 52.52, longitude: 13.405 } });
            fire(carrier, 'input'); fire(carrier, 'change');
        }
        return true;
    }

    const STRAT = {
        text: (c) => setText(c, 'input[type=text]', 'hello'),
        letters: (c) => setText(c, 'input[type=text]', 'hello'),
        textarea: (c) => setText(c, 'textarea', 'multi\nline'),
        number: (c) => setBounded(c, 'input[type=number]', 5),
        email: (c) => setText(c, 'input[type=email]', 'test@example.com'),
        url: (c) => setText(c, 'input[type=url]', 'https://example.com'),
        tel: (c) => setText(c, 'input[type=tel]', '+15551234567'),
        cc: (c) => setText(c, 'input[type=cc],input[type=text]', '4242424242424242'),
        date: (c) => setBounded(c, 'input[type=date]', '2020-06-15'),
        datetime: (c) => setBounded(c, 'input[type="datetime-local"],input[type=text]', '2020-06-15T10:30'),
        time: (c) => setBounded(c, 'input[type=time]', '09:00'),
        year: (c) => setBounded(c, 'input[type=number],input[type=year],input[type=text]', '1990'),
        month: (c) => setText(c, 'input[type=month],input[type=text]', '2024-06'),
        yearmonth: (c) => setText(c, 'input[type=yearmonth],input[type=month],input[type=text]', '2024-06'),
        week: (c) => setText(c, 'input[type=week],input[type=text]', '2024-W24'),
        color: (c) => { const el = c.querySelector('input[type=color]'); if (!settable(el)) return false; el.value = '#336699'; fire(el, 'input'); fire(el, 'change'); return true; },
        range: (c) => setRange(c),
        range_ticks: (c) => setRange(c),
        visual_analog_scale: (c) => setRange(c, '.vas-controls input[type=range].vas-display'),
        mc: setRadio,
        sex: setRadio,
        mc_button: clickBtn,
        check_button: clickBtn,
        mc_multiple_button: clickBtn,
        rating_button: clickBtn,
        check: setLastCheckbox,
        mc_multiple: setFirstCheckbox,
        select_one: setSelect,
        select_multiple: setSelect,
        select_or_add_one: setSelect,
        select_or_add_multiple: setSelect,
        geopoint: setGeo,
    };
    if (opts.listTypes) return Object.keys(STRAT);

    const scope = isEl ? a : (opts.scopeSelector ? document.querySelector(opts.scopeSelector) : document.body);
    if (!scope) return [{ type: null, ok: false, error: `scope not found: ${opts.scopeSelector}` }];

    const set = new Set();
    if (/\bitem-[a-z_]+\b/.test(scope.className || '')) set.add(scope);
    scope.querySelectorAll('[class*="item-"]').forEach((n) => set.add(n));

    const filled = [];
    set.forEach((c) => {
        const type = ((c.className || '').match(/\bitem-([a-z_]+)\b/) || [])[1];
        const fn = type && STRAT[type];
        if (!fn) return;
        const named = c.querySelector('[name]:not([name^="_item_views"])');
        const name = named ? named.name.replace(/\[\]$/, '') : null;
        // A showif-hidden item has every answer input disabled (v2's showif
        // directive) — nothing to answer, and a later pass fills it once a
        // reveal re-enables it.
        const answers = [...c.querySelectorAll('input[name],select[name],textarea[name]')].filter(notViews);
        if (answers.length && answers.every((el) => el.disabled)) {
            filled.push({ type, name, ok: true, skipped: 'disabled' });
            return;
        }
        try { filled.push({ type, name, ok: !!fn(c) }); }
        catch (e) { filled.push({ type, name, ok: false, error: String((e && e.message) || e) }); }
    });

    // Name-based overrides last (the give-100 pair, pattern fields, …).
    Object.entries(opts.overrides || {}).forEach(([name, value]) => {
        const el = scope.querySelector(`input[name="${name}"]:not([type=hidden]),input[name="${name}[]"]:not([type=hidden])`);
        if (!el || el.disabled) return;
        el.removeAttribute('readonly');
        el.value = value; fire(el, 'input'); fire(el, 'change');
        filled.push({ type: 'override', name, ok: true });
    });
    return filled;
}

// The item types the filler answers (for "which widgets are on this page?").
const WIDGET_TYPES = fillInPage({ listTypes: true });

// Fill every item in scope. scope: a CSS selector (default: the whole page),
// or a Locator (evaluated on its element). One evaluate either way — BS-safe
// for the selector form.
async function fillAllVisible(page, scope, { overrides = VALUE_OVERRIDES } = {}) {
    if (scope && typeof scope === 'object' && typeof scope.evaluate === 'function') {
        return scope.evaluate(fillInPage, { overrides });
    }
    return bsSafeEvaluate(page, fillInPage, { scopeSelector: scope || null, overrides });
}

module.exports = { V2_PAGE, VALUE_OVERRIDES, WIDGET_TYPES, fillAllVisible };
