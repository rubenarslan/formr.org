// PWA manifest personalization end-to-end against the dev instance.
//
// Covers the four commits that landed Phase 3 of the PWA persistence
// rework: manifestAction dynamic-renders + personalizes start_url/id/
// shortcuts/protocol_handlers when ?code= validates against
// survey_run_sessions; head.php emits the manifest link with ?code=
// when an active RunSession is in scope.
//
// What these tests need that tests/pwa_manifest_smoke.sh doesn't:
//   - the rendered <link rel="manifest"> in the participant's head
//     (only inspectable from inside a real browser, not curl).
//
// What the smoke script tests that these don't:
//   - response headers byte-for-byte. Playwright's request fixture
//     normalizes some headers; the smoke is the authoritative header
//     check. We assert headers loosely here for double-coverage.
//
// Fixture:
//   - PWA_TEST_RUN  (default `appstinence-v2`) — a public PWA-enabled
//     run on the dev instance.
//   - PWA_TEST_CODE — a session code on that run with testing=1. No
//     default; suite skips when unset so a fresh dev DB doesn't fail
//     the whole test run. The dev's stable test code lives in the DB
//     and can be discovered with the same query in
//     tests/pwa_manifest_smoke.sh.

const { test, expect } = require('./helpers/test');

// e2e-pwa-h-v1 chosen as the default fixture because (a) it has a
// generated manifest_json_path on dev (so the manifest link is emitted
// in head.php), (b) Run::exec handles bare-URL anon visits without a
// 500 (some other runs trigger an internal error before head.php
// renders, which makes the recovery-banner specs untestable). Override
// PWA_TEST_RUN to point at a different fixture if needed.
const RUN = process.env.PWA_TEST_RUN || 'e2e-pwa-h-v1';
const CODE = process.env.PWA_TEST_CODE;

test.describe('PWA manifest personalization', () => {
    test.skip(!CODE, 'PWA_TEST_CODE env var not set; skipping manifest personalization suite');

    test('clean fetch returns un-tokenized URLs and correct headers', async ({ request, baseURL }) => {
        const res = await request.get(`${baseURL}/${RUN}/manifest`);
        expect(res.status()).toBe(200);
        const headers = res.headers();
        expect(headers['content-type']).toMatch(/^application\/manifest\+json/);
        expect(headers['cache-control']).toMatch(/private/);
        expect(headers['cache-control']).toMatch(/no-store/);
        const m = await res.json();
        expect(m.start_url).not.toContain('?code=');
        expect(m.start_url).toContain(`/${RUN}/`);
        expect(m.id).toBe(m.start_url);
        // scope is a path-prefix and must NEVER carry a query.
        expect(m.scope).not.toContain('?');
    });

    test('tokenized fetch personalizes start_url, id, shortcuts, protocol_handlers', async ({ request, baseURL }) => {
        const res = await request.get(`${baseURL}/${RUN}/manifest?code=${CODE}`);
        expect(res.status()).toBe(200);
        const m = await res.json();
        expect(m.start_url).toContain(`?code=${CODE}`);
        expect(m.id).toBe(m.start_url);
        // scope stays clean even on tokenized fetch.
        expect(m.scope).not.toContain('?');

        for (const s of m.shortcuts || []) {
            expect(s.url, `shortcut "${s.name}" should carry ?code=`).toMatch(new RegExp(`[?&]code=${CODE}`));
        }
        for (const p of m.protocol_handlers || []) {
            // Template URL has `?pwa=true&query=%s` — appendCode must
            // pick & not ? since a query is already present.
            expect(p.url).toContain(`&code=${CODE}`);
        }
    });

    test('bogus code returns 404', async ({ request, baseURL }) => {
        const res = await request.get(`${baseURL}/${RUN}/manifest?code=BOGUS`);
        expect(res.status()).toBe(404);
    });

    test('malformed code returns 404 (regex validation)', async ({ request, baseURL }) => {
        // ?code=<has spaces> won't match user_code_regular_expression;
        // controller's regex check rejects before the DB lookup.
        const res = await request.get(`${baseURL}/${RUN}/manifest?code=has%20spaces`);
        expect(res.status()).toBe(404);
    });

    test('rendered run page emits manifest link with ?code=', async ({ page, baseURL }) => {
        await page.goto(`${baseURL}/${RUN}/?code=${CODE}`, { waitUntil: 'commit', timeout: 60000 });
        const href = await page.locator('link[rel="manifest"]').first().getAttribute('href');
        expect(href, 'manifest link must carry the participant code so iOS captures the personalized variant at install').toContain(`?code=${CODE}`);
    });
});
