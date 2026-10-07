// Shared by the data-quality specs: where a page of each engine is and how to
// leave it, finding a participant's stored records (runs.json: `dq` is the
// example survey on each engine, runs e2e-dq-v1 / e2e-dq-v2; the other suites
// are named by their key) and reading them the way the R helpers do.

const db = require('./db');
const v2 = require('./v2Form');
const { V2_PAGE } = require('./widgets');
const RUNS = require('../setup/runs.json');

// The page on screen, and its button to the next one.
const SCOPE = { v1: 'form.main_formr_survey', v2: V2_PAGE };
const NEXT = { v1: '.item-submit button', v2: '[data-fmr-next]' };
// Per engine: when its page is ready, and going on to the next one with the
// given kind of input (helpers/human.js: human() or plain()).
const ENGINES = {
    v1: {
        scope: SCOPE.v1,
        ready: (page) => page.waitForSelector(`${SCOPE.v1} ${NEXT.v1}`),
        // Every page is its own document.
        next: async (page, input) => {
            await Promise.all([
                page.waitForNavigation({ waitUntil: 'load' }),
                input.click(page.locator(`${SCOPE.v1} ${NEXT.v1}`)),
            ]);
        },
    },
    v2: {
        scope: SCOPE.v2,
        ready: (page) => v2.waitForBundle(page),
        next: async (page, input, { last = false } = {}) => {
            const before = await v2.visiblePageNum(page);
            await input.click(page.locator(`${SCOPE.v2} ${NEXT.v2}`));
            if (last) await page.waitForFunction(() => !document.querySelector('form.fmr-form-v2'), null, { timeout: 30000 });
            else await page.waitForFunction((b) => {
                const sec = document.querySelector('form.fmr-form-v2 section.fmr-page:not([hidden])');
                return sec && Number(sec.dataset.fmrPage) !== b;
            }, before, { timeout: 30000 });
        },
    },
};

// Letters only: what a person's keyboard, and the human-input helper, can type.
const token = () => 'tok' + Math.random().toString(36).replace(/[^a-z]/g, '').slice(0, 8);
// A study's results table does not change while the specs run: looked up once.
const tables = new Map();
const resultsTable = (engine, suite = 'dq') => {
    const study = RUNS[suite][engine].study;
    if (!tables.has(study)) tables.set(study, db.studyByName(study).results_table);
    return tables.get(study);
};
// The participant's row, found by the token typed into a text question.
const rowOf = (engine, tok, suite = 'dq', column = 'weekend_text') => db.dbQuery(
    `SELECT * FROM \`${resultsTable(engine, suite)}\` WHERE \`${column}\` LIKE ${db.sqlStr('%' + tok + '%')} ORDER BY session_id DESC LIMIT 1`,
)[0] || null;
const record = (json) => (json ? JSON.parse(json) : null);

// dq_flags() of the formr R package (R/data_quality.R there), behaviour part: the
// indicators summed over the pages' records, with the same thresholds.
function behaviourFlags(records) {
    const s = (k) => records.reduce((a, r) => a + (Number(r[k]) || 0), 0);
    const pd = s('pd'), ins = s('ins_nokey_ch'), tch = s('txt_ch'), kn = s('kn'), keys = s('keys'), mv = s('mv'), scr = s('scr');
    const kcv = Math.min(...records.map((r) => r.kcv).filter((x) => x >= 0), Infinity);
    const checks = {
        untr: s('untr') > 0,
        prog: s('prog') > 0,
        fld_nokey: s('fld_nokey') > 0,
        ins_nokey: ins >= 20 && ins > 0.5 * tch,
        keys_share: tch >= 20 && keys < 0.5 * tch,
        kfast: kn >= 10 && s('kfast') > 0.3 * kn,
        kdwf: kn >= 10 && s('kdwf') > 0.5 * keys,
        kcv: kcv < 0.15,
        klp: s('klp') >= 8 && s('kwc') > 0 && s('klp_ws') / s('klp') < 1.3 * s('kws') / s('kwc'),
        kcap: s('kcap_ns') + s('kcap_nosk') > 0,
        ins_na: s('ins_na') > 0,
        kcode0: s('kcode0') >= 3,
        pd_ctr: pd >= 3 && s('pd_ctr') > 0.5 * pd,
        pd_fewmv: pd >= 3 && s('pd_fewmv') > 0.8 * pd,
        pd_scr: pd >= 1 && s('pd_scr') === pd,
        pd_newview: pd >= 2 && s('pd_newview') > 0.3 * pd,
        mv_mz: mv >= 10 && s('mv_mz') > 0.5 * mv,
        mv_jump: mv > 0 && s('mv_jump') > 0.3 * mv,
        scr_nogest: scr >= 3 && s('scr_nogest') > 0.8 * scr,
        foc_nogest: s('foc_nogest') > 1,
        foc_prog: s('foc_prog') > 0,
        clk_nopt: s('clk_nopt') > 0,
        act: s('act_hid') + s('act_unf') > 0,
        bksp: keys >= 300 && s('bksp') === 0,
        tap_r1: s('tap') >= 3 && s('tap_r1') === s('tap'),
        rsz: s('rsz_bar') + s('rsz_side') > 0,
    };
    return Object.keys(checks).filter((k) => checks[k]);
}
// The checksum the record holds of navigator.userAgent (adler32 in data-quality.js).
function adler32(text) {
    let a = 1, b = 0;
    for (let i = 0; i < text.length; i++) { a = (a + text.charCodeAt(i)) % 65521; b = (b + a) % 65521; }
    return (b * 65536 + a).toString(16);
}

// Counts what a recorder would add to a page: its listeners on the document
// (`copy` is one nothing else in formr listens for), questions about DRM, and
// its wrappers around focus() and scrollIntoView(). Before the page is opened.
async function spy(page) {
    await page.addInitScript(() => {
        const seen = window.__dqSpy = { listeners: 0, drm: 0 };
        const add = EventTarget.prototype.addEventListener;
        EventTarget.prototype.addEventListener = function (type, ...rest) {
            if (this === document && type === 'copy') seen.listeners++;
            return add.call(this, type, ...rest);
        };
        if (navigator.requestMediaKeySystemAccess) {
            const ask = navigator.requestMediaKeySystemAccess.bind(navigator);
            navigator.requestMediaKeySystemAccess = (...args) => { seen.drm++; return ask(...args); };
        }
    });
}
// `native`: neither method is wrapped.
const probeSpy = (page) => page.evaluate(() => ({
    listeners: window.__dqSpy.listeners,
    drm: window.__dqSpy.drm,
    native: [HTMLElement.prototype.focus, Element.prototype.scrollIntoView]
        .every((fn) => /\[native code\]/.test(Function.prototype.toString.call(fn))),
}));

module.exports = { SCOPE, NEXT, ENGINES, token, resultsTable, rowOf, record, adler32, behaviourFlags, spy, probeSpy };
