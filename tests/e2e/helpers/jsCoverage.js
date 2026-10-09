// JS coverage of the app bundles under e2e, for the coverage pass
// (tests/coverage/README.md). Active only when FORMR_JS_COVERAGE names an
// output directory, and only in Chromium (V8 precise coverage).
//
// Raw V8 coverage is a few MB per test for form.bundle.js, so it is reduced
// in the worker: each test's block ranges are painted onto a per-byte
// "ran" array for the script (inner ranges override outer ones, as V8
// nests them), OR-ed into the worker's running union, and the union is
// written after every test as covered intervals plus the function list
// (offset → name). tests/coverage/js-report.mjs maps that onto the sources.

const fs = require('node:fs');
const path = require('node:path');

const OUT = process.env.FORMR_JS_COVERAGE;
// Our bundles only: vendor CDN scripts and inline scripts are not ours.
const KEEP = /\/assets\/(dev-)?build\/js\/[^/?#]+\.js(\?|#|$)/;
const acc = new Map(); // url → { length, ran: Uint8Array, functions: Map(offset → name) }

function absorb(entry) {
    const url = entry.url.replace(/[?#].*$/, '');
    const length = entry.source ? entry.source.length : 0;
    if (!length) return;
    let a = acc.get(url);
    if (!a || a.length !== length) {
        a = { length, ran: new Uint8Array(length), functions: new Map() };
        acc.set(url, a);
    }
    const ranges = [];
    for (const fn of entry.functions) {
        if (fn.ranges.length && !a.functions.has(fn.ranges[0].startOffset)) {
            a.functions.set(fn.ranges[0].startOffset, fn.functionName || '');
        }
        for (const r of fn.ranges) ranges.push(r);
    }
    // Outer first, so nested (smaller) ranges paint over them.
    ranges.sort((x, y) => (y.endOffset - y.startOffset) - (x.endOffset - x.startOffset));
    const cur = new Uint8Array(length);
    for (const r of ranges) cur.fill(r.count > 0 ? 1 : 0, r.startOffset, Math.min(r.endOffset, length));
    for (let i = 0; i < length; i++) if (cur[i]) a.ran[i] = 1;
}

function flush() {
    const out = {};
    for (const [url, a] of acc) {
        const covered = [];
        let start = -1;
        for (let i = 0; i <= a.length; i++) {
            const on = i < a.length && a.ran[i] === 1;
            if (on && start < 0) start = i;
            if (!on && start >= 0) { covered.push([start, i]); start = -1; }
        }
        out[url] = { length: a.length, covered, functions: [...a.functions] };
    }
    fs.mkdirSync(OUT, { recursive: true });
    fs.writeFileSync(path.join(OUT, `worker-${process.pid}.json`), JSON.stringify(out));
}

const fixtures = {
    page: async ({ page, browserName }, use) => {
        if (browserName !== 'chromium') { await use(page); return; }
        await page.coverage.startJSCoverage({ resetOnNavigation: false, reportAnonymousScripts: false });
        await use(page);
        const entries = await page.coverage.stopJSCoverage().catch(() => []);
        for (const e of entries) if (KEEP.test(e.url)) absorb(e);
        flush();
    },
};

module.exports = { enabled: !!OUT, fixtures };
