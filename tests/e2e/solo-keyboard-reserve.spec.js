// Solo layout — soft-keyboard reservation for text-entry steps (real device).
//
// Validates the keyboard-reservation feature: on a touch device, a text-entry
// solo step is fit against the screen height MINUS a keyboard-height guess
// (`--fmr-kbd-reserve`, set at seat time, shortening the step's min-height), so
// the question + field are already sized for the room left once the soft
// keyboard rises — no shrink on focus.
//
// Runs under `npm run test:bs:keyboard` across a spread of iOS device sizes
// (browserstack.keyboard.yml: small iPhone SE → large Pro Max → iPad tablet)
// so the screenshots show the textarea step on a variety of mobiles. Also runs
// locally under `npm run test:e2e` (local Chromium reports a fine pointer, so
// the reservation is correctly absent there — the test asserts conditionally).
//
// Screenshots (before-focus = reserved/idle, after-focus = keyboard up) land in
// tests/e2e/artifacts/solo-keyboard-reserve/ and attach to the HTML report.

const fs = require('node:fs');
const path = require('node:path');
const { test, expect, bsSafeEvaluate, clearBrowserState } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v2 = require('./helpers/v2Form');
const { currentStep, advanceFn } = require('./helpers/solo');

const SHOT_DIR = path.resolve(__dirname, 'artifacts', 'solo-keyboard-reserve');
// Tiny TEXT-ONLY solo fixture (tests/e2e/setup/create-text-fixture.sh): a static
// study (no DB layout-toggling) that makes ZERO OpenCPU calls, so BS device runs
// don't render the plot-heavy all_widgets and aren't flaked by OpenCPU load.
const RUN = () => runName('text_solo', 'v2');

// Reach the textarea step WITHOUT focusing anything (advanceFn sets .value
// programmatically — no focus — then clicks OK), so the idle screenshot shows
// the reserved layout. A fresh participant starts a few items in, so we walk.
async function seatTextarea(page) {
    for (let i = 0; i < 8; i++) {
        const info = await currentStep(page);
        if (!info || info.idx < 0) return null;
        if (info.type === 'item-textarea') return info;
        await bsSafeEvaluate(page, advanceFn);
        await page.waitForTimeout(700);
    }
    return null;
}

test.describe('solo layout: keyboard reservation on the textarea step', () => {
    test.beforeEach(async ({ page }) => clearBrowserState(page));

    test('textarea step reserves keyboard room and stays above the keyboard (with screenshots)', async ({ page, baseURL }, testInfo) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        // Let any service-worker-driven reload settle before we touch the page —
        // a reload mid-test detaches the execution context and the next
        // page.evaluate hangs until the BS automation socket goes idle.
        await page.waitForTimeout(1500);
        fs.mkdirSync(SHOT_DIR, { recursive: true });

        const device = (testInfo.project.name || 'device').replace(/[^\w.-]+/g, '_');

        const seated = await seatTextarea(page);
        expect(seated, 'expected to reach the textarea solo step').not.toBeNull();

        // (1) Capture the seated textarea step first — a screenshot is resilient
        // where a flaky BS socket later stalls a page.evaluate, so the artifact
        // lands even if the measurement below times out.
        const p1 = await page.screenshot({ path: path.join(SHOT_DIR, `${device}-textarea-1-idle.png`) }).catch(() => null);
        if (p1) await testInfo.attach(`${device}-1-idle`, { body: p1, contentType: 'image/png' });

        // Read the reservation state.
        const before = await bsSafeEvaluate(page, () => {
            const cur = document.querySelector('.fmr-solo-current');
            const cs = getComputedStyle(cur);
            const vv = window.visualViewport || { height: window.innerHeight };
            return {
                item: (cur.className.match(/item-[a-z_0-9]+/) || ['?'])[0],
                coarsePointer: window.matchMedia('(pointer: coarse)').matches,
                reserve: Math.round(parseFloat(cs.getPropertyValue('--fmr-kbd-reserve')) || 0),
                minHeight: Math.round(parseFloat(cs.minHeight)),
                fit: cs.getPropertyValue('--fmr-solo-fit').trim(),
                innerH: window.innerHeight,
                vvH: Math.round(vv.height),
                // Diagnostic: did this render hit an OpenCPU outage? (Not a showif
                // bug — surfaces when OpenCPU is down/restarting, e.g. under load.)
                openCpuError: /problem evaluating showifs|computational error/i.test(document.body.innerText || ''),
            };
        });

        expect(before.item, 'fixture should seat the textarea first').toBe('item-textarea');
        // On a real touch device (coarse pointer) the reservation must engage and
        // shorten the step's box; on a fine-pointer desktop it must NOT (reserve 0).
        if (before.coarsePointer) {
            expect(before.reserve, 'touch + text step → keyboard reserved').toBeGreaterThan(0);
            expect(before.minHeight, 'min-height shortened by the reserve').toBeLessThan(before.innerH);
        } else {
            expect(before.reserve, 'fine pointer → no reservation').toBe(0);
        }

        // (2) Tap the textarea → the soft keyboard rises. Capture it and check the
        // field stays fully above the keyboard (the reservation's whole point).
        const ta = page.locator('.fmr-solo-current textarea').first();
        try { await ta.tap({ timeout: 5000 }); } catch { await ta.click({ timeout: 5000 }).catch(() => {}); }
        await page.waitForTimeout(1300); // keyboard rise animation

        const after = await bsSafeEvaluate(page, () => {
            const cur = document.querySelector('.fmr-solo-current');
            const ta = cur.querySelector('textarea');
            const r = ta.getBoundingClientRect();
            const vv = window.visualViewport || { height: window.innerHeight, offsetTop: 0 };
            return {
                vvH: Math.round(vv.height),
                keyboardPx: Math.round(Math.max(0, window.innerHeight - (vv.height + vv.offsetTop))),
                taBottom: Math.round(r.bottom),
                aboveKeyboard: r.bottom <= vv.height + 1,
            };
        });
        const p2 = await page.screenshot({ path: path.join(SHOT_DIR, `${device}-textarea-2-keyboard.png`) }).catch(() => null);
        if (p2) await testInfo.attach(`${device}-2-keyboard`, { body: p2, contentType: 'image/png' });

        const delta = before.vvH - after.vvH;
        // eslint-disable-next-line no-console
        console.log(`[kbd-reserve][${device}] coarse=${before.coarsePointer} reserve=${before.reserve}px `
            + `min-h=${before.minHeight}/${before.innerH} fit=${before.fit} openCpuError=${before.openCpuError} | on focus: vvΔ=${delta} `
            + `actualKbd≈${after.keyboardPx}px taBottom=${after.taBottom} vvH=${after.vvH} above=${after.aboveKeyboard}`);
        testInfo.annotations.push({
            type: 'kbd-reserve',
            description: `reserve=${before.reserve}px actualKbd≈${after.keyboardPx}px vvΔ=${delta} aboveKeyboard=${after.aboveKeyboard}`,
        });

        // Only assert the above-keyboard guarantee when the keyboard demonstrably
        // rose (tooling can't always raise it through the automation bridge).
        if (delta > 120) {
            expect(after.aboveKeyboard, 'with the keyboard up, the textarea must stay above it').toBe(true);
        }
    });
});
