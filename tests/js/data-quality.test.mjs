// node --test tests/js  (npm run test:js)
// The parts of the data-quality recorder that need no browser
// (webroot/assets/common/js/data-quality.js): what a stored record plus a new
// visit add up to, and whose code a stack says called.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    RECORD_VERSION, blankRecord, parseRecord, mergeRecords, callerKind, adler32,
} from '../../webroot/assets/common/js/data-quality.js';

const BUNDLE = 'https://assets.example.org/assets/build/js/form.bundle.js';
const PAGE = 'https://study.example.org';
// Chrome's stack format; the first frame is always the recorder's own.
const chrome = (...callers) => ['Error', `    at HTMLElement.focus (${BUNDLE}:2:1500)`, ...callers].join('\n');
const firefox = (...callers) => [`focus@${BUNDLE}:2:1500`, ...callers].join('\n');

test('importing the module touches nothing: it runs here without a document', () => {
    assert.equal(typeof globalThis.document, 'undefined');
    assert.equal(blankRecord().v, RECORD_VERSION);
    assert.equal(Object.keys(blankRecord())[0], 'v', 'a stored record starts with its version');
});

test('only a record of ours is read back from an input', () => {
    assert.equal(parseRecord(''), null);
    assert.equal(parseRecord('not json'), null);
    assert.equal(parseRecord('[1,2]'), null);
    assert.equal(parseRecord('{"keys":3}'), null, 'no version, not a record');
    assert.deepEqual(parseRecord('{"v":2,"keys":3}'), { v: 2, keys: 3 });
});

test('a page seen again adds its counts to the stored record', () => {
    const base = Object.assign(blankRecord(), { ld: 1, t: 4000, keys: 10, pd: 3, hid_ms: 500, t1: 800 });
    const live = Object.assign(blankRecord(), { ld: 1, t: 1500, keys: 5, pd: 1, t1: 300 });
    const out = mergeRecords(base, live);
    assert.equal(out.ld, 2);
    assert.equal(out.t, 5500);
    assert.equal(out.keys, 15);
    assert.equal(out.pd, 4);
    assert.equal(out.hid_ms, 500);
    assert.equal(out.t1, 800, 'the first action is the first visit\'s');
    assert.equal(mergeRecords(null, live), live, 'nothing stored: the visit is the record');
});

test('yes/no values stay yes/no, name lists are joined, text counts are as they are now', () => {
    const base = Object.assign(blankRecord(), { wd: 1, mob: 1, chr_nowv: 0, swgl: -1, glob: 'a,b', ext: '', txt_ch: 120, fx0: 1 });
    const live = Object.assign(blankRecord(), { wd: 1, mob: 1, chr_nowv: 1, swgl: 0, glob: 'b,c', ext: 'claude', txt_ch: 40, fx0: 0 });
    const out = mergeRecords(base, live);
    assert.equal(out.wd, 1, 'not 2');
    assert.equal(out.mob, 1);
    assert.equal(out.chr_nowv, 1);
    assert.equal(out.swgl, 0);
    assert.equal(out.glob, 'a,b,c');
    assert.equal(out.ext, 'claude');
    assert.equal(out.txt_ch, 40, 'the text on the page now, not a sum');
    assert.equal(out.fx0, 0);
    assert.equal(out.v, RECORD_VERSION);
});

test('typing regularity keeps the most regular visit; -1 means too little typed', () => {
    const rec = (o) => Object.assign(blankRecord(), o);
    assert.equal(mergeRecords(rec({ kcv: 0.4 }), rec({ kcv: -1 })).kcv, 0.4);
    assert.equal(mergeRecords(rec({ kcv: 0.4 }), rec({ kcv: 0.1 })).kcv, 0.1);
    assert.equal(mergeRecords(rec({ kcv: -1 }), rec({ kcv: 0.3 })).kcv, 0.3);
    assert.equal(mergeRecords(rec({ kwb: 1.6 }), rec({ kwb: -1 })).kwb, 1.6);
    assert.equal(mergeRecords(rec({ mv_coal: -1, mv: 5 }), rec({ mv_coal: 3, mv: 5 })).mv_coal, -1, 'unsupported stays unsupported');
    assert.equal(mergeRecords(rec({ mv_coal: 2 }), rec({ mv_coal: 3 })).mv_coal, 5);
});

test('a call from the bundle, served from another origin than the page, is the page\'s own', () => {
    const own = `    at showPage (${BUNDLE}:2:99)`;
    assert.equal(callerKind(chrome(own, `    at HTMLButtonElement.<anonymous> (${BUNDLE}:2:400)`), PAGE), 'own');
    assert.equal(callerKind(firefox(`showPage@${BUNDLE}:2:99`), PAGE), 'own');
});

test('the run\'s own script on the page\'s origin is the page\'s own', () => {
    assert.equal(callerKind(chrome(`    at HTMLDocument.<anonymous> (${PAGE}/assets/tmp/admin/abc.js?v=1:3:7)`), PAGE), 'own');
});

test('code run in the page by an agent is foreign', () => {
    // page.evaluate / Runtime.evaluate
    assert.equal(callerKind(chrome('    at <anonymous>:1:38'), PAGE), 'foreign');
    assert.equal(callerKind(chrome('    at eval (eval at evaluate (:291:30), <anonymous>:1:20)', '    at UtilityScript.evaluate (<anonymous>:293:16)'), PAGE), 'foreign');
    assert.equal(callerKind(firefox('@debugger eval code:1:20'), PAGE), 'foreign');
    // a script injected from elsewhere
    assert.equal(callerKind(chrome('    at fill (chrome-extension://abcdefgh/content.js:10:5)'), PAGE), 'foreign');
    assert.equal(callerKind(chrome('    at run (https://evil.example/agent.js:1:1)'), PAGE), 'foreign');
});

test('the page\'s own code set off by an agent\'s is mixed: not counted again, and no page default', () => {
    // An agent's element.click() runs a button group's handler, which announces the change itself.
    const handler = `    at HTMLButtonElement.<anonymous> (${BUNDLE}:2:400)`;
    assert.equal(callerKind(chrome(handler, '    at <anonymous>:1:38'), PAGE), 'mixed');
    assert.equal(callerKind(firefox(`initButtonGroups/<@${BUNDLE}:2:400`, '@debugger eval code:1:20'), PAGE), 'mixed');
    // Built-in frames have no place in a file and say nothing either way.
    assert.equal(callerKind(chrome('    at Array.forEach (<anonymous>)', handler), PAGE), 'own');
});

test('an origin is matched whole, not as a prefix', () => {
    assert.equal(callerKind(chrome('    at run (https://study.example.org.evil.example/a.js:1:1)'), PAGE), 'foreign');
});

test('no script on the stack but the recorder: the browser called', () => {
    assert.equal(callerKind(chrome(), PAGE), 'none');
    assert.equal(callerKind('', PAGE), 'none');
    assert.equal(callerKind(undefined, PAGE), 'none');
});

test('the user-agent checksum is the one the R helper computes', () => {
    assert.equal(adler32('Wikipedia'), '11e60398');
    assert.equal(adler32(''), '1');
});
