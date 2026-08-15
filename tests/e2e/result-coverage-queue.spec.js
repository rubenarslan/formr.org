/**
 * Result coverage — outcomes only the daemon can produce.
 *
 * A participant never triggers these: the run sits parked until a deadline
 * passes and the queue worker acts on it. That is not a different lane
 * though — the fixture is entered in a browser like any other, and
 * `runQueueOnce()` shells out to the real worker (`bin/queue.php --once`),
 * so nothing here is simulated.
 *
 * The deadlines are REAL one-minute waits, not backdated rows. Rewriting
 * `expires` by hand would test the daemon against a state the app never
 * produced, and the elapsed path is exactly where that matters: v1.7.1's
 * `expired`-flag change altered which of end()/expire() an elapsed Wait
 * takes. So this spec is slow by construction — it enters both fixtures
 * first and then waits once for both.
 *
 * Fixtures (MCP-built, exported to tests/e2e/fixtures/<run>.json):
 *   rescov-pausecron  10 Pause wait_minutes=1 -> pause_ended, 20 Endpage
 *   rescov-waitcron   10 Pause wait_minutes=1, 20 Wait wait_minutes=1,
 *                     30 Endpage
 *
 * The Pause in front of the Wait is not padding, it is the only way to
 * reach `wait_ended` at all: ANY non-cron request on a parked Wait takes
 * the early-return branch and records `wait_ended_by_user`, so a Wait a
 * participant walks into ends the moment they arrive. It has to be entered
 * BY the cron cascade — participant parked on the Pause, daemon ends it and
 * advances them into the Wait while they are away — which is how a diary
 * study actually reaches one. First attempt at this spec put the Wait
 * first and got `wait_ended_by_user`; that is not a flake, it is the
 * v1.8.0 finding reproducing itself.
 *
 * See documentation/agent_doc/LOG_STATE_AUDIT.md.
 */
const { test, expect } = require('./helpers/test');
const { runQueueOnce } = require('./helpers/queue');
const { sessionRows, sessionTrace, runExists, recordObserved, enrol } = require('./helpers/results');

// Two real one-minute deadlines in sequence, plus traversals and queue passes.
test.describe.configure({ timeout: 360 * 1000 });

const MINUTE_PLUS_MARGIN = 75 * 1000;

/** Two passes: one ends the parked unit, the next lets the cascade proceed. */
function drainQueue() {
    const first = runQueueOnce();
    runQueueOnce();
    return first;
}

test('an elapsed Pause and an elapsed Wait are ended by a real daemon pass', async ({ page, context }) => {
    for (const run of ['rescov-pausecron', 'rescov-waitcron']) {
        test.skip(!runExists(run),
            `fixture run '${run}' is missing — rebuild it from `
            + `tests/e2e/fixtures/${run}.json via the run editor's Import button`);
    }

    // Enter both first, so one wait covers both deadlines.
    await enrol(page, context, 'rescov-pausecron');
    expect(sessionTrace('rescov-pausecron'), 'parks before the deadline')
        .toEqual([[10, 'pause_waiting']]);

    await enrol(page, context, 'rescov-waitcron');
    expect(sessionTrace('rescov-waitcron'), 'parks on the Pause, not the Wait')
        .toEqual([[10, 'pause_waiting']]);

    // The deadlines are wait_minutes=1 from entry; the margin puts the
    // queue's own clock unambiguously past them. No browser request is made
    // from here on — one would end the Wait as `wait_ended_by_user`.
    await new Promise((r) => setTimeout(r, MINUTE_PLUS_MARGIN));
    expect(drainQueue().ok, 'the queue worker must run').toBe(true);

    const pause = sessionRows('rescov-pausecron');
    expect(pause[0].result,
        'an elapsed Pause takes end()\'s type default, since the unit logs '
        + 'nothing on the elapsed path').toBe('pause_ended');

    // The cascade should have carried the second participant into the Wait.
    const midway = sessionTrace('rescov-waitcron');
    expect(midway[0]).toEqual([10, 'pause_ended']);
    expect(midway.map(([pos]) => pos), 'the daemon must advance them into the Wait')
        .toContain(20);

    await new Promise((r) => setTimeout(r, MINUTE_PLUS_MARGIN));
    drainQueue();

    const wait = sessionRows('rescov-waitcron').find((r) => r.position === 20);
    expect(wait.result,
        'an elapsed Wait must be distinguishable from one a participant '
        + 'returned to early (wait_ended_by_user) — v1.7.1 collapsed the two')
        .toBe('wait_ended');

    recordObserved(['pause_ended', 'wait_ended']);
});
