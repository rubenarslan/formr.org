/**
 * Result coverage — a send that SMTP refuses.
 *
 * The daemon only picks up rows whose account is `status = 1`, and status 1
 * comes solely from `EmailAccount::test()` succeeding, while
 * `changeSettings()` resets it to 0 on every save. So an account cannot be
 * *configured* broken — only *become* broken after being verified, when the
 * host moves, credentials rotate, or the server goes down. That is a real
 * production failure with no admin action behind it, so the test reproduces
 * the outage instead: it points the account's SMTP host at 127.0.0.1 inside
 * the mail daemon's own container.
 *
 * The revert is `docker restart`, which regenerates /etc/hosts — so the
 * change cannot outlive the container even if this process dies. The restart
 * happens in a finally regardless.
 *
 * Gated, because it briefly stops the dev box from sending any mail:
 *
 *   RESCOV_OUTAGE=1 npx playwright test --config tests/e2e/playwright.config.js result-coverage-smtp
 *
 * `email_gave_up` is NOT covered here. It needs the retry budget to run out:
 * four attempts spaced by `max(60, queue_item_ttl / queue_item_tries)` — 20
 * minutes of blackholed SMTP at stock settings, which is a long time to
 * leave a shared instance unable to mail. It was verified by hand during
 * v1.8.0 with `queue_item_ttl` temporarily at 60s ("Gave up after 4 delivery
 * attempts…"), and the config was restored and diffed afterwards.
 *
 * Fixture: rescov-mail-smtpfail (Email on the verified account, with a fresh
 * recipient per execution so the rate limiter never interferes).
 *
 * See documentation/agent_doc/LOG_STATE_AUDIT.md.
 */
const { execFileSync } = require('node:child_process');
const { test, expect } = require('./helpers/test');
const { dbQuery } = require('./helpers/db');
const { sessionRows, runExists, recordObserved, enrol, waitForResult } = require('./helpers/results');

const DAEMON = 'formr_mail_daemon';
// Its OWN fixture, not the one the email spec uses for `email_sent`.
// Sharing rescov-mail-queued meant this spec's blackhole could catch that
// spec's in-flight message: `email_sent` quietly stopped being observed in
// full-lane runs while both specs still passed.
const RUN = 'rescov-mail-smtpfail';

function docker(...args) {
    return execFileSync('docker', args, { encoding: 'utf8' }).trim();
}

test.describe('result coverage — SMTP refuses the send', () => {
    test.describe.configure({ timeout: 240 * 1000 });
    test.skip(process.env.RESCOV_OUTAGE !== '1',
        'stops the dev box sending mail for the duration — set RESCOV_OUTAGE=1 to run');

    test('a verified account whose host stops answering records error_email_not_sent', async ({ page, context }) => {
        test.skip(!runExists(RUN), `fixture run '${RUN}' is missing`);

        // The host the fixture's account actually uses. Read, not assumed:
        // pointing the wrong name at 127.0.0.1 would make this pass for no
        // reason on a differently-configured instance.
        const account = dbQuery(`
            SELECT a.host AS host FROM survey_email_accounts a
              JOIN survey_emails e ON e.account_id = a.id
              JOIN survey_run_units sru ON sru.unit_id = e.id
              JOIN survey_runs r ON r.id = sru.run_id
             WHERE r.name = '${RUN}' LIMIT 1;
        `);
        test.skip(!account.length, 'could not resolve the fixture account host');
        const host = account[0].host;

        docker('exec', '-u', 'root', DAEMON, 'sh', '-c', `echo "127.0.0.1 ${host}" >> /etc/hosts`);
        try {
            await enrol(page, context, RUN);
            expect(sessionRows(RUN).filter((r) => r.position === 10).pop().result,
                'the web side still queues it — the failure belongs to the daemon')
                .toBe('email_queued');

            const result = await waitForResult(RUN, 10, 'error_email_not_sent', 120000);
            expect(result,
                'a refused send has to land on the row: the participant has '
                + 'already been advanced past this email, so the row is the only '
                + 'place anyone can learn it never went out')
                .toBe('error_email_not_sent');

            const row = sessionRows(RUN).filter((r) => r.position === 10).pop();
            expect(row.log, 'and carry the SMTP error itself').toMatch(/SMTP|connect/i);
        } finally {
            // Restart regenerates /etc/hosts, so this both reverts the change
            // and proves it cannot persist.
            docker('restart', DAEMON);
        }

        const hosts = docker('exec', DAEMON, 'sh', '-c', `grep -c '${host}' /etc/hosts || true`);
        expect(hosts.trim(), 'the blackhole must not survive the restart').toBe('0');

        recordObserved(['error_email_not_sent', 'email_queued']);
    });
});
