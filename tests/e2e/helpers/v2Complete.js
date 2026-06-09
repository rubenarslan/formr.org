// Drive a v2 (FormRenderer) survey to completion. One self-contained in-page
// filler (fillAll) answers every item type — the generic ones AND the ones
// widgets.js skips (geopoint, select_or_add_*, the give-100 block pair, cc) —
// for BOTH layouts:
//   - non-solo: scopeSelector = 'form.fmr-form-v2 section.fmr-page:not([hidden])'
//   - solo:     scopeSelector = '.fmr-solo-current' (the item IS the scope)
// so the filler considers the scope element itself plus its item-* descendants.
// The altcha bot_check needs a TRUSTED click, so it stays a separate node-side
// step (solveBotCheckIfPresent). All in-page work is one evaluate (BS-safe).

const { bsSafeEvaluate } = require('./test');

// Name -> value overrides on top of the generic fillers. The two "spend" numbers
// must sum to exactly 100 or the no_less_than_100 / no_more_than_100 block guards
// fire and bar the final submit.
const VALUE_OVERRIDES = {
    the_needy: '40',
    suffering_animals: '60',
    teeth: '20',                       // number 0..32; keep generic 42 from tripping max
    creditcard: '4242424242424242',    // valid Luhn (Visa test) if a cc is ever visible
};

async function fillAll(page, scopeSelector, overrides = VALUE_OVERRIDES) {
    return bsSafeEvaluate(page, ({ scopeSelector, overrides }) => {
        const scope = scopeSelector ? document.querySelector(scopeSelector) : document.body;
        if (!scope) return { scope: 'missing' };
        const fire = (el, t) => el.dispatchEvent(new Event(t, { bubbles: true }));
        const filled = [];

        // Candidate items: the scope element itself (solo: scope IS the item) plus
        // every item-* descendant (non-solo: the page section holds many).
        const set = new Set();
        if (/\bitem-[a-z_]+\b/.test(scope.className || '')) set.add(scope);
        scope.querySelectorAll('[class*="item-"]').forEach((n) => set.add(n));
        const items = [...set];

        const typeOf = (c) => ((c.className || '').match(/\bitem-([a-z_]+)\b/) || [null, null])[1];

        const STRAT = {
            text: (c) => setText(c, 'input[type=text]', 'hello'),
            textarea: (c) => setText(c, 'textarea', 'multi\nline'),
            number: (c) => setBounded(c, 'input[type=number]', 5),
            email: (c) => setText(c, 'input[type=email]', 'test@example.com'),
            url: (c) => setText(c, 'input[type=url]', 'https://example.com'),
            tel: (c) => setText(c, 'input[type=tel]', '+15551234567'),
            date: (c) => setBounded(c, 'input[type=date]', '2020-06-15'),
            datetime: (c) => setBounded(c, 'input[type="datetime-local"],input[type=text]', '2020-06-15T10:30'),
            time: (c) => setBounded(c, 'input[type=time]', '09:00'),
            color: (c) => setText(c, 'input[type=color]', '#336699'),
            year: (c) => setBounded(c, 'input[type=number],input', '1990'),
            month: (c) => setText(c, 'input[type=month],input[type=text]', '2024-06'),
            week: (c) => setText(c, 'input[type=week],input[type=text]', '2024-W24'),
            range: (c) => setRange(c),
            range_ticks: (c) => setRange(c),
            visual_analog_scale: (c) => setRange(c, '.vas-controls input[type=range].vas-display'),
            mc: (c) => setRadio(c),
            sex: (c) => setRadio(c),
            mc_button: (c) => clickBtn(c),
            check_button: (c) => clickBtn(c),
            mc_multiple_button: (c) => clickBtn(c),
            rating_button: (c) => clickBtn(c),
            check: (c) => setLastCheckbox(c),
            mc_multiple: (c) => setFirstCheckbox(c),
            select_one: (c) => setSelect(c, false),
            select_multiple: (c) => setSelect(c, true),
            select_or_add_one: (c) => setSelect(c, false),
            select_or_add_multiple: (c) => setSelect(c, true),
            geopoint: (c) => setGeo(c),
            cc: (c) => setText(c, 'input', '4242424242424242'),
        };

        function setText(c, sel, v) { const el = c.querySelector(sel); if (el && !el.readOnly && !el.disabled) { el.value = v; fire(el, 'input'); fire(el, 'change'); } }
        // Respect any admin-set min/max (e.g. the "time <= 12:00" item, a number
        // range, a "past date") so the default value can't trip native validation.
        function setBounded(c, sel, def) {
            const el = c.querySelector(sel); if (!el || el.readOnly || el.disabled) return;
            let v = def;
            const min = el.min || el.getAttribute('min');   // attribute fallback: type=year is a text input
            const max = el.max || el.getAttribute('max');
            const lt = (a, b) => (el.type === 'number' ? Number(a) < Number(b) : String(a) < String(b));
            if (min && lt(v, min)) v = min;
            if (max && lt(max, v)) v = max;
            el.value = String(v); fire(el, 'input'); fire(el, 'change');
        }
        function setRange(c, sel) { const el = c.querySelector(sel || 'input[type=range]'); if (!el) return; el.value = String((Number(el.min || 0) + Number(el.max || 100)) / 2); fire(el, 'input'); fire(el, 'change'); }
        function setRadio(c) { const rs = c.querySelectorAll('input[type=radio]'); if (!rs.length) return; rs.forEach((r) => { r.checked = false; }); const t = [...rs].find((r) => r.value && r.value !== ''); if (t) { t.checked = true; fire(t, 'input'); fire(t, 'change'); } }
        function clickBtn(c) { const inp = c.querySelector('input[type=radio],input[type=checkbox]'); if (!inp) return; const b = c.querySelector(`[data-for="${inp.id}"]`); if (b) b.click(); else { inp.checked = true; fire(inp, 'change'); } }
        function setLastCheckbox(c) { const bs = c.querySelectorAll('input[type=checkbox]'); const el = bs[bs.length - 1]; if (el) { el.checked = true; fire(el, 'input'); fire(el, 'change'); } }
        function setFirstCheckbox(c) { const t = [...c.querySelectorAll('input[type=checkbox]')].find((b) => b.value && b.value !== '0'); if (t) { t.checked = true; fire(t, 'change'); } }
        function tsPick(ts, multi) {
            // Select via tom-select's API: an existing option if there is one
            // (addItem), else create one (create-enabled select_or_add). Using
            // settings.valueField keeps addOption correct (it's 'id', not 'value').
            const existing = Object.keys(ts.options || {}).filter((v) => v && v !== '');
            if (existing.length) { ts.addItem(existing[0], false); }
            else if (ts.settings && ts.settings.create) { ts.createItem('Option A', false); }
            else { const d = {}; d[ts.settings.valueField] = 'Option A'; d[ts.settings.labelField] = 'Option A'; ts.addOption(d); ts.addItem('Option A', false); }
        }
        function setSelect(c, multi) {
            const sel = c.querySelector('select');
            if (sel && sel.tomselect) { tsPick(sel.tomselect, multi); fire(sel, 'input'); fire(sel, 'change'); return; }
            if (sel) {
                const real = [...sel.options].map((o) => o.value).filter((v) => v && v !== '');
                const pick = real[0] || 'Option A';
                if (!real.includes(pick)) sel.add(new Option(pick, pick, true, true));
                [...sel.options].forEach((o) => { o.selected = o.value === pick; });
                fire(sel, 'input'); fire(sel, 'change'); return;
            }
            const txt = c.querySelector('input.select2add');
            if (txt && txt.tomselect) { tsPick(txt.tomselect, multi); }
            else if (txt) { txt.value = 'Option A'; fire(txt, 'input'); fire(txt, 'change'); }
        }
        function setGeo(c) {
            const vis = c.querySelector('input[type=text]'); const hidden = c.querySelector('input[type=hidden]');
            if (vis) { vis.removeAttribute('readonly'); vis.value = 'lat:52.52/long:13.405'; fire(vis, 'input'); fire(vis, 'change'); }
            if (hidden && !hidden.value) { hidden.value = JSON.stringify({ coords: { latitude: 52.52, longitude: 13.405 } }); fire(hidden, 'change'); }
        }

        items.forEach((c) => {
            const type = typeOf(c);
            if (!type) return;
            const fn = STRAT[type];
            if (!fn) return;
            try { fn(c); filled.push(type); } catch (e) { filled.push(type + '!' + (e && e.message)); }
        });

        // name-based overrides last (give-100 pair etc.)
        Object.entries(overrides).forEach(([name, value]) => {
            const el = scope.querySelector(`input[name="${name}"],input[name="${name}[]"]`)
                || (/\bitem-[a-z_]+\b/.test(scope.className || '') ? null : null);
            if (el && !el.disabled && el.type !== 'hidden') { el.removeAttribute('readonly'); el.value = value; fire(el, 'input'); fire(el, 'change'); filled.push('override:' + name); }
        });

        return { scope: scopeSelector, filled };
    }, { scopeSelector, overrides });
}

// Solve the altcha bot_check inside scope (TRUSTED click + wait for PoW). No-op
// when absent or already verified.
async function solveBotCheckIfPresent(page, scopeSelector) {
    const present = await bsSafeEvaluate(page, ({ scopeSelector }) => {
        const root = scopeSelector ? document.querySelector(scopeSelector) : document;
        const w = root && root.querySelector('altcha-widget');
        if (!w) return false;
        return w.querySelector('.altcha')?.getAttribute('data-state') !== 'verified';
    }, { scopeSelector });
    if (!present) return false;

    await page.waitForFunction(() => !!customElements.get('altcha-widget') && !!window.$altcha, null, { timeout: 15000 }).catch(() => {});
    // Click the wrapper, not the input: altcha's checkmark svg is positioned
    // over the input and (pre-CSS-fix bundles) intercepts the click point,
    // which makes Playwright's strict actionability check refuse the input.
    // The widget handles bubbled wrapper clicks — same path a human takes.
    const box = page.locator(`${scopeSelector} altcha-widget .altcha-checkbox`).first();
    if (!(await box.count())) return false;
    await box.click().catch(() => {});
    await page.waitForFunction(
        (sel) => document.querySelector(`${sel} altcha-widget .altcha`)?.getAttribute('data-state') === 'verified',
        scopeSelector, { timeout: 30000 },
    ).catch(() => {});
    return true;
}

module.exports = { VALUE_OVERRIDES, fillAll, solveBotCheckIfPresent };
