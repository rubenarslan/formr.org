// Input made the way a person makes it, through Playwright's mouse and
// keyboard: the pointer travels to its target along a curve, clicks land off
// centre and are held for a moment, keys are pressed and released one at a
// time with uneven gaps, capitals come with Shift, and pauses fall before
// words. For specs that must NOT look automated (data-quality.spec.js); the
// ordinary locator.click() / fill() are what an off-the-shelf agent does.

const rnd = (a, b) => a + Math.random() * (b - a);

function human(page) {
    let pos = { x: rnd(200, 600), y: rnd(100, 300) };
    const sleep = (ms) => page.waitForTimeout(ms);

    async function moveTo(x, y) {
        const steps = Math.round(rnd(25, 45));
        const cx = (pos.x + x) / 2 + rnd(-80, 80), cy = (pos.y + y) / 2 + rnd(-80, 80);
        for (let i = 1; i <= steps; i++) {
            const t = i / steps, e = t * t * (3 - 2 * t);
            const px = (1 - e) * (1 - e) * pos.x + 2 * (1 - e) * e * cx + e * e * x + rnd(-1.5, 1.5);
            const py = (1 - e) * (1 - e) * pos.y + 2 * (1 - e) * e * cy + e * e * y + rnd(-1.5, 1.5);
            await page.mouse.move(px, py);
            await sleep(rnd(6, 18));
        }
        pos = { x, y };
    }

    // Scroll with the wheel until the target is comfortably in view (an
    // element.scrollIntoView() would be a scroll nobody made). The pointer
    // goes to the page's margin first: over a focused number field the wheel
    // changes the number instead of scrolling (v1's webshim), and the target
    // never arrives.
    async function bringIntoView(locator) {
        const vh = page.viewportSize().height;
        const comfortable = (b) => b && b.y > 60 && b.y + b.height < vh - 60;
        const visible = (b) => b && b.y >= 0 && b.y + b.height <= vh;
        let parked = false;
        for (let i = 0; i < 16; i++) {
            const b = await locator.boundingBox();
            if (comfortable(b)) return;
            if (!parked) {
                await moveTo(rnd(4, 14), rnd(vh * 0.3, vh * 0.6));
                parked = true;
            }
            const before = await page.evaluate(() => window.scrollY);
            await page.mouse.wheel(0, !b || b.y + b.height >= vh - 60 ? 220 : -220);
            await sleep(rnd(60, 140));
            // A page that cannot scroll any further: near the edge is as good as it gets.
            if (before === await page.evaluate(() => window.scrollY) && visible(await locator.boundingBox())) return;
        }
        throw new Error('human: the wheel did not bring the target into view');
    }

    async function click(locator) {
        await bringIntoView(locator);
        await sleep(rnd(250, 500)); // a person sees the target before going for it
        const b = await locator.boundingBox();
        await moveTo(b.x + b.width * rnd(0.2, 0.42), b.y + b.height * rnd(0.25, 0.42));
        await sleep(rnd(80, 250));
        await page.mouse.down();
        await sleep(rnd(60, 140));
        await page.mouse.up();
        await sleep(rnd(300, 700));
    }

    async function key(k, shift) {
        if (shift) { await page.keyboard.down('Shift'); await sleep(rnd(30, 90)); }
        await page.keyboard.down(k);
        await sleep(rnd(50, 120));
        await page.keyboard.up(k);
        if (shift) { await sleep(rnd(20, 60)); await page.keyboard.up('Shift'); }
    }

    // Types into the focused field. Letters, digits, spaces, commas and full
    // stops only: what Playwright maps to a physical key.
    async function type(text) {
        let prev = '';
        for (const ch of text) {
            // people pause before starting a word, not in the middle of one
            await sleep(prev === ' ' && Math.random() < 0.3 ? rnd(520, 900) : rnd(60, 210));
            if (ch === ' ') await key('Space');
            else if (/[A-Z]/.test(ch)) await key('Key' + ch, true);
            else await key(ch);
            prev = ch;
        }
    }

    async function typeInto(locator, text) {
        await click(locator);
        await type(text);
    }

    // On to the next field by keyboard, and into it after the moment it
    // takes to read the question.
    async function tabAndType(text) {
        await key('Tab');
        await sleep(rnd(650, 950));
        await type(text);
    }

    return { click, type, typeInto, tabAndType };
}

// What an off-the-shelf agent does: Playwright's own actions.
function plain() {
    return {
        click: (locator) => locator.click(),
        typeInto: (locator, text) => locator.fill(text),
    };
}

module.exports = { human, plain };
