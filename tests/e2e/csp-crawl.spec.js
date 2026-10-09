// CSP violation sweep: drives the admin UI (Report-Only CSP must be live on
// the target instance) and collects securitypolicyviolation events across the
// admin route surface, then writes a triage report to artifacts/.
//
//   npx playwright test --config tests/e2e/playwright.config.js csp-crawl
//
// Read-only: it discovers an existing run/survey name from the list pages
// rather than creating fixtures, so it never mutates the shared dev instance.
// Auth via the cached admin storage state (helpers/admin.js).
//
// Dev note: the dev instance serves the dev-build/ webpack bundles, which use
// eval() — those produce script-src 'eval' violations that DO NOT exist in the
// prod build/. They are classified as `devBuildEval` and reported separately.

const { test } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { ADMIN_BASE, STATE_PATH, ensureAdminState } = require('./helpers/admin');
const ROUTES = require('./fixtures/admin-routes');

const ARTIFACTS = path.resolve(__dirname, 'artifacts');
const CONCURRENCY = 4;

// Injected before any page script so it catches load-time violations.
const VIOLATION_HOOK = () => {
    window.__cspViolations = [];
    document.addEventListener('securitypolicyviolation', (e) => {
        window.__cspViolations.push({
            effectiveDirective: e.effectiveDirective || e.violatedDirective,
            blockedURI: e.blockedURI,
            sourceFile: e.sourceFile,
            lineNumber: e.lineNumber,
            columnNumber: e.columnNumber,
            sample: e.sample,
            disposition: e.disposition,
        });
    });
};

// Noise to ignore in console/pageerror capture. `unsafe-eval` / "Evaluating a
// string as JavaScript" are the dev-build webpack eval() messages — absent in
// the prod build/ bundle, so not prod-relevant (the securitypolicyviolation
// events for them are also classified as devBuildEval and excluded).
const NOISE = /xdebug|Deprecated:|Notice:|^Warning:|favicon|net::ERR_ABORTED|unsafe-eval|Evaluating a string as JavaScript/i;

// Discover a real run + survey name from the list pages (read-only).
async function discover(page) {
    const found = { run: null, survey: null };
    for (const [kind, listUrl, re] of [
        ['run', '/admin/run', /\/admin\/run\/([A-Za-z][A-Za-z0-9_]{2,64})(?:[\/?#]|$)/],
        ['survey', '/admin/survey', /\/admin\/survey\/([A-Za-z][A-Za-z0-9_]{2,64})(?:[\/?#]|$)/],
    ]) {
        try {
            await page.goto(ADMIN_BASE + listUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
            const hrefs = await page.$$eval('a[href]', (as) => as.map((a) => a.getAttribute('href')));
            const skip = new Set(['add_run', 'add_survey']);
            for (const h of hrefs) {
                const m = h && h.match(re);
                if (m && !skip.has(m[1])) { found[kind] = m[1]; break; }
            }
        } catch (e) { /* leave null */ }
    }
    return found;
}

// Build the concrete URL list, dropping templated entries we have no name for.
function buildUrls(names) {
    const out = [];
    for (const r of ROUTES) {
        if (r.needs && !names[r.needs]) continue;
        const p = r.path.replace('{run}', names.run || '').replace('{survey}', names.survey || '');
        out.push(ADMIN_BASE + p);
    }
    return out;
}

// Visit one URL in its own page, return { url, status, violations[], errors[] }.
async function visit(context, url) {
    const rec = { url, status: null, violations: [], errors: [] };
    const page = await context.newPage();
    page.on('pageerror', (err) => { if (!NOISE.test(String(err))) rec.errors.push(String(err)); });
    page.on('console', (msg) => {
        const t = msg.text();
        if (/content security policy|refused to (load|execute|connect)/i.test(t) && !NOISE.test(t)) {
            rec.errors.push('[console] ' + t);
        }
    });
    try {
        const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
        rec.status = resp ? resp.status() : null;
        await page.waitForTimeout(800); // let async scripts/iframes trip violations
        rec.violations = await page.evaluate(() => window.__cspViolations || []);
    } catch (e) {
        rec.errors.push('[nav] ' + String(e.message || e));
    } finally {
        await page.close();
    }
    return rec;
}

// dev-build webpack eval() — absent in the prod build/ bundle.
function isDevBuildEval(v) {
    return v.blockedURI === 'eval' ||
        (/script-src/.test(v.effectiveDirective || '') && /eval/i.test(v.blockedURI || ''));
}

function dedupeKey(v) {
    return [v.effectiveDirective, v.blockedURI, v.sourceFile].join('|');
}

function writeReports(rows, names) {
    fs.mkdirSync(ARTIFACTS, { recursive: true });
    const flat = [];
    for (const r of rows) {
        for (const v of r.violations) flat.push({ documentURI: r.url, ...v, devBuildEval: isDevBuildEval(v) });
    }
    const real = flat.filter((v) => !v.devBuildEval);
    const devEval = flat.filter((v) => v.devBuildEval);

    // dedupe + count by (directive, blockedURI, sourceFile)
    const clusters = {};
    for (const v of real) {
        const k = dedupeKey(v);
        (clusters[k] = clusters[k] || { ...v, count: 0, pages: new Set() });
        clusters[k].count++; clusters[k].pages.add(v.documentURI);
    }
    const byDirective = {};
    for (const c of Object.values(clusters)) {
        const d = (c.effectiveDirective || 'unknown').split(' ')[0];
        (byDirective[d] = byDirective[d] || []).push(c);
    }

    fs.writeFileSync(path.join(ARTIFACTS, 'csp-violations.json'),
        JSON.stringify({ seededNames: names, pagesCrawled: rows.length,
            realViolations: real.length, devBuildEvalViolations: devEval.length,
            rows, clusters: Object.values(clusters).map((c) => ({ ...c, pages: [...c.pages] })) }, null, 2));

    let md = `# CSP violation sweep\n\n`;
    md += `- pages crawled: **${rows.length}**\n`;
    md += `- real violations (prod-relevant): **${real.length}** in ${Object.keys(clusters).length} clusters\n`;
    md += `- dev-build eval() violations (absent in prod build/): ${devEval.length} — ignored\n`;
    md += `- discovered names: run=\`${names.run || '—'}\` survey=\`${names.survey || '—'}\`\n\n`;
    const navErrors = rows.filter((r) => r.errors.length);
    for (const [d, cs] of Object.entries(byDirective)) {
        md += `## ${d} (${cs.reduce((n, c) => n + c.count, 0)})\n\n`;
        md += `| blocked-uri | source | count | example page |\n|---|---|---|---|\n`;
        for (const c of cs.sort((a, b) => b.count - a.count)) {
            md += `| \`${c.blockedURI || ''}\` | ${(c.sourceFile || '').replace(ADMIN_BASE, '')}${c.lineNumber ? ':' + c.lineNumber : ''} | ${c.count} | ${[...c.pages][0].replace(ADMIN_BASE, '')} |\n`;
        }
        md += `\n`;
    }
    if (!real.length) md += `**No prod-relevant CSP violations across the crawled admin surface.**\n\n`;
    if (navErrors.length) {
        md += `## console / nav errors\n\n`;
        for (const r of navErrors) md += `- ${r.url.replace(ADMIN_BASE, '')} (HTTP ${r.status}): ${r.errors.slice(0, 3).join(' · ')}\n`;
    }
    fs.writeFileSync(path.join(ARTIFACTS, 'csp-violations.md'), md);
    return { pages: rows.length, real: real.length, devEval: devEval.length };
}

test('csp sweep over admin surface', async ({ browser }) => {
    test.setTimeout(300000);
    await ensureAdminState(browser);

    const disc = await browser.newContext({ storageState: STATE_PATH });
    const discPage = await disc.newPage();
    const names = await discover(discPage);
    await disc.close();

    const urls = buildUrls(names);
    // worker pool across CONCURRENCY contexts (in-process, one browser)
    const queue = urls.slice();
    const rows = [];
    await Promise.all(Array.from({ length: CONCURRENCY }, async () => {
        const ctx = await browser.newContext({ storageState: STATE_PATH });
        await ctx.addInitScript(VIOLATION_HOOK);
        let url;
        while ((url = queue.shift()) !== undefined) rows.push(await visit(ctx, url));
        await ctx.close();
    }));

    const summary = writeReports(rows, names);
    console.log(`CSP sweep: ${summary.pages} pages, ${summary.real} real violations, ${summary.devEval} dev-eval (ignored). Report: tests/e2e/artifacts/csp-violations.md`);
});
