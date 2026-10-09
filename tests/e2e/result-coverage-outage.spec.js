/**
 * Result coverage — outcomes that need a dependency to be DOWN.
 *
 * These are the two results a healthy instance cannot produce, so the spec
 * breaks the instance on purpose and puts it back. Both are gated on an env
 * var, because the dev box is shared and an interrupted run would leave a
 * service stopped:
 *
 *   RESCOV_OUTAGE=1 npx playwright test --config tests/e2e/playwright.config.js result-coverage-outage
 *
 * `error_opencpu_down` — the Endpage's body needs knitting, and knitting
 * needs OpenCPU. The spec stops the `opencpu` container, walks a participant
 * onto the endpage, and restarts it in a finally, then verifies it came back.
 *
 * `error_email` — the SYNCHRONOUS send path, which only exists when
 * `email.use_queue` is false. That is an instance-wide config setting, so
 * the spec cannot flip it; it detects the mode from behaviour instead
 * (a queueing instance records `email_queued` for the same fixture) and
 * skips unless someone has put the instance in synchronous mode. Verified by
 * hand during v1.8.0: with use_queue false and an account pointing at an
 * unreachable host, `rescov-mail-syncfail` records `error_email`.
 *
 * Fixtures (MCP-built, exported to tests/e2e/fixtures/<run>.json):
 *   rescov-ocpudown       5 Survey  10 Endpage with `r 40 + 2` in the body
 *   rescov-mail-syncfail  10 Email on account 22 (host smtp.invalid)  20 Endpage
 *
 * See documentation/agent_doc/LOG_STATE_AUDIT.md.
 */
const { execFileSync } = require('node:child_process');
const { test, expect } = require('./helpers/test');
const { sessionRows, runExists, recordObserved, enrol } = require('./helpers/results');

const OUTAGE_CONTAINER = 'opencpu';

function docker(...args) {
    return execFileSync('docker', args, { encoding: 'utf8' }).trim();
}

function containerRunning(name) {
    try { return docker('inspect', '-f', '{{.State.Running}}', name) === 'true'; }
    catch (e) { return false; }
}

/**
 * Wait until OpenCPU answers again, from inside the app container — the only
 * place that can reach it (it lives on the backend_r network).
 *
 * `docker start` returns as soon as the container is up, not when R is
 * listening. Without this the NEXT test ran while OpenCPU was still booting
 * and its Email fixture recorded `no_recipient`, because the R that resolves
 * the recipient could not evaluate. A false failure that looks exactly like
 * a real one.
 */
async function waitForOpenCpu(timeoutMs = 60000) {
    const deadline = Date.now() + timeoutMs;
    for (;;) {
        try {
            const code = docker('exec', 'formr_app', 'curl', '-s', '-o', '/dev/null',
                '-w', '%{http_code}', 'http://opencpu/ocpu/library');
            if (/^(2|3)\d\d$/.test(code)) return true;
        } catch (e) { /* still down */ }
        if (Date.now() > deadline) return false;
        await new Promise((r) => setTimeout(r, 2000));
    }
}

test.describe('result coverage — dependency outages', () => {
    test.describe.configure({ mode: 'serial', timeout: 180 * 1000 });
    test.skip(process.env.RESCOV_OUTAGE !== '1',
        'stops a shared service on purpose — set RESCOV_OUTAGE=1 to run');

    test('a Page whose knit needs an unreachable OpenCPU records error_opencpu_down', async ({ page, context }) => {
        test.skip(!runExists('rescov-ocpudown'),
            'fixture run \'rescov-ocpudown\' is missing — rebuild it from '
            + 'tests/e2e/fixtures/rescov-ocpudown.json via the run editor\'s Import button');
        test.skip(!containerRunning(OUTAGE_CONTAINER),
            `container '${OUTAGE_CONTAINER}' is not running — nothing to take down`);

        docker('stop', OUTAGE_CONTAINER);
        try {
            await enrol(page, context, 'rescov-ocpudown');
            await page.fill('input[name=q1]', 'anything');
            await page.click('button[type=submit], input[type=submit]');
            await page.waitForLoadState('networkidle');

            const endpage = sessionRows('rescov-ocpudown').filter((r) => r.position === 10).pop();
            expect(endpage, 'the participant must reach the endpage').toBeTruthy();
            expect(endpage.result).toBe('error_opencpu_down');
        } finally {
            docker('start', OUTAGE_CONTAINER);
        }

        // Put it back BEFORE asserting anything else: a failed assertion above
        // must not leave the instance without R. Then prove it really is back
        // and SERVING, because every other spec in this suite depends on it.
        expect(containerRunning(OUTAGE_CONTAINER),
            `'${OUTAGE_CONTAINER}' did not restart — the dev instance has no R runtime until it does`)
            .toBe(true);
        expect(await waitForOpenCpu(),
            `'${OUTAGE_CONTAINER}' restarted but is not answering — R-dependent specs will fail`)
            .toBe(true);

        recordObserved(['error_opencpu_down']);
    });

    test('a synchronous send failure records error_email', async ({ page, context }) => {
        test.skip(!runExists('rescov-mail-syncfail'),
            'fixture run \'rescov-mail-syncfail\' is missing — rebuild it from '
            + 'tests/e2e/fixtures/rescov-mail-syncfail.json via the run editor\'s Import button');

        await enrol(page, context, 'rescov-mail-syncfail');
        const email = sessionRows('rescov-mail-syncfail').filter((r) => r.position === 10).pop();

        test.skip(email.result === 'email_queued',
            'this instance queues mail (email.use_queue = true), so the synchronous '
            + 'path that records error_email is not in play. Flip email.use_queue to '
            + 'false in config/settings.php to exercise it.');

        expect(email.result,
            'with use_queue false, sendNow() fails against smtp.invalid and the '
            + 'error lands on the row rather than only in alert()')
            .toBe('error_email');

        recordObserved(['error_email']);
    });
});
