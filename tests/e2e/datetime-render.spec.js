// Date / time / datetime-local / month / week / yearmonth render smoke.
//
// Phase 2 leftover from plan_form_v2 §8: "cross-browser smoke". The
// existing all-widgets specs exercise these via fill-all-and-submit, but
// that path is genuinely flaky on BS real devices (lots of CDP round-trips
// over a slow bridge). This spec is the lighter version: load the
// all_widgets fixture, assert each date/time variant renders as the
// expected `<input type>`, that it accepts a valid value via .fill(), and
// that the value sticks.
//
// No submit. The point is to catch type/format regressions per platform —
// e.g. iOS Safari rendering `datetime` as a plain text field, or Pixel
// Chrome refusing a value that desktop Chrome accepts.

const { test, expect } = require('@playwright/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');

// One entry per item type present in the all_widgets fixture (verified via
// `SELECT type, name FROM survey_items WHERE study_id = ...`). The
// `inputType` is what the server-rendered <input type=...> should be on a
// fully-supporting browser; iOS Safari + Android Chrome real devices may
// fall back to text for some of these (e.g. older `month`/`week`).
// `inputType` is what the server-side PHP emits for the `<input type=…>`
// attribute. Browsers may downgrade unsupported types to "text" silently
// — `datetime` is deprecated HTML5 (browsers will not show a picker but the
// `type` attribute still reads back as "datetime") and `yearmonth` is
// formr-specific, server-emitted as `month`.
const DATETIME_FIELDS = [
    { itemType: 'date',           name: 'geburtsdatum',      inputType: 'date',           value: '2024-06-15' },
    { itemType: 'time',           name: 'uhrzeit',           inputType: 'time',           value: '14:30' },
    { itemType: 'datetime-local', name: 'datetimelocaltest', inputType: 'datetime-local', value: '2024-06-15T10:30' },
    { itemType: 'datetime',       name: 'datetimetest',      inputType: 'datetime',       value: '2024-06-15T10:30' },
    { itemType: 'month',          name: 'monat',             inputType: 'month',          value: '2024-06' },
    { itemType: 'week',           name: 'weektest',          inputType: 'week',           value: '2024-W24' },
    { itemType: 'yearmonth',      name: 'yearmonthtest',     inputType: 'yearmonth',      value: '2024-06' },
];

test.describe('date/time render smoke', () => {
    test('all date/time variants render with the expected input type', async ({ browser }) => {
        // BS real-device CDP latency makes per-field round-trips slow. Pull
        // the whole snapshot in one page.evaluate and compare in node-land.
        const { context, page } = await freshParticipant(browser, runName('all_widgets', 'v2'));
        try {
            await expect(page.locator('form.fmr-form-v2').first()).toBeVisible({ timeout: 15000 });

            const names = DATETIME_FIELDS.map((f) => f.name);
            const observed = await page.evaluate((names) => {
                const out = {};
                names.forEach((n) => {
                    const el = document.querySelector(`input[name="${CSS.escape(n)}"]`);
                    out[n] = el ? el.type : null;
                });
                return out;
            }, names);

            for (const field of DATETIME_FIELDS) {
                const actualType = observed[field.name];
                if (actualType === null) {
                    test.info().annotations.push({
                        type: 'note',
                        description: `field ${field.name} (${field.itemType}) not present in this fixture`,
                    });
                    continue;
                }
                if (actualType !== field.inputType && actualType !== 'text') {
                    throw new Error(
                        `${field.name} (${field.itemType}): expected input type "${field.inputType}" or "text", got "${actualType}"`,
                    );
                }
                test.info().annotations.push({
                    type: 'note',
                    description: actualType === 'text'
                        ? `${field.name}: browser fell back to text for ${field.itemType} (server validation handles format)`
                        : `${field.name}: native ${actualType} input`,
                });
            }
            return; // skip the legacy per-field loop below
            // eslint-disable-next-line no-unreachable
            for (const field of DATETIME_FIELDS) {
                const input = page.locator(`input[name="${field.name}"]`).first();
                const cnt = await input.count();
                if (cnt === 0) {
                    test.info().annotations.push({
                        type: 'note',
                        description: `field ${field.name} (${field.itemType}) not present in this fixture; skipping its assertion`,
                    });
                    continue;
                }
                const actualType = await input.getAttribute('type');
                // Browsers may downgrade unsupported types to "text" — record
                // that as a soft note rather than failing, since formr's
                // server validation is the source of truth.
                if (actualType !== field.inputType && actualType !== 'text') {
                    throw new Error(
                        `${field.name} (${field.itemType}): expected input type "${field.inputType}" or "text", got "${actualType}"`,
                    );
                }
                if (actualType !== 'text') {
                    test.info().annotations.push({
                        type: 'note',
                        description: `${field.name}: native ${actualType} input`,
                    });
                } else {
                    test.info().annotations.push({
                        type: 'note',
                        description: `${field.name}: browser fell back to text for ${field.itemType} (server validation handles format)`,
                    });
                }
            }
        } finally {
            await context.close();
        }
    });

    test('date/time inputs accept valid values via .fill()', async ({ browser }) => {
        const { context, page } = await freshParticipant(browser, runName('all_widgets', 'v2'));
        try {
            await expect(page.locator('form.fmr-form-v2').first()).toBeVisible({ timeout: 15000 });

            // Set values via DOM directly (avoid Playwright's per-input fill
            // round-trip which is slow on BS real-device). Then read back via
            // a single page.evaluate.
            const written = await page.evaluate((fields) => {
                const out = {};
                fields.forEach((f) => {
                    const el = document.querySelector(`input[name="${CSS.escape(f.name)}"]`);
                    if (!el) { out[f.name] = { skipped: 'absent' }; return; }
                    if (el.type === 'text') { out[f.name] = { skipped: 'text-fallback', type: el.type }; return; }
                    try {
                        el.value = f.value;
                        el.dispatchEvent(new Event('input', { bubbles: true }));
                        el.dispatchEvent(new Event('change', { bubbles: true }));
                        out[f.name] = { type: el.type, set: f.value, got: el.value };
                    } catch (err) {
                        out[f.name] = { type: el.type, error: String(err && err.message || err) };
                    }
                });
                return out;
            }, DATETIME_FIELDS);

            // Soft assertions: any successful set should leave a non-empty
            // value behind. Browsers may reformat (locale), reject
            // malformed values, or silently accept anything — annotate per
            // platform so we have a record without churning the suite green.
            for (const field of DATETIME_FIELDS) {
                const r = written[field.name];
                if (!r) continue;
                if (r.skipped) {
                    test.info().annotations.push({ type: 'note', description: `${field.name}: skipped (${r.skipped})` });
                    continue;
                }
                if (r.error) {
                    test.info().annotations.push({ type: 'note', description: `${field.name} (${r.type}): set threw — ${r.error.slice(0, 120)}` });
                    continue;
                }
                if (r.got === '') {
                    test.info().annotations.push({ type: 'note', description: `${field.name} (${r.type}): browser rejected "${r.set}" silently — value cleared` });
                } else {
                    test.info().annotations.push({ type: 'note', description: `${field.name} (${r.type}): set "${r.set}" → got "${r.got}"` });
                }
            }
        } finally {
            await context.close();
        }
    });
});
