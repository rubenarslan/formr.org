<?php

/**
 * Pure decision logic for healing duplicated Pause/Wait unit-sessions
 * (v0.27.0). Extracted from bin/heal_duplicate_pause_sessions.php
 * so the risky classification is unit-testable without a live DB.
 *
 * A duplicate cluster = >1 non-superseded unit-session row sharing
 * (run_session_id, run_unit_id, iteration). Because UnitSession::create()
 * increments `iteration` behind the run-session lock, legitimate loop /
 * SkipBackward re-entries always get DISTINCT iterations; only the
 * non-atomic MAX(iteration)+1 race collides on an identical tuple, so a
 * collision is a duplicate — never a loop pass. Wait extends Pause and
 * shares this create() path, so both types are in scope.
 *
 * classify() decides, per cluster, one of:
 *   - 'noop'   : nothing to do (<=1 live row; extras already superseded)
 *   - 'heal'   : supersede the spurious LIVE rows (never ended -> never
 *                cascaded -> zero side effects), keep the canonical, and
 *                repoint the run session's current pointer if it sat on a
 *                superseded row and an unambiguous target exists
 *   - 'review' : anything requiring a human (terminal/cascaded siblings,
 *                legacy NULL-iteration ambiguity, no safe repoint target)
 */
class DuplicatePauseHealer {

    /**
     * @param array    $considered       cluster rows (queued != SUPERSEDED),
     *                                    ordered by created ASC. Each row:
     *                                    id, created, expires, ended, expired,
     *                                    queued, placement_position, is_current.
     * @param bool     $legacy           true for NULL-iteration (legacy) clusters
     * @param callable $frontierResolver fn(int $canonicalPos, int[] $excludeIds)
     *                                    => ['id'=>int,'position'=>mixed]|null
     *                                    Resolves a live downstream frontier when
     *                                    the current pointer sits on a spurious row
     *                                    and the canonical is already terminal.
     * @return array  ['action'=>'noop'|'heal'|'review', ...]
     */
    public static function classify(array $considered, bool $legacy, callable $frontierResolver): array {
        if (count($considered) <= 1) {
            return ['action' => 'noop'];
        }

        // Legacy NULL-iteration rows can't use the same-iteration invariant:
        // legitimate loop re-entries also share a NULL iteration, so a
        // collision does NOT prove a race duplicate. Human only.
        if ($legacy) {
            return ['action' => 'review',
                    'reason' => 'legacy NULL-iteration cluster — cannot tell race-duplicate from loop re-entry without run structure'];
        }

        $isTerminal = fn($r) => $r['ended'] !== null || $r['expired'] !== null;
        $terminals = array_values(array_filter($considered, $isTerminal));
        $lives     = array_values(array_filter($considered, fn($r) => !$isTerminal($r)));

        // Two cascaded siblings: both may have fired real Email/Push sends
        // or collected answers. Never auto-collapse.
        if (count($terminals) >= 2) {
            return ['action' => 'review',
                    'reason' => count($terminals) . ' terminal (cascaded) siblings — possible double send'];
        }

        // Canonical = the sole terminal traversal, else the earliest live arrival.
        $canonical = count($terminals) === 1 ? $terminals[0] : $lives[0];
        $canonLive = !$isTerminal($canonical);
        $spurious  = array_values(array_filter($considered, fn($r) => (int) $r['id'] !== (int) $canonical['id']));

        $supersede = [];
        $repoint   = null;
        foreach ($spurious as $sp) {
            if ($isTerminal($sp)) {
                return ['action' => 'review',
                        'reason' => 'spurious sibling is terminal (already cascaded) — review before removing'];
            }
            if ((int) $sp['queued'] <= 0) {
                return ['action' => 'review',
                        'reason' => 'spurious live sibling has queued<=0 (unexpected state) — review'];
            }
            $supersede[] = (int) $sp['id'];

            if ((int) $sp['is_current'] === 1) {
                if ($canonLive) {
                    $target = ['id' => (int) $canonical['id'], 'position' => $canonical['placement_position']];
                } else {
                    $excl   = array_map(fn($r) => (int) $r['id'], $considered);
                    $target = $frontierResolver($canonical['placement_position'], $excl);
                }
                if ($target === null || $target['position'] === null) {
                    return ['action' => 'review',
                            'reason' => 'current pointer sits on a spurious row with no unambiguous repoint target — review'];
                }
                $repoint = ['cid' => (int) $target['id'], 'pos' => $target['position']];
            }
        }

        return ['action' => 'heal', 'canonical_id' => (int) $canonical['id'],
                'supersede_ids' => $supersede, 'repoint' => $repoint];
    }
}
