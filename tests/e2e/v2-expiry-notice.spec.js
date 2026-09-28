// form_v2: when the unit session's expiry passes while the page is open,
// the "page expired" notice appears (items/expiry-notifier.js) — in the
// study's language, from FormStrings like the rest of the engine's texts.
// The coverage pass found this path never ran.
//
// Fixture: run e2e-inputs-v2 (study e2e_v2_inputs); its language is set
// through the DB and restored. The page load recomputes the unit session's
// expiry from the study's settings (none here), so the deadline is handed to
// the page the way the template would: window.unit_session_expires, set by an
// init script before the page's own scripts run.

const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const { dbQuery, dbExecRaw } = require('./helpers/db');
const v2 = require('./helpers/v2Form');

const RUN = () => runName('v2_inputs', 'v2');
const STUDY = 'e2e_v2_inputs';

test('an expired page says so, in the study language, and offers a reload', async ({ page, baseURL }) => {
    test.skip(RUNNING_ON_BS, 'sets the study language through the DB; local lane only');
    await freshParticipant(page, RUN(), { baseURL });
    await v2.waitForBundle(page);
    const lang = dbQuery(`SELECT language FROM survey_studies WHERE name = '${STUDY}';`)[0].language;
    dbExecRaw(`UPDATE survey_studies SET language = 'de' WHERE name = '${STUDY}';`);
    try {
        await page.addInitScript(() => { window.unit_session_expires = new Date(Date.now() + 5000).toISOString(); });
        await page.reload({ waitUntil: 'domcontentloaded' });
        await v2.waitForBundle(page);
        const modal = page.locator('.fmr-expiry-modal');
        await expect(modal).toBeVisible({ timeout: 20000 });
        await expect(modal.locator('.fmr-expiry-modal-title')).toHaveText('Seite abgelaufen');
        await expect(modal.locator('.fmr-expiry-modal-reload')).toContainText('Neu laden');
    } finally {
        dbExecRaw(`UPDATE survey_studies SET language = '${lang}' WHERE name = '${STUDY}';`);
    }
});
