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

const { bsSafeEvaluate } = require('./test');

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
async function fillAllVisible(page, scope) {
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
