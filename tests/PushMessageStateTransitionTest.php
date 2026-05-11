<?php

/**
 * Track A A8 — PushMessage state-transition fix.
 *
 * Pre-fix bug: PushMessage::getUnitSessionOutput returned
 * `['move_on' => true]` (and `['log' => ['result' => 'sent'], 'move_on' => true]`
 * on the success path) WITHOUT `end_session`. That meant RunSession::
 * executeUnitSession never called `UnitSession::end()` on a Push row.
 * Consequences:
 *   - `state` stays at PENDING for the row's lifetime even after a
 *     successful send (Track A's state column was silently broken for
 *     Push).
 *   - `ended` stays NULL.
 *   - `queued` stays at whatever it was (typically 0 because Push
 *     doesn't `queue()`).
 *   - `result` column DOES get set via `logResult` from the `log` key,
 *     so the v0.25.7 terminal-result guard still works — but the row
 *     looks "live" in any check that uses `ended IS NULL` (e.g.
 *     `RunSession::endLastExternal`-style queries, admin queue
 *     inspectors filtering by `state != 'ENDED'`).
 *
 * Fix: every terminating return in `getUnitSessionOutput` includes
 * `end_session => true` so the standard cascade dispatcher calls
 * `end()`, which dual-writes `state = 'ENDED'`, `ended = NOW()`, and
 * the structured `state_log` JSON.
 *
 * This test pins the corrected return shapes. Email already does this
 * (`['end_session' => true, 'move_on' => true]`); Push now follows the
 * same contract.
 */
class PushMessageStateTransitionTest extends \PHPUnit\Framework\TestCase
{
    /**
     * v0.25.7 terminal-result guard must return end_session+move_on so
     * a re-encounter of an already-sent row transitions the state too
     * (idempotent: end() is a no-op on a row where ended IS NOT NULL).
     *
     * @dataProvider terminalResults
     */
    public function testTerminalResultGuardReturnsEndSessionPlusMoveOn(string $result): void
    {
        $push = (new ReflectionClass(PushMessage::class))->newInstanceWithoutConstructor();
        $us = (new ReflectionClass(UnitSession::class))->newInstanceWithoutConstructor();
        $us->result = $result;

        $output = $push->getUnitSessionOutput($us);

        $this->assertTrue($output['end_session'] ?? false,
            "terminal-result guard for '{$result}' must return end_session=true so Push transitions to ENDED");
        $this->assertTrue($output['move_on'] ?? false,
            "terminal-result guard for '{$result}' must return move_on=true");
    }

    public static function terminalResults(): array
    {
        return [
            'sent'                  => ['sent'],
            'no_subscription'       => ['no_subscription'],
            'error'                 => ['error'],
            'message_parse_failed'  => ['message_parse_failed'],
            'title_parse_failed'    => ['title_parse_failed'],
        ];
    }

    /**
     * Source-grep proof that every `return [ ..., 'move_on' => true ];`
     * literal in PushMessage::getUnitSessionOutput is paired with
     * `'end_session' => true`. Only matches `return [...]` array
     * literals — assignments like `$output['move_on'] = true;` are
     * accumulator-style and get returned via `return $output;` at the
     * end, where the matching `$output['end_session'] = true;`
     * assignment lives on an adjacent line. The data-provider test
     * above covers the early-return shapes; this pins the inline
     * `return [...]` form.
     */
    public function testNoBareMoveOnReturnLiteralsInGetUnitSessionOutput(): void
    {
        $src = file_get_contents(APPLICATION_ROOT . 'application/Model/RunUnit/PushMessage.php');
        $this->assertNotFalse($src);

        $startPos = strpos($src, 'public function getUnitSessionOutput');
        $this->assertNotFalse($startPos);
        $body = substr($src, $startPos, 3500);

        // Match `return [ ... ];` literals (the dot+s flag spans newlines
        // but the non-greedy [^\]]* keeps each match scoped to one
        // array literal).
        $offenders = [];
        if (preg_match_all('/return\s*\[([^\]]*?)\]\s*;/s', $body, $matches)) {
            foreach ($matches[1] as $inner) {
                if (str_contains($inner, "'move_on'") && !str_contains($inner, "'end_session'")) {
                    $offenders[] = "return [" . trim($inner) . "];";
                }
            }
        }

        $this->assertEmpty(
            $offenders,
            "Found `return [...]` literals with 'move_on' but no 'end_session':\n" . implode("\n", $offenders)
        );
    }
}
