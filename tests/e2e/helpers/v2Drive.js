// Drivers that walk a v2 form to its end with generic answers
// (helpers/v2Complete.js fills the items). Shared by v2-finish.spec.js and
// v2-preview.spec.js.
const { RUNNING_ON_BS } = require('./test');
const v2 = require('./v2Form');
const v2c = require('./v2Complete');

async function fillScope(page, scopeSelector) {
    // Swallow "execution context destroyed" — a submit can navigate to the next
    // unit mid-fill; the caller's formGone() check handles completion.
    // Separate tries: a bot_check that fails to verify throws, and that must
    // not skip filling the rest of the scope (the stuck submit is reported by
    // the driver).
    try { await v2c.solveBotCheckIfPresent(page, scopeSelector); } catch (e) { /* unverified / navigation */ }
    try { await v2c.fillAll(page, scopeSelector); } catch (e) { /* navigation mid-fill */ }
}

async function formGone(page) {
    // The final submit navigates to the next unit; let any in-flight nav settle,
    // then the form is absent on the end/Stop page.
    await page.waitForLoadState('domcontentloaded').catch(() => {});
    try {
        return (await page.locator('form.fmr-form-v2').count()) === 0;
    } catch (e) {
        return false;
    }
}

// ---- non-solo driver: paged, many items per page ----
// `pages` is the number of page submits made — counted, not inferred from
// the loop index, which read one lower when the redirect was seen right after
// the final submit than when it was seen on the next pass.
async function driveDefault(page) {
    let submits = 0;
    for (let p = 0; p < 12; p++) {
        if (await formGone(page)) return { completed: true, pages: submits };
        const scope = 'form.fmr-form-v2 section.fmr-page:not([hidden])';
        const before = await v2.visiblePageNum(page).catch(() => null);
        // Two passes: the first answers what's visible; picking a choice can REVEAL
        // a conditional required item via Alpine (async), so a second pass after a
        // tick fills those (in solo each item is its own step, so reveals are caught
        // naturally — here every item is on screen at once).
        await fillScope(page, scope);
        await page.waitForTimeout(350);
        await fillScope(page, scope);
        const res = await v2.submitV2(page, { timeout: RUNNING_ON_BS ? 60000 : 12000 });
        submits++;
        // Detect advancement by the visible page CHANGING (or navigating away), NOT
        // by submitV2's blockedByClient flag: under the BS service worker the
        // form-page-submit POST is intercepted, so waitForResponse never fires and
        // submitV2 falsely reports "blocked" even though the submit succeeded and
        // the next page is already showing. (Solo never hit this — it detects
        // advancement by the step changing.)
        await page.waitForTimeout(RUNNING_ON_BS ? 2500 : 1200);
        if (await formGone(page)) return { completed: true, pages: submits }; // last page redirected to the next unit
        const after = await v2.visiblePageNum(page).catch(() => null);
        if (after !== before) continue; // advanced to the next page

        // Looks unchanged — but a final-page redirect can still be navigating.
        // Give it one more beat and re-check before declaring a real failure.
        await page.waitForTimeout(RUNNING_ON_BS ? 2000 : 1000);
        if (await formGone(page)) return { completed: true, pages: submits };

        // Genuinely stuck → a server-validation error or a real client gate.
        const serverErrors = (res.body && res.body.status === 'errors') ? res.body.errors : null;
        const diag = await page.evaluate(function () {
            const form = document.querySelector('form.fmr-form-v2');
            const alp = form && form._x_dataStack && form._x_dataStack[0];
            const sections = Array.prototype.map.call(document.querySelectorAll('form.fmr-form-v2 section.fmr-page'), function (s) {
                return { page: s.getAttribute('data-fmr-page'), hidden: s.hasAttribute('hidden') };
            });
            const root = document.querySelector('form.fmr-form-v2 section.fmr-page:not([hidden])') || document;
            const unanswered = [];
            root.querySelectorAll('.form-group.required').forEach(function (g) {
                if (g.hasAttribute('data-fmr-hidden') || g.classList.contains('hidden') || g.offsetParent === null) return;
                const t = (g.className.match(/item-([a-z_0-9]+)/) || [null, '?'])[1];
                const answered = !!g.querySelector('input:checked, input[type=hidden][value]:not([value=""])')
                    || Array.prototype.some.call(g.querySelectorAll('input:not([type=checkbox]):not([type=radio]):not([type=hidden]):not([type=file]),textarea,select'), function (e) { return !e.readOnly && String(e.value || '').trim() !== ''; });
                if (!answered) unanswered.push({ t: t, page: g.closest('section.fmr-page') ? g.closest('section.fmr-page').getAttribute('data-fmr-page') : null });
            });
            return { sections: sections, alpine: alp ? 'yes' : 'no', unanswered: unanswered };
        }).catch(function (e) { return { error: String(e && e.message || e) }; });
        return { completed: false, pages: submits, serverErrors, errors: await v2.errorMessages(page), diag };
    }
    return { completed: await formGone(page), pages: 12 };
}

module.exports = { fillScope, formGone, driveDefault };
