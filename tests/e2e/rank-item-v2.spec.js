// rank item (form_v2) — Qualtrics-style drag-and-drop rank order.
//
// Covers the item's participant-facing surface end to end:
//   - render: rows in authored order, one hidden `name[]` input per row whose
//     DOM order IS the submitted order; position badges; ▲/▼ buttons disabled
//     at the ends
//   - mouse drag (page.mouse down → move → up) reorders rows and the hidden
//     inputs follow; the value stored in the results table is the ", "-joined
//     key order
//   - ▲/▼ buttons and keyboard (ArrowUp/Down, Home/End on a focused row) move
//     rows and renumber the badges
//   - `random`: the start order differs across fresh loads, every key present
//   - `touch`: hidden inputs start empty; a required rank blocks the client
//     submit with "Please rank the options to continue."; a click on a row
//     (tap) counts as touching and the authored order is then stored
//   - a11y: rows are focusable with a live-region announcement on move
//   - no console errors along the way
//
// Fixture: persistent public run `e2e-rank-v2` → Form unit (study `e2e_rank`:
// page 1 = rank_plain[required] + rank_random[optional, random]; page 2 =
// rank_touch[required, touch]) → Stop. See tests/e2e/setup/runbook.md
// ("Fixture: rank"). Server-side option parsing / validation / markup is
// unit-tested in tests/RankItemTest.php.

const { test, expect } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v2 = require('./helpers/v2Form');
const db = require('./helpers/db');

const RUN = () => runName('rank', 'v2');
const STUDY = 'e2e_rank';
const PLAIN = 'rank_plain';
const RANDOM = 'rank_random';
const TOUCH = 'rank_touch';
const PLAIN_LABELS = ['Apple', 'Banana', 'Cherry', 'Date', 'Elderberry'];

function resultsTable() {
    // survey_studies.results_table, not s<id>_<name>: a renamed study keeps
    // the table it was created with.
    const study = db.studyByName(STUDY);
    if (!study) throw new Error(`study ${STUDY} not found — run the runbook to provision e2e-rank-v2`);
    return study.results_table;
}

// The form carries its unit-session id in a hidden input; the results table
// keys rows by that same id, so a test can find *its own* participant's row
// without a marker text item.
async function unitSessionId(page) {
    const id = await v2.unitSessionId(page);
    expect(id, 'form exposes its unit session id').toBeTruthy();
    return Number(id);
}

function storedValues(sessionId) {
    const rows = db.dbQuery(
        `SELECT ${PLAIN}, ${RANDOM}, ${TOUCH} FROM \`${resultsTable()}\` WHERE session_id = ${Number(sessionId)} LIMIT 1`,
    );
    return rows[0] || null;
}

// Snapshot of one rank box as the test sees it: row keys in DOM order, the
// hidden inputs in DOM order, the position badges, and the touched flag.
async function rankState(page, name) {
    return page.evaluate((n) => {
        const box = document.querySelector(`input[name="${n}[]"]`)?.closest('.fmr-rank');
        if (!box) return null;
        const rows = [...box.querySelectorAll(':scope > .fmr-rank-list > .fmr-rank-item')];
        return {
            rows: rows.map((r) => r.dataset.value),
            labels: rows.map((r) => r.querySelector('.fmr-rank-label')?.textContent.trim()),
            inputs: [...box.querySelectorAll(`input[type=hidden][name="${n}[]"]`)].map((i) => i.value),
            badges: rows.map((r) => r.querySelector('.fmr-rank-pos')?.textContent.trim()),
            upDisabled: rows.map((r) => r.querySelector('.fmr-rank-up')?.disabled),
            downDisabled: rows.map((r) => r.querySelector('.fmr-rank-down')?.disabled),
            touched: box.classList.contains('fmr-rank-touched'),
            touchMode: box.dataset.rankTouch === '1',
            focusable: rows.every((r) => r.getAttribute('tabindex') === '0'),
            hint: box.querySelector('[id$="-rank-hint"]')?.textContent.trim() || '',
            status: box.querySelector('.fmr-rank-status')?.textContent.trim() || '',
        };
    }, name);
}

function row(page, name, key) {
    return page.locator(`.fmr-rank:has(> .fmr-rank-list input[name="${name}[]"]) > .fmr-rank-list > .fmr-rank-item[data-value="${key}"]`);
}

// Drag a row by its handle to the vertical centre of another row, in steps so
// the widget's pointermove reorder + FLIP logic runs the way a real pointer
// drives it (a single jump would skip the threshold/hold-timer paths).
async function dragRow(page, name, fromKey, toKey) {
    const handle = row(page, name, fromKey).locator('.fmr-rank-handle');
    const from = await handle.boundingBox();
    const to = await row(page, name, toKey).boundingBox();
    expect(from && to, 'both rows are on screen').toBeTruthy();
    const x = from.x + from.width / 2;
    await page.mouse.move(x, from.y + from.height / 2);
    await page.mouse.down();
    const targetY = to.y + to.height / 2 + (to.y > from.y ? 2 : -2);
    await page.mouse.move(x, targetY, { steps: 12 });
    await page.mouse.up();
}

// v2 renders every page section up front and toggles `hidden`, so wait for
// the section to become the visible one rather than for its (hidden) inputs.
async function waitForPage(page, n) {
    await page.waitForFunction(
        (num) => document.querySelector('form.fmr-form-v2 section.fmr-page:not([hidden])')?.getAttribute('data-fmr-page') === String(num),
        n,
    );
}

function collectConsoleErrors(page) {
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    return errors;
}

test.describe('rank item (v2, drag-and-drop)', () => {

    test('renders rows in authored order with hidden name[] inputs that mirror the DOM', async ({ page, baseURL }) => {
        const errors = collectConsoleErrors(page);
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);

        const plain = await rankState(page, PLAIN);
        expect(plain).not.toBeNull();
        expect(plain.rows).toEqual(['1', '2', '3', '4', '5']);
        expect(plain.labels).toEqual(PLAIN_LABELS);
        expect(plain.inputs).toEqual(['1', '2', '3', '4', '5']); // no `touch`: filled from the start
        expect(plain.badges).toEqual(['1', '2', '3', '4', '5']);
        expect(plain.upDisabled).toEqual([true, false, false, false, false]);
        expect(plain.downDisabled).toEqual([false, false, false, false, true]);
        expect(plain.touched).toBe(true);
        expect(plain.touchMode).toBe(false);
        expect(plain.focusable).toBe(true);
        expect(plain.hint).toMatch(/drag|arrow/i);

        // The optional `random` item is on the same page: six colours, all present.
        const random = await rankState(page, RANDOM);
        expect([...random.rows].sort()).toEqual(['blue', 'green', 'orange', 'purple', 'red', 'yellow']);
        expect(random.inputs).toEqual(random.rows);
        expect(errors).toEqual([]);
    });

    test('a mouse drag reorders the rows and the joined order is stored', async ({ page, baseURL }) => {
        const errors = collectConsoleErrors(page);
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        const sid = await unitSessionId(page);

        // Elderberry (5) to the top, then Apple (1, now second) to the bottom.
        await dragRow(page, PLAIN, '5', '1');
        let s = await rankState(page, PLAIN);
        expect(s.rows).toEqual(['5', '1', '2', '3', '4']);
        expect(s.inputs).toEqual(['5', '1', '2', '3', '4']);
        expect(s.badges).toEqual(['1', '2', '3', '4', '5']);
        expect(s.status).toMatch(/Elderberry.*position 1 of 5/);

        await dragRow(page, PLAIN, '1', '4');
        s = await rankState(page, PLAIN);
        expect(s.rows).toEqual(['5', '2', '3', '4', '1']);
        expect(s.inputs).toEqual(['5', '2', '3', '4', '1']);
        // No drag artefacts left behind.
        expect(await page.locator('.fmr-rank-placeholder, .fmr-rank-dragging').count()).toBe(0);

        const res = await v2.submitV2(page);
        expect(res.blockedByClient).toBe(false);
        expect(res.status).toBe(200);
        expect(res.body && res.body.status).toBe('ok');
        expect(v2.advanced(res.body), JSON.stringify(res.body)).toBeTruthy();

        const stored = storedValues(sid);
        expect(stored).not.toBeNull();
        expect(stored[PLAIN]).toBe('5, 2, 3, 4, 1');
        // Optional random item untouched by the participant: its start order is
        // still a complete ranking and is stored as shown.
        expect(stored[RANDOM].split(', ').sort()).toEqual(['blue', 'green', 'orange', 'purple', 'red', 'yellow']);
        expect(errors).toEqual([]);
    });

    test('▲/▼ buttons and the keyboard move rows and renumber the badges', async ({ page, baseURL }) => {
        const errors = collectConsoleErrors(page);
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);

        // Button: move Cherry (3) up once.
        await row(page, PLAIN, '3').locator('.fmr-rank-up').click();
        let s = await rankState(page, PLAIN);
        expect(s.rows).toEqual(['1', '3', '2', '4', '5']);
        expect(s.badges).toEqual(['1', '2', '3', '4', '5']);
        expect(s.status).toMatch(/Cherry.*position 2 of 5/);

        // Button: move Date (4) down once → last; its ▼ is then disabled.
        await row(page, PLAIN, '4').locator('.fmr-rank-down').click();
        s = await rankState(page, PLAIN);
        expect(s.rows).toEqual(['1', '3', '2', '5', '4']);
        expect(s.downDisabled[4]).toBe(true);

        // Keyboard on a focused row: ArrowUp ×2 brings Banana (2) to the top,
        // End sends it to the bottom, Home brings it back to the top.
        const banana = row(page, PLAIN, '2');
        await banana.focus();
        await page.keyboard.press('ArrowUp');
        await page.keyboard.press('ArrowUp');
        s = await rankState(page, PLAIN);
        expect(s.rows).toEqual(['2', '1', '3', '5', '4']);
        expect(await page.evaluate(() => document.activeElement?.dataset.value)).toBe('2'); // focus follows the row
        await page.keyboard.press('End');
        s = await rankState(page, PLAIN);
        expect(s.rows).toEqual(['1', '3', '5', '4', '2']);
        await page.keyboard.press('Home');
        await page.keyboard.press('ArrowDown');
        s = await rankState(page, PLAIN);
        expect(s.rows).toEqual(['1', '2', '3', '5', '4']);
        expect(s.inputs).toEqual(s.rows);
        expect(s.badges).toEqual(['1', '2', '3', '4', '5']);
        expect(errors).toEqual([]);
    });

    test('`random` shuffles the start order between fresh loads', async ({ page, baseURL }) => {
        // Six options have 720 orderings; eight fresh loads all landing on the
        // same one is a ~1e-17 event, so a repeat means the shuffle is off.
        const seen = new Set();
        for (let i = 0; i < 8 && seen.size < 2; i++) {
            await freshParticipant(page, RUN(), { baseURL });
            await v2.waitForBundle(page);
            const s = await rankState(page, RANDOM);
            expect([...s.rows].sort()).toEqual(['blue', 'green', 'orange', 'purple', 'red', 'yellow']);
            expect(s.inputs).toEqual(s.rows);
            seen.add(s.rows.join(','));
        }
        expect(seen.size).toBeGreaterThan(1);
        // …while the plain item always starts in authored order.
        expect((await rankState(page, PLAIN)).rows).toEqual(['1', '2', '3', '4', '5']);
    });

    test('`touch` leaves the inputs empty and blocks a required submit until the list is touched', async ({ page, baseURL }) => {
        const errors = collectConsoleErrors(page);
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        const sid = await unitSessionId(page);

        // Page 1 as-is (plain is pre-filled, random optional) → page 2.
        let res = await v2.submitV2(page);
        expect(res.blockedByClient).toBe(false);
        expect(v2.advanced(res.body), JSON.stringify(res.body)).toBeTruthy();
        await waitForPage(page, 2);

        let s = await rankState(page, TOUCH);
        expect(s.touchMode).toBe(true);
        expect(s.touched).toBe(false);
        expect(s.rows).toEqual(['1', '2', '3', '4']);
        expect(s.inputs).toEqual(['', '', '', '']);

        // Untouched + required: the client gate stops the POST with the rank message.
        res = await v2.submitV2(page, { timeout: 4000 });
        expect(res.blockedByClient).toBe(true);
        expect(await v2.visiblePageNum(page)).toBe(2);
        const msgs = await v2.errorMessages(page);
        expect(msgs.join(' ')).toMatch(/rank the options/i);

        // A tap on a row — no reorder — is the participant saying "this order".
        await row(page, TOUCH, '3').click();
        s = await rankState(page, TOUCH);
        expect(s.touched).toBe(true);
        expect(s.inputs).toEqual(['1', '2', '3', '4']);
        expect(await page.locator('.fmr-rank-item.fmr-rank-dragging').count()).toBe(0);

        res = await v2.submitV2(page);
        expect(res.blockedByClient).toBe(false);
        expect(res.status).toBe(200);
        expect(res.body && res.body.status).toBe('ok');

        const stored = storedValues(sid);
        expect(stored[TOUCH]).toBe('1, 2, 3, 4');
        expect(stored[PLAIN]).toBe('1, 2, 3, 4, 5');
        expect(errors).toEqual([]);
    });

    test('`touch` is also satisfied by a keyboard move, and the moved order is stored', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        const sid = await unitSessionId(page);
        let res = await v2.submitV2(page);
        expect(v2.advanced(res.body), JSON.stringify(res.body)).toBeTruthy();
        await waitForPage(page, 2);

        await row(page, TOUCH, '4').focus();
        await page.keyboard.press('Home');
        const s = await rankState(page, TOUCH);
        expect(s.rows).toEqual(['4', '1', '2', '3']);
        expect(s.inputs).toEqual(['4', '1', '2', '3']);
        expect(s.touched).toBe(true);
        expect(s.status).toMatch(/Rabbit.*position 1 of 4/);

        res = await v2.submitV2(page);
        expect(res.blockedByClient).toBe(false);
        expect(res.body && res.body.status).toBe('ok');
        expect(storedValues(sid)[TOUCH]).toBe('4, 1, 2, 3');
    });
});
