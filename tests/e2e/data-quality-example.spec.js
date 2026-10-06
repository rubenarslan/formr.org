// The example run for the data-quality items, start to finish, on both engines.
//
// Runs e2e-dq-example-v1 and e2e-dq-example-v2 are imported from
// documentation/run_components/Data_Quality.json and Data_Quality_v2.json
// (setup/create-v2-fixtures.js): a consent survey, a skip for those who do not
// consent, the two-page study with an agent_probe and a data_quality item on
// each page, and an end page that reads the visit's own records with the
// formr R package — dq_flags_by_session(), so OpenCPU needs formr 1.3.0 — and
// shows what is flagged.
//
// A visit made the way a person answers must come out with no input flag on
// that page; a scripted one that also answers the hidden field must be
// flagged for both. Each visit writes what it was into `purpose_text`, so the
// sessions these tests leave behind are a labelled sample to analyse.

const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const { human, plain } = require('./helpers/human');
const { token, record } = require('./helpers/dataQuality');
const v2 = require('./helpers/v2Form');
const db = require('./helpers/db');

const RUNS = require('./setup/runs.json');
const SCOPE = { v1: 'form.main_formr_survey', v2: 'form.fmr-form-v2 section.fmr-page:not([hidden])' };
const NEXT = { v1: '.item-submit button', v2: '[data-fmr-next]' };
const ANALYSIS = '.rmarkdown_iframe iframe';

test.skip(RUNNING_ON_BS, 'local Chromium only: it judges input made through Playwright\'s mouse and keyboard');

const ready = (page, engine) => (engine === 'v2'
    ? v2.waitForBundle(page, { timeout: 30000 })
    : page.waitForSelector(`${SCOPE.v1} ${NEXT.v1}`, { timeout: 30000 }));

// Press the page's button and wait for what comes next: a field of the next
// page, or the end page. The consent survey and the study are two units, so
// on v2 too the browser loads a new document in between.
async function go(page, engine, input, marker) {
    await input.click(page.locator(`${SCOPE[engine]} ${NEXT[engine]}`));
    await page.waitForSelector(marker, { state: 'attached', timeout: 90000 });
}

// The whole run. `label` goes into the last open question.
async function visit(page, engine, baseURL, input, { tok, label, probe = null, twin = 'No' }) {
    const scope = () => page.locator(SCOPE[engine]);
    await freshParticipant(page, runName('dq_example', engine), { baseURL });
    await ready(page, engine);
    await input.click(scope().locator('.item-mc_button .btn[data-for]').first());   // Yes, I agree
    await go(page, engine, input, `${SCOPE[engine]} textarea[name=weekend_text]`);
    await ready(page, engine);

    // Part 1: five statements, an open question.
    for (let i = 0; i < 5; i++) {
        await input.click(scope().locator('.item-mc_button').nth(i).locator('.btn[data-for]').nth(1 + (i % 4)));
    }
    await input.typeInto(scope().locator('textarea[name=weekend_text]'), `On Saturday I went to the lake with two friends, ${tok}.`);
    if (probe !== null) await scope().locator('.item-agent_probe textarea').fill(probe);
    await go(page, engine, input, `${SCOPE[engine]} textarea[name=purpose_text]`);
    await ready(page, engine);

    // Part 2: the three statements, age, an open question.
    await input.click(scope().locator('.item-mc_button .btn[data-for]').nth(twin === 'Yes' ? 0 : 1));
    await input.typeInto(scope().locator('.item-number input:visible').first(), '34');
    await input.typeInto(scope().locator('textarea[name=purpose_text]'), label);
    await go(page, engine, input, ANALYSIS);
}

// What the analysis page says: it is knitted by OpenCPU and shown in a frame.
async function analysis(page) {
    const frame = page.frameLocator(ANALYSIS);
    await expect(frame.locator('h2').first()).toHaveText('Thank you!', { timeout: 90000 });
    const text = await frame.locator('body').innerText();
    const heading = text.match(/(\d+) of (\d+) indicators are flagged/);
    const rows = await frame.locator('table').first().locator('tbody tr').evaluateAll(
        (trs) => trs.map((tr) => Array.from(tr.cells).map((c) => c.textContent.trim().replace(/\s+/g, ' '))),
    ).catch(() => []);
    return { text, flagged: heading ? Number(heading[1]) : null, of: heading ? Number(heading[2]) : null, rows };
}
const storedRow = (engine, tok) => db.dbQuery(
    `SELECT * FROM \`${db.studyByName(RUNS.dq_example[engine].study).results_table}\` WHERE weekend_text LIKE ${db.sqlStr('%' + tok + '%')} ORDER BY session_id DESC LIMIT 1`,
)[0] || null;

for (const engine of ['v1', 'v2']) {
    test.describe(`data-quality example run, ${engine}`, () => {

        test('a person\'s visit ends on an analysis page without an input flag', async ({ page, baseURL }) => {
            test.setTimeout(300 * 1000);
            const tok = token();
            await visit(page, engine, baseURL, human(page), { tok, label: 'a person filled this in' });

            const shown = await analysis(page);
            expect(shown.text, 'the page is knitted with the formr package').not.toMatch(/could not find function|Error in|object '.*' not found/);
            expect(shown.of, shown.text.slice(0, 400)).toBeGreaterThan(50);
            // Whatever is flagged is about this lane's browser (it is an automated one), not about the input.
            expect(shown.rows.filter((r) => r[0] === 'input').map((r) => r[1])
                .filter((indicator) => !/screen == client/.test(indicator))).toEqual([]);
            expect(shown.text).toContain('it is empty, as it is for every person');
            expect(shown.text).toContain('Your answer to the three statements: No');

            const row = storedRow(engine, tok);
            expect(record(row.dq_p1).keys).toBeGreaterThan(50);
            expect(record(row.dq_p2).v).toBe(2);
            expect(row.dq_agent_p1).toBeNull();
            expect(row.dq_srv_ua).toBeTruthy();
        });

        test('a script\'s visit is flagged there, for its input and for the hidden field', async ({ page, baseURL }) => {
            test.setTimeout(180 * 1000);
            const tok = token();
            await visit(page, engine, baseURL, plain(), { tok, label: 'a script filled this in', probe: 'yes, a language model driven by Playwright', twin: 'Yes' });

            const shown = await analysis(page);
            expect(shown.text).not.toMatch(/could not find function|Error in|object '.*' not found/);
            const flagged = shown.rows.map((r) => r[1]);
            expect(flagged).toContain('agent_probe field filled');
            expect(flagged).toContain('mouse presses / at exact centre');
            expect(flagged).toContain('text answers mostly not typed or pasted (fields)');
            expect(shown.rows.find((r) => r[1] === 'agent_probe field filled')[2]).toBe('yes, a language model driven by Playwright');
            expect(shown.flagged).toBe(shown.rows.length);
            expect(shown.text).toContain('it holds “yes, a language model driven by Playwright”');
            expect(shown.text).toContain('Your answer to the three statements: Yes');

            expect(storedRow(engine, tok).dq_agent_p1).toBe('yes, a language model driven by Playwright');
        });

        // The `server` rows are stored by the server, not by the page: this is the
        // check that those columns reach the page's R as well (run R is handed
        // only the columns it names).
        test('an agent that signs its requests is named there', async ({ browser, baseURL }) => {
            test.setTimeout(180 * 1000);
            const context = await browser.newContext({ ignoreHTTPSErrors: true });
            await context.setExtraHTTPHeaders({ 'Signature-Agent': '"https://chatgpt.com"' });
            const page = await context.newPage();
            const tok = token();
            await visit(page, engine, baseURL, plain(), { tok, label: 'an agent that signs its requests filled this in' });

            const shown = await analysis(page);
            const signed = shown.rows.find((r) => /request signed by an agent/.test(r[1]));
            expect(signed, shown.rows.map((r) => r[1]).join(' | ')).toBeTruthy();
            expect(signed[0]).toBe('environment');
            // The header's value is a quoted string; knitted, its quotes are typographic ones.
            expect(signed[2].replace(/[“”]/g, '"')).toBe('"https://chatgpt.com"');
            expect(storedRow(engine, tok).dq_sig_agent).toBe('"https://chatgpt.com"');
            await context.close();
        });

        test('without consent the run ends at once and nothing of the study is stored', async ({ page, baseURL }) => {
            const study = db.studyByName(RUNS.dq_example[engine].study);
            const before = Number(db.dbQuery(`SELECT COUNT(*) AS n FROM \`${study.results_table}\``)[0].n);
            await freshParticipant(page, runName('dq_example', engine), { baseURL });
            await ready(page, engine);
            // The consent page has no data_quality item: nothing is recorded on it.
            expect(await page.locator('input[data-fmr-dq], .item-agent_probe').count()).toBe(0);
            await page.locator(`${SCOPE[engine]} .item-mc_button .btn[data-for]`).nth(1).click();   // No, I do not agree
            await page.locator(`${SCOPE[engine]} ${NEXT[engine]}`).click();
            await expect(page.locator('body')).toContainText('You did not agree to take part', { timeout: 60000 });
            await expect(page.locator(ANALYSIS)).toHaveCount(0);
            expect(Number(db.dbQuery(`SELECT COUNT(*) AS n FROM \`${study.results_table}\``)[0].n)).toBe(before);
        });
    });
}
