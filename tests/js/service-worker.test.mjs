// node --test tests/js  (npm run test:js)
// Rules of the run's service worker (webroot/assets/common/js/service-worker.js),
// loaded as a classic worker script into a sandbox: its top level only
// declares functions and constants and registers listeners, so a stub `self`
// is enough to reach the functions.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const SOURCE = readFileSync(new URL('../../webroot/assets/common/js/service-worker.js', import.meta.url), 'utf8');

function loadWorker(href = 'https://study.example.org/myrun/service-worker') {
    const self = {
        location: new URL(href),
        registration: { scope: new URL('./', href).href },
        addEventListener() {},
    };
    const sandbox = { self, console: { log() {}, warn() {}, error() {} }, URL, Date, Number, Promise, clients: {}, caches: {} };
    vm.createContext(sandbox);
    vm.runInContext(SOURCE, sandbox);
    return sandbox;
}

test('a notification asks for no badge image unless the push names one', () => {
    const sw = loadWorker();
    const plain = sw.notificationOptions({ title: 'Hi', body: 'b', badgeCount: 3 }, 'https://study.example.org/icon.png', 1000);
    // A badge that is not an image URL makes the browser fetch <run>/1.
    assert.equal(plain.badge, undefined);
    assert.equal(plain.data.badgeCount, 3, 'the count goes to the Badging API');
    assert.equal(sw.notificationOptions({ badge: 1 }, '', 1000).badge, undefined, 'a number is no image');
    assert.equal(sw.notificationOptions({ badge: '/assets/pwa/badge.png' }, '', 1000).badge,
        'https://study.example.org/assets/pwa/badge.png');
    assert.equal(plain.tag, 'notification-1000');
    assert.equal(plain.data.clickTarget, 'https://study.example.org/myrun/');
});

test("activation drops this run's older caches, not another run's", () => {
    const sw = loadWorker('https://study.example.org/myrun/service-worker');
    assert.equal(sw.isStaleCache('formr-v7-study.example.org-myrun'), true, 'an earlier version of this run');
    assert.equal(sw.isStaleCache('formr-v8-study.example.org-myrun'), false, 'the current cache');
    assert.equal(sw.isStaleCache('formr-v7-study.example.org-otherrun'), false, 'another run on the domain');
    assert.equal(sw.isStaleCache('formr-v7-study.example.org-run'), false, 'a run whose name ends the same');
    assert.equal(sw.isStaleCache('workbox-precache'), false);
});
