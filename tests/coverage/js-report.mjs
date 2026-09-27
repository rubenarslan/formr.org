// Map the e2e JS coverage (tests/e2e/helpers/jsCoverage.js) onto our source
// files through the dev-build source maps.
//
//   node tests/coverage/js-report.mjs [.coverage]
//
// Reads <dir>/js/worker-*.json, writes <dir>/js-summary.txt (per source
// file: executable/covered lines, uncovered functions) and
// <dir>/js-uncovered.tsv (file, uncovered line ranges). The bundle on disk
// must be the one the suite ran against (don't edit while webpack:watch
// runs during a coverage pass): its length is checked.
//
// Line granularity: the dev build's `cheap-module-source-map` maps lines
// only. A source line counts as executable if a generated line maps to it,
// and as covered if the first statement of any such generated line ran.

import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { SourceMapConsumer } = require('source-map');
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.resolve(process.argv[2] || path.join(ROOT, '.coverage'));
const OURS = /^webroot\/assets\/(?!.*node_modules)/;

const union = new Map(); // bundle pathname → { length, ran, functions }
for (const f of fs.readdirSync(path.join(OUT, 'js')).filter((n) => /^worker-.*\.json$/.test(n))) {
    const data = JSON.parse(fs.readFileSync(path.join(OUT, 'js', f), 'utf8'));
    for (const [url, d] of Object.entries(data)) {
        const key = new URL(url).pathname;
        let u = union.get(key);
        if (!u) { u = { length: d.length, ran: new Uint8Array(d.length), functions: new Map() }; union.set(key, u); }
        if (u.length !== d.length) { console.warn(`skip ${f}: ${key} length differs`); continue; }
        for (const [s, e] of d.covered) u.ran.fill(1, s, e);
        for (const [off, name] of d.functions) if (!u.functions.has(off)) u.functions.set(off, name);
    }
}

const files = new Map(); // source → { exec:Set, covered:Set, fns:[{line,name,covered}] }
const fileFor = (src) => {
    if (!files.has(src)) files.set(src, { exec: new Set(), covered: new Set(), fns: [] });
    return files.get(src);
};
const normalise = (src) => src.replace(/^webpack:\/\/[^/]*\/(\.\/)?/, '');

for (const [pathname, u] of union) {
    const local = path.join(ROOT, 'webroot', pathname);
    if (!fs.existsSync(local) || !fs.existsSync(local + '.map')) { console.warn(`no local bundle/map for ${pathname}`); continue; }
    const code = fs.readFileSync(local, 'utf8');
    if (code.length !== u.length) { console.warn(`${pathname}: bundle on disk differs from the one covered (rebuilt?) — skipped`); continue; }
    const lineStarts = [0];
    for (let i = 0; i < code.length; i++) if (code.charCodeAt(i) === 10) lineStarts.push(i + 1);
    const firstStatement = (line) => {
        let i = lineStarts[line - 1];
        const end = line < lineStarts.length ? lineStarts[line] : code.length;
        while (i < end && /\s/.test(code[i])) i++;
        return i < end ? i : -1;
    };
    const consumer = new SourceMapConsumer(JSON.parse(fs.readFileSync(local + '.map', 'utf8')));
    consumer.eachMapping((m) => {
        if (!m.source || m.originalLine == null) return;
        const src = normalise(m.source);
        if (!OURS.test(src)) return;
        const off = m.generatedColumn > 0 ? lineStarts[m.generatedLine - 1] + m.generatedColumn : firstStatement(m.generatedLine);
        if (off < 0) return;
        const f = fileFor(src);
        f.exec.add(m.originalLine);
        if (u.ran[off]) f.covered.add(m.originalLine);
    });
    for (const [off, name] of u.functions) {
        let lo = 0, hi = lineStarts.length - 1;
        while (lo < hi) { const mid = (lo + hi + 1) >> 1; if (lineStarts[mid] <= off) lo = mid; else hi = mid - 1; }
        const pos = consumer.originalPositionFor({ line: lo + 1, column: off - lineStarts[lo] });
        if (!pos.source || !OURS.test(normalise(pos.source))) continue;
        fileFor(normalise(pos.source)).fns.push({ line: pos.line, name: name || '(anonymous)', covered: u.ran[off] === 1 });
    }
}

const summary = [];
const tsv = ['file\tuncovered_lines'];
let totE = 0, totC = 0;
for (const src of [...files.keys()].sort()) {
    const f = files.get(src);
    const e = f.exec.size, c = [...f.exec].filter((l) => f.covered.has(l)).length;
    totE += e; totC += c;
    summary.push(`${src}\n  lines ${c}/${e} (${e ? ((100 * c) / e).toFixed(1) : '0.0'}%)`);
    const uncoveredFns = f.fns.filter((x) => !x.covered).sort((a, b) => a.line - b.line);
    if (uncoveredFns.length) summary.push('  never called: ' + uncoveredFns.map((x) => `${x.name}:${x.line}`).join(', '));
    const lines = [...f.exec].filter((l) => !f.covered.has(l)).sort((a, b) => a - b);
    const runs = [];
    for (const l of lines) {
        const last = runs[runs.length - 1];
        if (last && l === last[1] + 1) last[1] = l; else runs.push([l, l]);
    }
    if (runs.length) tsv.push(`${src}\t${runs.map(([a, b]) => (a === b ? `${a}` : `${a}-${b}`)).join(',')}`);
}
summary.unshift(`JS coverage (e2e), our sources: ${totC}/${totE} lines (${totE ? ((100 * totC) / totE).toFixed(1) : '0.0'}%)\n`);
fs.writeFileSync(path.join(OUT, 'js-summary.txt'), summary.join('\n') + '\n');
fs.writeFileSync(path.join(OUT, 'js-uncovered.tsv'), tsv.join('\n') + '\n');
console.log(summary[0].trim());
console.log(`wrote ${path.join(OUT, 'js-summary.txt')}, js-uncovered.tsv`);
