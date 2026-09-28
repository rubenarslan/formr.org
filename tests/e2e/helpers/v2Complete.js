// Drive a v2 (FormRenderer) survey to completion. The item filling is the one
// shared in-page filler (helpers/widgets.js), for BOTH layouts:
//   - non-solo: scopeSelector = 'form.fmr-form-v2 section.fmr-page:not([hidden])'
//   - solo:     scopeSelector = '.fmr-solo-current' (the item IS the scope)
// The altcha bot_check needs a TRUSTED click, so it stays a separate node-side
// step (solveBotCheckIfPresent).

const { bsSafeEvaluate } = require('./test');
const { VALUE_OVERRIDES, fillAllVisible } = require('./widgets');

async function fillAll(page, scopeSelector, overrides = VALUE_OVERRIDES) {
    return fillAllVisible(page, scopeSelector, { overrides });
}

function botState(page, scopeSelector) {
    return bsSafeEvaluate(page, ({ scopeSelector }) => {
        const root = scopeSelector ? document.querySelector(scopeSelector) : document;
        const w = root && root.querySelector('altcha-widget');
        if (!w) return 'absent';
        return w.querySelector('.altcha')?.getAttribute('data-state') || 'unknown';
    }, { scopeSelector: scopeSelector || null });
}

// Solve the altcha bot_check inside scope (TRUSTED click + wait for PoW).
// Returns false when there is no widget in scope, true once it is 'verified'
// (immediately if it already was). Throws when a widget is present but never
// reaches 'verified' — so `expect(await solveBotCheckIfPresent(…)).toBe(true)`
// fails with the widget's last state rather than passing on a stuck PoW.
async function solveBotCheckIfPresent(page, scopeSelector) {
    const scope = scopeSelector || null;
    const state = await botState(page, scope);
    if (state === 'absent') return false;
    if (state === 'verified') return true;

    await page.waitForFunction(() => !!customElements.get('altcha-widget') && !!window.$altcha, null, { timeout: 15000 }).catch(() => {});
    // Click the wrapper, not the input: altcha's checkmark svg is positioned
    // over the input and (pre-CSS-fix bundles) intercepts the click point,
    // which makes Playwright's strict actionability check refuse the input.
    // The widget handles bubbled wrapper clicks — same path a human takes.
    const box = page.locator(`${scope ? scope + ' ' : ''}altcha-widget .altcha-checkbox`).first();
    if (!(await box.count())) {
        throw new Error(`bot_check widget present (state=${state}) but has no .altcha-checkbox to click`);
    }
    await box.click();
    try {
        await page.waitForFunction((sel) => {
            const root = sel ? document.querySelector(sel) : document;
            return root?.querySelector('altcha-widget .altcha')?.getAttribute('data-state') === 'verified';
        }, scope, { timeout: 30000 });
    } catch (e) {
        const last = await botState(page, scope).catch(() => 'unreadable');
        throw new Error(`bot_check proof-of-work did not reach 'verified' within 30s (last state=${last})`);
    }
    return true;
}

module.exports = { VALUE_OVERRIDES, fillAll, solveBotCheckIfPresent };
