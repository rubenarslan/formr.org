/**
 * Result coverage — Email.
 *
 * The Email unit checks its preconditions in a fixed order — recipient,
 * sending account, rate limit, subject, body, address validity — and the
 * FIRST failure wins, so each outcome needs a fixture of its own. A failed
 * send also parks the participant (deliberately: nobody should be advanced
 * past an email that never went out), so they cannot be chained.
 *
 * Every fixture resolves its recipient with an R literal in
 * `recipient_field`, e.g. `'rescov-nobody@invalid.invalid'`. That is a real
 * feature of the field — it is evaluated as R whenever it is not "most
 * recent reported address" — and it means these runs need no upstream survey
 * to collect an address. Each has its OWN address, because the rate limiter
 * counts per recipient across the whole instance: sharing one address made
 * the fixtures block each other, which looks exactly like a bug in the unit.
 *
 * Fixtures that fail BEFORE the send keep `.invalid` recipients — the
 * reserved never-resolving TLD, so no mailbox is involved. Fixtures that
 * reach the SMTP handoff (queued, ratelimit, smtpfail, syncfail) cannot: since
 * v1.9.0 the mail guard's MX check (`mail_guard.mx_check`) refuses a domain
 * with no MX/A record and records `error_email_guard_refused` instead of
 * handing the message to the relay. Those address the fixture account's own
 * mailbox with a plus tag (`e2etesting+rescov-…@researchmixtape.com`): the
 * domain resolves, nobody else is mailed, and the tag keeps the recipient
 * string different from the account's `from`, so the rate limiter's
 * mailing-yourself exemption does not apply.
 *
 * Fixtures (MCP-built, exported to tests/e2e/fixtures/<run>.json):
 *   rescov-mail-norec      recipient R -> NULL          -> no_recipient
 *   rescov-mail-nosubj     subject `r ""`               -> no_email_subject
 *   rescov-mail-nobody     empty body                   -> no_email_body
 *   rescov-mail-invalid    recipient 'not-an-address'   -> invalid_email
 *   rescov-mail-crononly   cron_only=1                  -> email_skipped_user_active
 *   rescov-mail-queued     valid                        -> email_queued, then email_sent
 *   rescov-mail-ratelimit  two Emails, same address     -> error_send_eligible on a re-run
 *
 * See documentation/agent_doc/LOG_STATE_AUDIT.md.
 */
const { test, expect } = require('./helpers/test');
const { dbQuery } = require('./helpers/db');
const { sessionRows, sessionTrace, runExists, recordObserved, enrol, waitForResult } = require('./helpers/results');

function requireFixture(runName) {
    test.skip(!runExists(runName),
        `fixture run '${runName}' is missing — rebuild it from `
        + `tests/e2e/fixtures/${runName}.json via the run editor's Import button`);
}

/** The precondition failures: one traversal, one row, run parks there. */
const FAILURES = [
    ['rescov-mail-norec', 'no_recipient', /could not find an email recipient/i],
    ['rescov-mail-nosubj', 'no_email_subject', /no email subject/i],
    ['rescov-mail-nobody', 'no_email_body', /no email body/i],
    ['rescov-mail-invalid', 'invalid_email', /not valid/i],
];

test.describe('result coverage — Email', () => {
    for (const [run, expected, logPattern] of FAILURES) {
        test(`${expected}`, async ({ page, context }) => {
            requireFixture(run);
            await enrol(page, context, run);

            const rows = sessionRows(run);
            expect(rows.map((r) => [r.position, r.result]),
                'a failed send must not advance the participant past the email')
                .toEqual([[10, expected]]);
            // The reason has to reach the row: for most of these the only other
            // copy goes to alert(), a request-scoped bag a daemon-driven
            // execute discards entirely.
            expect(rows[0].log).toMatch(logPattern);

            recordObserved([expected]);
        });
    }

    test('a cron_only Email reached by a live participant is skipped, not sent', async ({ page, context }) => {
        requireFixture('rescov-mail-crononly');
        await enrol(page, context, 'rescov-mail-crononly');

        expect(sessionTrace('rescov-mail-crononly')).toEqual([
            [10, 'email_skipped_user_active'],
            [20, 'ended'],
        ]);

        recordObserved(['email_skipped_user_active', 'ended']);
    });

    test('a good Email is queued, and the mail daemon then records email_sent', async ({ page, context }) => {
        requireFixture('rescov-mail-queued');
        await enrol(page, context, 'rescov-mail-queued');

        // Read email_queued IMMEDIATELY. The mail daemon runs continuously in
        // the dev stack and overwrites this row with email_sent within ~15s —
        // it UPDATEs survey_unit_sessions with no `ended IS NULL` guard, which
        // is how a terminal row gets rewritten at all.
        expect(sessionTrace('rescov-mail-queued')).toEqual([
            [10, 'email_queued'],
            [20, 'ended'],
        ]);
        recordObserved(['email_queued']);

        const sent = await waitForResult('rescov-mail-queued', 10, 'email_sent');
        expect(sent, 'the daemon rewrites the row once SMTP accepts it').toBe('email_sent');

        // The fixture's recipient_field is
        // `paste0('e2etesting+rescov-queued-', as.integer(Sys.time()), '@researchmixtape.com')`
        // — a fresh address per execution, so the per-address rate limiter can
        // never block this fixture. With a fixed address the spec passed once
        // and then failed for a minute afterwards, reporting
        // error_send_eligible where it expected email_queued.
        const logged = dbQuery(`
            SELECT status FROM survey_email_log
             WHERE recipient LIKE 'e2etesting+rescov-queued-%@researchmixtape.com'
             ORDER BY id DESC LIMIT 1;
        `);
        expect(parseInt(logged[0].status, 10), 'and survey_email_log agrees').toBe(1);

        recordObserved(['email_sent']);
    });

    test('the rate limiter blocks a second send to the same address', async ({ page, context }) => {
        requireFixture('rescov-mail-ratelimit');

        // The limiter counts only rows at status = 1, so a traversal starting
        // from a quiet address cannot trip it — both emails are still queued
        // when it is consulted. It needs a prior *delivered* message, which
        // the daemon supplies. But the address may already be hot from a
        // previous run of this spec, and then the very first email is refused.
        // Handle both entry states rather than assuming a quiet start: an
        // over-precise version of this test skipped itself because the
        // condition it wanted was already true.
        await enrol(page, context, 'rescov-mail-ratelimit');
        let blocked = sessionRows('rescov-mail-ratelimit').filter((r) => r.position === 10).pop();

        if (blocked.result !== 'error_send_eligible') {
            const sent = await waitForResult('rescov-mail-ratelimit', 10, 'email_sent');
            test.skip(sent !== 'email_sent',
                'the mail daemon did not deliver the priming message — is formr_mail_daemon up?');

            // Within the minute, a second participant to the same address is
            // refused. `in_last_1m` is 1 by default, and this run is not
            // testing, so there is no permissive testing branch to fall into.
            await enrol(page, context, 'rescov-mail-ratelimit');
            blocked = sessionRows('rescov-mail-ratelimit').filter((r) => r.position === 10).pop();
        }

        expect(blocked.result,
            'the catalogue used to declare error_send_eligible unreachable, on the '
            + 'reasoning that the rate-limit text is written to $this->messages and '
            + 'read only on the success path. That is true of the WARNING branch; the '
            + 'BLOCKING branch writes $this->errors[\'log\'], which the caller reads. '
            + 'Found by this suite — the catalogue is corrected.')
            .toBe('error_send_eligible');
        expect(blocked.log).toMatch(/already sent/i);

        recordObserved(['error_send_eligible']);
    });
});
