// data_quality: what people do with ordinary items is not what an agent
// does, and a row switched off by its showif stays off. Both engines.
//
// Fixtures (setup/create-v2-fixtures.js):
//   e2e-dqitems-v1 / -v2   setup/e2e_dq_items.csv. One page of ordinary
//       items beside a data_quality row: radio buttons with labels, a
//       checkbox, a select_or_add list and ten short text fields. The
//       studies are named e2e_dq_chatgpt_v1 / _v2 and one item has the class
//       `manuscript`: names of the survey's own that hold a tool's name.
//   e2e-dqshowif-v1 / -v2  setup/e2e_dq_showif.csv. A data_quality row whose
//       showif reads an answer of its own page.

const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const { human } = require('./helpers/human');
const { ENGINES, token, rowOf, record, behaviourFlags, spy, probeSpy } = require('./helpers/dataQuality');

// Local Chromium only: it judges input made through Playwright's mouse and keyboard.
test.skip(RUNNING_ON_BS, 'local Chromium only; the device lane is data-quality-devices.spec.js');

// The fields of e2e_dq_items.csv that a person fills in, one word or two each.
const WORDS = ['katharina', 'new york', 'teacher', 'german', 'cycling', 'lisbon', 'tomatoes', 'october', 'piano'];

for (const engine of ['v1', 'v2']) {
    test.describe(`data quality, ${engine}: ordinary items`, () => {
        const E = ENGINES[engine];

        // Open the list of a select_or_add item and take a choice from it, by mouse.
        async function choose(page, input, label) {
            const item = page.locator(`${E.scope} .item-select_or_add_one`);
            if (engine === 'v2') {
                await input.click(item.locator('.ts-control'));
                await input.click(item.locator('.ts-dropdown .option', { hasText: label }));
            } else {
                await input.click(item.locator('.select2-container'));
                await input.click(page.locator('.select2-drop-active .select2-result-label', { hasText: label }));
            }
        }

        test('label clicks, arrow keys, a choice from a list and short fields raise no flag', async ({ page, baseURL }) => {
            test.setTimeout(240 * 1000);
            const input = human(page), tok = token();
            await freshParticipant(page, runName('dq_items', engine), { baseURL });
            await E.ready(page);
            const scope = page.locator(E.scope);

            // The text of an option, not its button: the browser passes the
            // click on to the radio button. Then the arrow key, as someone
            // who answers by keyboard does.
            await input.click(scope.locator('.item-mc label.radio-inline .mc-span').first());
            await page.keyboard.press('ArrowRight', { delay: 70 });
            await page.waitForTimeout(400);
            // The question of a checkbox is its label.
            await input.click(scope.locator('.item-check label.control-label'));
            await choose(page, input, 'Federal Republic of Germany');
            // Ten short fields: into the first by mouse, on to each next one
            // with the Tab key and a moment to read its question.
            await input.typeInto(scope.locator('input[name=it_w1]'), WORDS[0]);
            for (const word of WORDS.slice(1).concat([tok])) {
                await input.tabAndType(word);
            }
            await E.next(page, input);
            await E.ready(page);

            let row = null;
            await expect.poll(() => { row = rowOf(engine, tok, 'dq_items', 'it_w10'); return row && row.it_dq; }, { timeout: 15000 }).toBeTruthy();
            expect(row.it_country).toBe('Federal Republic of Germany');
            expect(Number(row.it_agree)).toBe(1);
            const rec = record(row.it_dq);
            // The click a label passes on and the one an arrow key makes have no pointer, and are the browser's.
            expect(rec.clk_nopt, 'clicks without a pointer').toBe(0);
            // The study is named after a tool and an item has such a class: the survey's own names.
            expect(rec.ext, 'tool names found on the page').toBe('');
            // The list wrote its choice into the item's text input; nobody typed or pasted it.
            expect(rec.fld_nokey, 'text answers without keys').toBe(0);
            expect(rec.prog, 'answers nobody touched').toBe(0);
            // Nine long pauses, each before the first key in the next field: pauses before a word.
            expect(rec.klp, 'long pauses').toBeGreaterThanOrEqual(9);
            expect(rec.klp_ws, 'of which before a word').toBeGreaterThanOrEqual(9);
            // pd_scr is left out, as in data-quality.spec.js: this lane's browser has no window frame.
            expect(behaviourFlags([rec]).filter((f) => f !== 'pd_scr'), JSON.stringify(rec)).toEqual([]);
        });
    });
}

// Runs e2e-dqshowif-v1 / -v2 (setup/e2e_dq_showif.csv): the page's
// data_quality row has the showif `sw_on == 1`, and `sw_on` is asked on the
// same page. Hidden by its showif the row is switched off, like any withdrawn
// item (its input is disabled and nothing of it is posted): then nothing is
// read from the device either.
for (const engine of ['v1', 'v2']) {
    test.describe(`data quality, ${engine}: a row with a showif`, () => {
        const E = ENGINES[engine];
        const OFF = { listeners: 0, drm: 0, native: true };

        async function open(page, baseURL) {
            await spy(page);
            await freshParticipant(page, runName('dq_showif', engine), { baseURL });
            await E.ready(page);
        }
        const answer = (page, input, nth) => input.click(page.locator(`${E.scope} .item-mc_button .btn[data-for]`).nth(nth));
        const stored = async (tok) => {
            let row = null;
            await expect.poll(() => { row = rowOf(engine, tok, 'dq_showif', 'sw_text'); return !!row; }, { timeout: 15000 }).toBe(true);
            return row;
        };

        test('records nothing while its showif hides it', async ({ page, baseURL }) => {
            const input = human(page), tok = token();
            await open(page, baseURL);
            await expect(page.locator(`${E.scope} input[data-fmr-dq]`)).toBeDisabled();
            await page.waitForTimeout(1500);
            expect(await probeSpy(page)).toEqual(OFF);

            await answer(page, input, 1);   // "no"
            await input.typeInto(page.locator(`${E.scope} input[name=sw_text]`), tok);
            await page.waitForTimeout(700);
            expect(await probeSpy(page), 'still off').toEqual(OFF);
            await E.next(page, input);
            await E.ready(page);
            expect((await stored(tok)).sw_dq).toBeNull();
        });

        test('records from the moment its showif shows it', async ({ page, baseURL }) => {
            const input = human(page), tok = token();
            await open(page, baseURL);
            await page.waitForTimeout(800);
            expect(await probeSpy(page)).toEqual(OFF);

            await answer(page, input, 0);   // "yes"
            await expect(page.locator(`${E.scope} input[data-fmr-dq]`)).toBeEnabled();
            await expect.poll(async () => (await probeSpy(page)).listeners).toBeGreaterThan(0);
            expect((await probeSpy(page)).native).toBe(false);
            await input.typeInto(page.locator(`${E.scope} input[name=sw_text]`), tok);
            await E.next(page, input);
            await E.ready(page);
            const rec = record((await stored(tok)).sw_dq);
            expect(rec.v).toBe(2);
            expect(rec.ld).toBe(1);
            // What was typed after the row came on is in its record.
            expect(rec.keys).toBeGreaterThanOrEqual(tok.length);
        });
    });
}
