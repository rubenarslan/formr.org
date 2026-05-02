// Widget strategies for the all_widgets fixture.
//
// Each entry tells the spec how to find an item type's container, fill it
// with a known-valid value, and (optionally) fill it with a known-invalid
// value to trigger a server validation error. Specs iterate this table and
// apply matching strategies to whatever items are present on the visible
// page; unknown item types are reported as skipped, never failed.
//
// Container selector convention (Item.php:207): every item wraps in
// `<div class="form-group ... item-<type> ...">`. So `.item-mc_button`
// reliably targets a McButton item's wrapper, regardless of v1 or v2 page
// chrome.
//
// Two cross-cutting gotchas baked in here so per-spec code doesn't have
// to remember them (both documented in formr_source/CLAUDE.md):
//   - Programmatic `.checked = true` on a radio does NOT auto-uncheck
//     siblings; clear them first.
//   - tom-select doesn't react to `.value = …` mutation; use
//     `select.tomselect?.setValue(...)` and dispatch input/change.

const NAME_ATTR = 'data-fmr-item-name';

const { bsSafeEvaluate, RUNNING_ON_BS } = require('./test');

// Set a same-name radio group to a specific value, clearing siblings first.
async function setRadioGroup(page, name, value) {
    await bsSafeEvaluate(page, ({ name, value }) => {
        const inputs = document.querySelectorAll(`input[type=radio][name="${CSS.escape(name)}"]`);
        inputs.forEach((r) => { r.checked = false; });
        const target = document.querySelector(`input[type=radio][name="${CSS.escape(name)}"][value="${CSS.escape(value)}"]`);
        if (target) {
            target.checked = true;
            target.dispatchEvent(new Event('input', { bubbles: true }));
            target.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }, { name, value });
}

async function setCheckbox(page, name, checked) {
    await bsSafeEvaluate(page, ({ name, checked }) => {
        // For check items the form has both a hidden 0 and a visible checkbox sharing name.
        const visible = Array.from(document.querySelectorAll(`input[type=checkbox][name="${CSS.escape(name)}"]`)).pop();
        if (!visible) return;
        visible.checked = !!checked;
        visible.dispatchEvent(new Event('input', { bubbles: true }));
        visible.dispatchEvent(new Event('change', { bubbles: true }));
    }, { name, checked });
}

// Click a button-group entry by its data-for target id (preferred path —
// avoids the "programmatic .checked doesn't sync siblings" trap).
async function clickButtonByDataFor(container, inputId) {
    await container.locator(`[data-for="${inputId}"]`).first().click();
}

// Pick the first interactive choice in a button-group and click it via the
// visible button. Works for mc_button / rating_button / check_button.
async function selectFirstButton(page, container) {
    const firstInputId = await container.locator('input[type=radio], input[type=checkbox]').first().getAttribute('id');
    if (firstInputId) {
        await clickButtonByDataFor(container, firstInputId);
        return;
    }
    // Fallback: just click any visible .btn[data-for]
    await container.locator('[data-for]').first().click();
}

async function setTomSelect(page, selector, value) {
    return bsSafeEvaluate(page, ({ selector, value }) => {
        const el = document.querySelector(selector);
        if (!el) return false;
        if (el.tomselect && typeof el.tomselect.setValue === 'function') {
            el.tomselect.setValue(value, false);
            return true;
        }
        // Fall back to native select
        if (el.tagName === 'SELECT') {
            const v = Array.isArray(value) ? value : [value];
            Array.from(el.options).forEach((opt) => { opt.selected = v.includes(opt.value); });
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
        }
        return false;
    }, { selector, value });
}

// First non-NA option's value attribute on a multi-choice item. Skips empty
// strings (used by the placeholder "—" option in select items).
async function firstChoiceValue(container, kind = 'radio') {
    const inputs = container.locator(`input[type=${kind}]`);
    const n = await inputs.count();
    for (let i = 0; i < n; i++) {
        const v = await inputs.nth(i).getAttribute('value');
        if (v != null && v !== '') return v;
    }
    return null;
}

// Strategy table. Each entry:
//   type             — item type from spreadsheet (matches `.item-<type>`)
//   fillValid(page, c)
//   fillInvalid(page, c) — optional; omit when the type has no straightforward invalid case
//                          (Note, Submit, computed, etc.) or when "required + blank" is the only failure
const WIDGETS = [
    {
        type: 'text',
        async fillValid(page, c) { await c.locator('input[type=text]').first().fill('hello'); },
        async fillInvalid() { /* leave blank: required-text is the natural invalid case */ },
        invalidIsBlank: true,
    },
    {
        type: 'textarea',
        async fillValid(page, c) { await c.locator('textarea').first().fill('multi\nline'); },
        invalidIsBlank: true,
    },
    {
        type: 'number',
        async fillValid(page, c) { await c.locator('input[type=number]').first().fill('42'); },
        async fillInvalid(page, c) {
            // Force the browser to accept a non-numeric string by switching the
            // input to text; on submit, the server-side numeric validation rejects.
            const input = c.locator('input[type=number]').first();
            await input.evaluate((el) => { el.type = 'text'; });
            await input.fill('not-a-number');
        },
    },
    {
        type: 'email',
        async fillValid(page, c) { await c.locator('input[type=email]').first().fill('test@example.com'); },
        async fillInvalid(page, c) {
            const i = c.locator('input[type=email]').first();
            await i.evaluate((el) => { el.type = 'text'; });
            await i.fill('not-an-email');
        },
    },
    {
        type: 'url',
        async fillValid(page, c) { await c.locator('input[type=url]').first().fill('https://example.com'); },
        async fillInvalid(page, c) {
            const i = c.locator('input[type=url]').first();
            await i.evaluate((el) => { el.type = 'text'; });
            await i.fill('not a url');
        },
    },
    {
        type: 'tel',
        async fillValid(page, c) { await c.locator('input[type=tel]').first().fill('+15551234567'); },
        invalidIsBlank: true,
    },
    {
        type: 'date',
        async fillValid(page, c) { await c.locator('input[type=date]').first().fill('2024-06-15'); },
        invalidIsBlank: true,
    },
    {
        type: 'datetime',
        async fillValid(page, c) { await c.locator('input[type="datetime-local"], input[type=text]').first().fill('2024-06-15T10:30'); },
        invalidIsBlank: true,
    },
    {
        type: 'time',
        async fillValid(page, c) { await c.locator('input[type=time]').first().fill('14:30'); },
        invalidIsBlank: true,
    },
    {
        type: 'range',
        async fillValid(page, c) {
            const input = c.locator('input[type=range]').first();
            await input.evaluate((el) => {
                const target = (Number(el.min || 0) + Number(el.max || 100)) / 2;
                el.value = String(target);
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            });
        },
        invalidIsBlank: true,
    },
    {
        type: 'visual_analog_scale',
        // The visible range has no `name`; the inline touch listener
        // mirrors its value into the sibling hidden input on
        // `input`/`change`. Setting el.value + dispatching input is
        // what real participant interaction looks like.
        async fillValid(page, c) {
            const input = c.locator('.vas-controls input[type=range].vas-display').first();
            await input.evaluate((el) => {
                const target = (Number(el.min || 0) + Number(el.max || 100)) / 2;
                el.value = String(target);
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            });
        },
        invalidIsBlank: true,
    },
    {
        type: 'mc',
        async fillValid(page, c) {
            const name = await c.locator('input[type=radio]').first().getAttribute('name');
            const v = await firstChoiceValue(c, 'radio');
            if (name && v) await setRadioGroup(page, name, v);
        },
        invalidIsBlank: true,
    },
    {
        type: 'mc_button',
        async fillValid(page, c) { await selectFirstButton(page, c); },
        invalidIsBlank: true,
    },
    {
        type: 'check',
        async fillValid(page, c) {
            const name = await c.locator('input[type=checkbox]').first().getAttribute('name');
            if (name) await setCheckbox(page, name, true);
        },
        invalidIsBlank: true,
    },
    {
        type: 'check_button',
        async fillValid(page, c) { await selectFirstButton(page, c); },
        invalidIsBlank: true,
    },
    {
        type: 'mc_multiple',
        async fillValid(page, c) {
            const boxes = c.locator('input[type=checkbox]');
            const n = await boxes.count();
            // Tick the first checkbox (skip a hidden 0 if present)
            for (let i = 0; i < n; i++) {
                const v = await boxes.nth(i).getAttribute('value');
                if (v && v !== '0') {
                    const name = await boxes.nth(i).getAttribute('name');
                    await bsSafeEvaluate(page, ({ name, value }) => {
                        const target = document.querySelector(`input[type=checkbox][name="${CSS.escape(name)}"][value="${CSS.escape(value)}"]`);
                        if (target) {
                            target.checked = true;
                            target.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }, { name, value: v });
                    break;
                }
            }
        },
        invalidIsBlank: true,
    },
    {
        type: 'mc_multiple_button',
        async fillValid(page, c) { await selectFirstButton(page, c); },
        invalidIsBlank: true,
    },
    {
        type: 'rating_button',
        async fillValid(page, c) { await selectFirstButton(page, c); },
        invalidIsBlank: true,
    },
    {
        type: 'select_one',
        async fillValid(page, c) {
            const sel = c.locator('select').first();
            const id = await sel.getAttribute('id');
            const selector = id ? `#${CSS.escape(id)}` : 'select';
            const opts = await sel.locator('option').all();
            for (const o of opts) {
                const v = await o.getAttribute('value');
                if (v) { await setTomSelect(page, selector, v); return; }
            }
        },
        invalidIsBlank: true,
    },
    {
        type: 'select_multiple',
        async fillValid(page, c) {
            const sel = c.locator('select').first();
            const id = await sel.getAttribute('id');
            const selector = id ? `#${CSS.escape(id)}` : 'select';
            const opts = await sel.locator('option').all();
            for (const o of opts) {
                const v = await o.getAttribute('value');
                if (v) { await setTomSelect(page, selector, [v]); return; }
            }
        },
        invalidIsBlank: true,
    },
    {
        type: 'sex',
        async fillValid(page, c) {
            const name = await c.locator('input[type=radio]').first().getAttribute('name');
            const v = await firstChoiceValue(c, 'radio');
            if (name && v) await setRadioGroup(page, name, v);
        },
        invalidIsBlank: true,
    },
    {
        type: 'color',
        async fillValid(page, c) { await c.locator('input[type=color]').first().fill('#336699'); },
        invalidIsBlank: true,
    },
    {
        type: 'year',
        async fillValid(page, c) { await c.locator('input[type=number]').first().fill('1990'); },
        invalidIsBlank: true,
    },
    {
        type: 'month',
        async fillValid(page, c) { await c.locator('input[type=month], input[type=text]').first().fill('2024-06'); },
        invalidIsBlank: true,
    },
    {
        type: 'week',
        async fillValid(page, c) { await c.locator('input[type=week], input[type=text]').first().fill('2024-W24'); },
        invalidIsBlank: true,
    },
];

// Items we deliberately don't try to fill (display-only, ambient, computed,
// or interactively requiring browser permissions a CI can't grant). The
// fillAll helper passes over these.
const NON_INPUT_TYPES = new Set([
    'note', 'block', 'mc_heading', 'submit', 'cc', 'browser', 'ip',
    'referrer', 'server', 'get', 'random', 'calculate', 'hidden',
    'image', 'audio', 'video', 'note_iframe', 'add_to_home_screen',
    'push_notification', 'request_cookie', 'request_phone', 'timezone',
    'geopoint',  // requires navigator.geolocation; off the happy path here
    'file',      // multipart-only; covered by a dedicated upload spec slice
]);

// Walk the page for every visible `.item-*` wrapper, dispatch to the
// matching widget filler. Inverted from the original "for each widget
// type, find containers" because the latter does WIDGETS × items round-
// trips through CDP and is glacial on a 30-item page (CDP latency × N²).
//
// We resolve which items are present in a single page.evaluate, then iterate
// only the matched ones in node-land. This collapses ~1000 round-trips into
// ~30. Per-item fillers can still be slow (tom-select, etc.) but those are
// real work, not framework overhead.
//
// Scope can be a Locator (e.g. v2's visible page section) or omitted (whole
// page). The selector is rooted at scope.
//
// On BrowserStack iOS Safari this CDP-per-locator path is unworkable —
// `locator.fill` and `locator.getAttribute` pipeline several internal
// calls each, and the BS-Selenium bridge intermittently returns frames
// Playwright can't parse ("Serialized error must have either an error
// or a value"). The orphans land on the next CDP boundary (typically
// the form submit click). `fillAllVisibleInPage` does the entire walk
// in one bsSafeEvaluate call — DOM mutations only, no CDP round-trips
// per item — so the bridge sees a single evaluate and one return.
async function fillAllVisible(page, scope) {
    if (RUNNING_ON_BS) {
        const scopeSel = scope && typeof scope.evaluate === 'function'
            // Locator → use its evaluation context. On BS we can't pass a
            // Locator handle through bsSafeEvaluate, so resolve the scope
            // to a CSS selector. v2 is the only caller that passes a
            // scope; it always uses `form.fmr-form-v2 section.fmr-page:not([hidden])`.
            ? 'form.fmr-form-v2 section.fmr-page:not([hidden])'
            : null;
        return fillAllVisibleInPage(page, scopeSel);
    }
    return fillAllVisibleViaLocators(page, scope);
}

// Single-evaluate filler used on BrowserStack iOS Safari. All DOM
// mutations happen in the page; no per-input CDP round-trips. Mirrors
// the WIDGETS table strategies; if you add a new widget type to the
// table, also add the same strategy here.
async function fillAllVisibleInPage(page, scopeSelector) {
    return bsSafeEvaluate(page, ({ scopeSelector }) => {
        const root = scopeSelector ? document.querySelector(scopeSelector) : document;
        if (!root) return [];

        const filled = [];
        const fire = (el, type) => el.dispatchEvent(new Event(type, { bubbles: true }));

        // The strategies below are ports of the WIDGETS table fillValid
        // entries to "container element"-form. Keep in sync with the
        // node-side WIDGETS array above.
        const STRATEGIES = {
            text: (c) => { const el = c.querySelector('input[type=text]'); if (el) { el.value = 'hello'; fire(el, 'input'); fire(el, 'change'); } },
            textarea: (c) => { const el = c.querySelector('textarea'); if (el) { el.value = 'multi\nline'; fire(el, 'input'); fire(el, 'change'); } },
            number: (c) => { const el = c.querySelector('input[type=number]'); if (el) { el.value = '42'; fire(el, 'input'); fire(el, 'change'); } },
            email: (c) => { const el = c.querySelector('input[type=email]'); if (el) { el.value = 'test@example.com'; fire(el, 'input'); fire(el, 'change'); } },
            url: (c) => { const el = c.querySelector('input[type=url]'); if (el) { el.value = 'https://example.com'; fire(el, 'input'); fire(el, 'change'); } },
            tel: (c) => { const el = c.querySelector('input[type=tel]'); if (el) { el.value = '+15551234567'; fire(el, 'input'); fire(el, 'change'); } },
            date: (c) => { const el = c.querySelector('input[type=date]'); if (el) { el.value = '2024-06-15'; fire(el, 'input'); fire(el, 'change'); } },
            datetime: (c) => { const el = c.querySelector('input[type="datetime-local"], input[type=text]'); if (el) { el.value = '2024-06-15T10:30'; fire(el, 'input'); fire(el, 'change'); } },
            time: (c) => { const el = c.querySelector('input[type=time]'); if (el) { el.value = '14:30'; fire(el, 'input'); fire(el, 'change'); } },
            color: (c) => { const el = c.querySelector('input[type=color]'); if (el) { el.value = '#336699'; fire(el, 'input'); fire(el, 'change'); } },
            year: (c) => { const el = c.querySelector('input[type=number]'); if (el) { el.value = '1990'; fire(el, 'input'); fire(el, 'change'); } },
            month: (c) => { const el = c.querySelector('input[type=month], input[type=text]'); if (el) { el.value = '2024-06'; fire(el, 'input'); fire(el, 'change'); } },
            week: (c) => { const el = c.querySelector('input[type=week], input[type=text]'); if (el) { el.value = '2024-W24'; fire(el, 'input'); fire(el, 'change'); } },
            range: (c) => {
                const el = c.querySelector('input[type=range]');
                if (!el) return;
                el.value = String((Number(el.min || 0) + Number(el.max || 100)) / 2);
                fire(el, 'input'); fire(el, 'change');
            },
            visual_analog_scale: (c) => {
                // Visible range has no `name`; the inline touch listener
                // mirrors the value into the sibling hidden input on
                // input/change. Same flow as a participant moving the slider.
                const el = c.querySelector('.vas-controls input[type=range].vas-display');
                if (!el) return;
                el.value = String((Number(el.min || 0) + Number(el.max || 100)) / 2);
                fire(el, 'input'); fire(el, 'change');
            },
            mc: (c) => {
                const radios = c.querySelectorAll('input[type=radio]');
                if (!radios.length) return;
                radios.forEach((r) => { r.checked = false; });
                const target = Array.from(radios).find((r) => r.value && r.value !== '');
                if (target) { target.checked = true; fire(target, 'input'); fire(target, 'change'); }
            },
            sex: (c) => STRATEGIES.mc(c),
            mc_button: (c) => {
                const inp = c.querySelector('input[type=radio], input[type=checkbox]');
                if (!inp) return;
                const btn = c.querySelector(`[data-for="${inp.id}"]`);
                if (btn) btn.click();
            },
            check: (c) => {
                const boxes = c.querySelectorAll('input[type=checkbox]');
                const visible = boxes[boxes.length - 1];
                if (!visible) return;
                visible.checked = true; fire(visible, 'input'); fire(visible, 'change');
            },
            check_button: (c) => STRATEGIES.mc_button(c),
            mc_multiple: (c) => {
                const boxes = c.querySelectorAll('input[type=checkbox]');
                const target = Array.from(boxes).find((b) => b.value && b.value !== '0');
                if (target) { target.checked = true; fire(target, 'change'); }
            },
            mc_multiple_button: (c) => STRATEGIES.mc_button(c),
            rating_button: (c) => STRATEGIES.mc_button(c),
            select_one: (c) => {
                const sel = c.querySelector('select');
                if (!sel) return;
                const opt = Array.from(sel.options).find((o) => o.value);
                if (!opt) return;
                if (sel.tomselect && typeof sel.tomselect.setValue === 'function') {
                    sel.tomselect.setValue(opt.value, false);
                } else {
                    Array.from(sel.options).forEach((o) => { o.selected = o.value === opt.value; });
                    fire(sel, 'input'); fire(sel, 'change');
                }
            },
            select_multiple: (c) => STRATEGIES.select_one(c),
        };

        const items = root.querySelectorAll('[class*="item-"]');
        items.forEach((node) => {
            const m = (node.className || '').match(/\bitem-([a-z_]+)\b/);
            if (!m) return;
            const type = m[1];
            const fn = STRATEGIES[type];
            if (!fn) return;
            try {
                fn(node);
                filled.push({ type });
            } catch (err) {
                filled.push({ type, error: String(err && err.message || err) });
            }
        });
        return filled;
    }, { scopeSelector });
}

async function fillAllVisibleViaLocators(page, scope) {
    const filled = [];
    const widgetByType = new Map(WIDGETS.map((w) => [w.type, w]));
    const root = scope || page;
    // Discover present item types and the count of each, in one round-trip.
    // Use page.evaluate for the whole-page case (Locator.evaluate requires
    // an element handle and we don't want to depend on `body` always being a
    // single match).
    const presence = scope
        ? await scope.evaluate((el) => {
              const items = el.querySelectorAll('[class*="item-"]');
              const counts = {};
              items.forEach((node) => {
                  const m = (node.className || '').match(/\bitem-([a-z_]+)\b/);
                  if (!m) return;
                  counts[m[1]] = (counts[m[1]] || 0) + 1;
              });
              return counts;
          })
        : await page.evaluate(() => {
              const items = document.querySelectorAll('[class*="item-"]');
              const counts = {};
              items.forEach((node) => {
                  const m = (node.className || '').match(/\bitem-([a-z_]+)\b/);
                  if (!m) return;
                  counts[m[1]] = (counts[m[1]] || 0) + 1;
              });
              return counts;
          });

    for (const [type, count] of Object.entries(presence)) {
        const w = widgetByType.get(type);
        if (!w) continue;
        const locators = root.locator(`.item-${type}`);
        for (let i = 0; i < count; i++) {
            const c = locators.nth(i);
            try {
                // 5s per filler — protects the suite from a hang on a single item.
                await Promise.race([
                    w.fillValid(page, c),
                    new Promise((_, rej) => setTimeout(() => rej(new Error(`fill timeout for ${type}#${i}`)), 5000)),
                ]);
                filled.push({ type, index: i });
            } catch (e) {
                filled.push({ type, index: i, error: String(e && e.message || e) });
            }
        }
    }
    return filled;
}

module.exports = {
    WIDGETS,
    NON_INPUT_TYPES,
    fillAllVisible,
    setRadioGroup,
    setCheckbox,
    selectFirstButton,
    setTomSelect,
};
