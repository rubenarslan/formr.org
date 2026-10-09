// data_quality on phones, tablets and the other engines.
//
// The recorder was built and tuned on desktop Chrome. This spec is what runs
// it everywhere else, on BrowserStack (browserstack.data-quality.yml: an
// iPhone and an Android phone, an iPad and an Android tablet, Firefox on
// Windows, WebKit on macOS):
//
//   npm run test:bs:dq
//
// and, as a plain check of the same expectations, on the local Chromium:
//
//   npx playwright test --config tests/e2e/playwright.config.js data-quality-devices
//
// What a device run can and cannot show. Under automation a phone's on-screen
// keyboard does not come up, and taps arrive through the automation bridge,
// not from a finger: how a PERSON's typing and tapping look on a phone needs a
// person. What it does show, on every engine and screen:
//
//   - the record is written and stored, on both engines of formr;
//   - an honest browser is not taken for a lying one: the real engine is
//     recognised (WebKit on every iOS browser, Gecko, Blink), the version
//     check passes, the User-Agent the page reads is the one the server got;
//   - phones and tablets are known as such — an iPad too, which calls itself a
//     Mac — so the checks that need a physical keyboard, a mouse or a window
//     are off there, and no DRM question is asked;
//   - whose code made an event is told apart in this engine's stack format:
//     clicks and input made by a script in the page are counted, formr's own
//     widgets' events are not;
//   - nothing of the hidden field shows, and the page does not scroll
//     sideways on this screen;
//   - on a desktop engine, text typed key by key counts as typed.
//
// Everything is driven from inside the page (helpers/test.js bsSafeEvaluate):
// on real iOS that is the only input the bridge delivers reliably, and it is
// what "a script in the page" means here anyway.

const { test, expect, bsSafeEvaluate } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const { token, rowOf, record, adler32 } = require('./helpers/dataQuality');
const v2 = require('./helpers/v2Form');

// What each platform IS, by the name BrowserStack gives its project (the
// platform of browserstack.data-quality.yml; the `browserName` fixture stays
// the base project's and says chromium everywhere). The test's own knowledge,
// not read from the page: an Android tablet asks for the desktop site and an
// iPad calls itself a Mac, so neither says what it is.
const PLATFORMS = [
    [/iPhone/, { engine: 'webkit', handheld: true }],
    [/iPad/, { engine: 'webkit', handheld: true }],
    [/Pixel|Galaxy/, { engine: 'blink', handheld: true }],
    [/Windows/, { engine: 'gecko', handheld: false }],
    [/OS ?X/, { engine: 'webkit', handheld: false }],
    [/^local-chromium$/, { engine: 'blink', handheld: false }],
];
function platform() {
    const name = test.info().project.name;
    const known = PLATFORMS.find(([rx]) => rx.test(name));
    if (!known) throw new Error(`data-quality-devices: no expectations for the project "${name}" (add it to PLATFORMS)`);
    return known[1];
}
// The visible page: the whole form on the classic engine, one section on v2.
const SCOPE = { v1: 'form.main_formr_survey', v2: 'form.fmr-form-v2 section.fmr-page:not([hidden])' };
const NEXT = { v1: '.item-submit button', v2: '[data-fmr-next]' };

// What the device is, read by the test itself (not from the record).
const device = (page) => bsSafeEvaluate(page, () => ({
    ua: navigator.userAgent,
    touch: navigator.maxTouchPoints || 0,
    coarse: matchMedia('(pointer: coarse)').matches,
    fine: matchMedia('(pointer: fine)').matches,
    anyFine: matchMedia('(any-pointer: fine)').matches,
    hover: matchMedia('(hover: hover)').matches,
    platform: navigator.platform,
    width: window.innerWidth,
    focusNative: /\[native code\]/.test(Function.prototype.toString.call(HTMLElement.prototype.focus)),
}));

async function ready(page, engine) {
    if (engine === 'v2') return v2.waitForBundle(page, { timeout: 30000 });
    return expect.poll(() => bsSafeEvaluate(page, () => !!document.querySelector('form.main_formr_survey .item-submit button')).catch(() => false), { timeout: 30000 }).toBe(true);
}
// Which page is on screen: the number of the v2 section, or on v1 the names of its inputs.
const onPage = (page, engine, marker) => expect.poll(() => bsSafeEvaluate(page, (a) => {
    const scope = document.querySelector(a.scope);
    return !!(scope && scope.querySelector(a.marker));
}, { scope: SCOPE[engine], marker }).catch(() => false), { timeout: 45000 }).toBe(true);   // mid-navigation: not yet

// The page's Next, clicked by a script. On the classic engine the click
// navigates, and the call that made it may not come back.
const scriptNext = (page, engine) => scriptClicks(page, engine, NEXT[engine], 'first').catch(() => 1);

// Clicks made by a script in the page. Returns how many it made.
const scriptClicks = (page, engine, selector, which) => bsSafeEvaluate(page, (a) => {
    const scope = document.querySelector(a.scope);
    const all = Array.from(scope.querySelectorAll(a.selector));
    const targets = a.which === 'each-group'
        ? Array.from(scope.querySelectorAll('.item-mc_button')).map((g) => g.querySelectorAll('.btn[data-for]')[2])
        : [all[0]];
    targets.forEach((el) => el.click());
    return targets.length;
}, { scope: SCOPE[engine], selector, which });

async function toPage2(page, engine, baseURL) {
    await freshParticipant(page, runName('dq', engine), { baseURL });
    await ready(page, engine);
    const before = await device(page);
    await scriptClicks(page, engine, '.item-mc_button .btn[data-for]', 'first');
    await scriptNext(page, engine);
    await onPage(page, engine, 'textarea[name=weekend_text]');
    await ready(page, engine);
    return before;
}

for (const engine of ['v1', 'v2']) {
    test(`${engine}: the device is known for what it is, and a script's input for what it is`, async ({ page, baseURL }) => {
        test.setTimeout(240 * 1000);
        const is = platform();
        // The consent page: no item, nothing added to the page.
        const consentPage = await toPage2(page, engine, baseURL);
        expect(consentPage.focusNative, 'the consent page is left alone').toBe(true);
        const d = await device(page);
        expect(d.focusNative, 'the recorder runs on page 2').toBe(false);

        // Nothing of the hidden field shows, and the page fits the screen.
        const layout = await bsSafeEvaluate(page, (a) => {
            const scope = document.querySelector(a.scope);
            const probe = scope.querySelector('.item-agent_probe');
            const box = probe.getBoundingClientRect();
            return {
                w: box.width, h: box.height, display: getComputedStyle(probe).display,
                recordDisplay: getComputedStyle(scope.querySelector('.item-data_quality')).display,
                note: probe.textContent.indexOf('Survey integrity rule') >= 0,
                sideways: document.documentElement.scrollWidth - window.innerWidth,
            };
        }, { scope: SCOPE[engine] });
        expect(layout.w).toBeLessThanOrEqual(1);
        expect(layout.h).toBeLessThanOrEqual(1);
        expect(layout.display).not.toBe('none');
        expect(layout.note, 'the note is on the page for a machine to read').toBe(true);
        expect(layout.recordDisplay).toBe('none');
        expect(layout.sideways, 'the page does not scroll sideways').toBeLessThanOrEqual(1);

        // A script answers page 2: three ratings, the text, and Next.
        const tok = token();
        let clicks = await scriptClicks(page, engine, '.item-mc_button .btn[data-for]', 'each-group');
        await bsSafeEvaluate(page, (a) => {
            const field = document.querySelector(a.scope).querySelector('textarea[name=weekend_text]');
            field.value = 'A script wrote this sentence, ' + a.tok + '.';
            field.dispatchEvent(new Event('input', { bubbles: true }));
        }, { scope: SCOPE[engine], tok });
        clicks += await scriptNext(page, engine);
        await onPage(page, engine, 'textarea[name=purpose_text]');

        let row = null;
        await expect.poll(() => { row = rowOf(engine, tok); return row && row.dq_p1; }, { timeout: 30000 }).toBeTruthy();
        const rec = record(row.dq_p1);
        const facts = `${test.info().project.name}: ${JSON.stringify(d)}`;
        // One line per device in the run's output: what it said of itself, and what was recorded.
        console.log(`[dq-device] ${engine} ${facts} -> ${JSON.stringify({ eng: rec.eng, eng_ua: rec.eng_ua, mob: rec.mob, ptr: rec.ptr, drm: rec.drm, uac: rec.uac, untr: rec.untr, prog: rec.prog, fld_nokey: rec.fld_nokey, clk_nopt: rec.clk_nopt, ch: [row.dq_ch_platform, row.dq_ch_mobile] })}`);
        expect(rec.v).toBe(2);
        expect(rec.ld).toBe(1);

        // An honest browser: its real engine, and none of the "it lies" flags.
        expect(rec.eng, facts).toBe(is.engine);
        expect(rec.eng_ua, facts).toBe(rec.eng);
        expect(rec.eng_mm, facts).toBe(0);
        expect(rec.ver_mm, facts).toBe(0);
        expect(rec.uac, facts).toBe('');
        expect(rec.chr_nowv, facts).toBe(0);
        expect(rec.uah, 'the page read the User-Agent the server got').toBe(adler32(row.dq_srv_ua));

        // A phone or a tablet is known as one; the DRM question is for desktops only.
        expect(rec.mob, facts).toBe(is.handheld ? 1 : 0);
        expect(rec.ptr, facts).toBe(d.fine ? 'fine' : d.coarse ? 'coarse' : 'none');
        if (is.handheld || is.engine === 'gecko') expect(rec.drm, facts).toBe('na');
        expect(rec.drm).not.toBe('blocked');

        // The script's clicks and its input event are counted, formr's own
        // widgets' events (a button group announcing its change) are not.
        expect(rec.untr, facts).toBe(clicks + 1);
        expect(rec.prog, 'four items answered without a touch').toBe(4);
        expect(rec.fld_nokey, 'text that no key produced').toBe(1);
        expect(rec.keys).toBe(0);
        expect(rec.foc_prog).toBe(0);
    });
}

test('on a desktop engine, text typed key by key counts as typed', async ({ page, baseURL }) => {
    // A phone's keyboard does not come up under automation: nothing to learn there.
    test.skip(platform().handheld, 'needs a physical keyboard');
    await toPage2(page, 'v2', baseURL);

    const tok = token();
    const text = `i went to the lake with two friends, ${tok}.`;
    const scope = page.locator(SCOPE.v2);
    for (let i = 0; i < 3; i++) await scope.locator('.item-mc_button').nth(i).locator('.btn[data-for]').nth(1).click();
    await scope.locator('textarea[name=weekend_text]').click();
    await page.keyboard.type(text, { delay: 60 });
    await scope.locator(NEXT.v2).click();
    await onPage(page, 'v2', 'textarea[name=purpose_text]');

    let row = null;
    await expect.poll(() => { row = rowOf('v2', tok); return row && row.dq_p1; }, { timeout: 30000 }).toBeTruthy();
    const rec = record(row.dq_p1);
    expect(rec.keys).toBeGreaterThanOrEqual(text.length);
    expect(rec.fld_nokey).toBe(0);
    expect(rec.ins_nokey).toBe(0);
    expect(rec.kfast).toBe(0);
    // Real clicks and real keys: nothing script-made, nothing answered without a touch.
    expect(rec.untr).toBe(0);
    expect(rec.prog).toBe(0);
    expect(rec.pd + rec.tap).toBeGreaterThanOrEqual(5);
});
