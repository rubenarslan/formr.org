// all_widgets catalogue coverage (form_v2).
//
// documentation/example_surveys/all_widgets.json is meant to be the complete
// showcase of every item type form_v2 can render. These tests keep it honest:
//
//   1. it's valid and substantial
//   2. it covers EVERY implemented Item class (so adding a new item type without
//      adding it here fails CI — the drift this fixture kept hitting)
//   3. every type it references actually has an implementation
//   4. (browser) the live canonical run renders only catalogued types
//
// Type ↔ class mapping mirrors Item::getItemClass(): the spreadsheet type is
// the snake_case of the class filename, with '-'→'_' folded (so DatetimeLocal
// is reachable as both `datetime-local` and `datetime_local`).

const { test, expect } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v2 = require('./helpers/v2Form');
const fs = require('node:fs');
const path = require('node:path');

const ITEM_DIR = path.resolve(__dirname, '../../application/Model/Item');
const FIXTURE = path.resolve(__dirname, '../../documentation/example_surveys/all_widgets.json');
const ALIAS = { datetime_local: 'datetime-local' };

function camelToSnake(name) {
    return name.replace(/(?<!^)(?=[A-Z])/g, '_').toLowerCase();
}
function implementedTypes() {
    return new Set(
        fs.readdirSync(ITEM_DIR)
            .filter((f) => f.endsWith('.php') && f !== 'Item.php')
            .map((f) => { const t = camelToSnake(f.slice(0, -4)); return ALIAS[t] || t; }),
    );
}
function fixture() {
    const data = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));
    return { data, types: new Set(data.items.map((i) => i.type)) };
}

test.describe('all_widgets catalogue', () => {

    test('fixture is valid and substantial', () => {
        const { data } = fixture();
        expect(Array.isArray(data.items)).toBe(true);
        expect(data.items.length).toBeGreaterThan(50);
        expect(data.settings).toBeTruthy();
        // names must be unique (import would otherwise collide)
        const names = data.items.map((i) => i.name);
        expect(new Set(names).size, 'duplicate item names').toBe(names.length);
    });

    test('covers every implemented item type', () => {
        const missing = [...implementedTypes()].filter((t) => !fixture().types.has(t)).sort();
        expect(
            missing,
            `add these item types to documentation/example_surveys/all_widgets.json: ${missing.join(', ')}`,
        ).toEqual([]);
    });

    test('every catalogued type maps to an implemented Item class', () => {
        const impl = implementedTypes();
        const dangling = [...fixture().types].filter((t) => !impl.has(t)).sort();
        expect(dangling, `fixture references unimplemented types: ${dangling.join(', ')}`).toEqual([]);
    });

    test('canonical e2e-aw-v2 run renders only catalogued item types', async ({ page, baseURL }) => {
        await freshParticipant(page, runName('all_widgets', 'v2'), { baseURL });
        await v2.waitForBundle(page);
        const rendered = await page.evaluate(() => {
            const set = new Set();
            document.querySelectorAll('form.fmr-form-v2 .form-group').forEach((g) => {
                g.classList.forEach((c) => { if (c.startsWith('item-')) set.add(c.slice(5)); });
            });
            return [...set];
        });
        expect(rendered.length, 'too few widget types rendered').toBeGreaterThanOrEqual(8);
        const unknown = rendered.filter((t) => !fixture().types.has(t)).sort();
        expect(unknown, `live run renders types missing from the catalogue: ${unknown.join(', ')}`).toEqual([]);
    });

});
