// Helpers for racing two near-simultaneous HTTP requests against the
// same run-session URL. This is what the prod ExpiryNotifier auto-reload
// triggers when a participant has the run open in two clients (PWA +
// browser tab) — both fire `window.location.reload()` on the same wall-
// clock tick.
//
// The duplicate-cascade bug requires both requests' RunSession
// constructors to load `position` BEFORE either acquires the run-session
// named lock. Race them:
//   1. Hold the named lock externally for a short window.
//   2. Fire two parallel GETs. Each PHP request:
//        - constructor → load() → cache position from DB
//        - lock acquire → BLOCKS on the externally-held lock
//   3. Release external lock. Both PHP requests' acquireLock succeed in
//      turn. Whoever goes second uses its stale cached position to
//      drive moveOn — that's the duplicate cascade.
// Without external lock-holding the race would still happen (TCP/PHP
// startup variance is enough), but holding the lock makes the bug
// deterministic in test.

const { request: pwRequest } = require('@playwright/test');
const { holdRunSessionLock } = require('./lock');

// Fire two parallel GETs against `${baseURL}/${runName}/?code=${code}`.
// Returns an array of two { status, body } records once both responses
// have come back. Uses two separate Playwright APIRequestContexts so the
// HTTP/1.1 keep-alive pool can't serialize them onto a single
// connection.
async function raceTwoGets(baseURL, runName, code, { timeoutMs = 30000 } = {}) {
    const [ctxA, ctxB] = await Promise.all([
        pwRequest.newContext({ ignoreHTTPSErrors: true }),
        pwRequest.newContext({ ignoreHTTPSErrors: true }),
    ]);
    const url = `${baseURL}/${runName}/?code=${code}`;
    try {
        const [respA, respB] = await Promise.all([
            ctxA.get(url, { timeout: timeoutMs }),
            ctxB.get(url, { timeout: timeoutMs }),
        ]);
        return [
            { status: respA.status(), body: await respA.text() },
            { status: respB.status(), body: await respB.text() },
        ];
    } finally {
        await ctxA.dispose().catch(() => {});
        await ctxB.dispose().catch(() => {});
    }
}

// Fire two parallel GETs while a third process holds the run-session
// lock for `holdSec` seconds. The two GETs both reach acquireLock
// before either can proceed; both have RunSession.load()'s cached
// position from the moment of their constructor (which is BEFORE the
// lock-acquire). After release, they unblock in turn — the second one
// uses its stale position to drive moveOn, producing duplicate
// downstream unit-sessions.
async function raceTwoGetsBehindLock(baseURL, runName, code, runSessionId, { holdSec = 3, timeoutMs = 30000 } = {}) {
    const handle = holdRunSessionLock(runSessionId, holdSec);
    try {
        await handle.waitAcquired();
        // Give both PHP requests a beat to start, hit their RunSession
        // constructor, and queue at the lock. 200 ms is plenty.
        const racePromise = raceTwoGets(baseURL, runName, code, { timeoutMs });
        await new Promise((r) => setTimeout(r, 200));
        // Release external lock; the two PHP requests now compete.
        handle.release();
        return await racePromise;
    } finally {
        handle.release();
    }
}

module.exports = { raceTwoGets, raceTwoGetsBehindLock };
