<?php

/**
 * Regression coverage for the duplicate Pause/Wait healer decision logic
 * (v0.27.0, Services/DuplicatePauseHealer).
 *
 * Duplicates arise when UnitSession::create()'s non-atomic
 * MAX(iteration)+1 races on the non-unique idx_run_unit_iter, producing
 * >1 row on the same (run_session, run_unit, iteration). The healer keeps
 * a canonical row and supersedes spurious LIVE siblings (never ended ->
 * never cascaded -> no side effects), repointing the run session's current
 * pointer only to an unambiguous target; anything cascaded/ambiguous is
 * held for review. These tests pin each branch of that decision. The
 * classifier is pure (no DB), so it runs in the SQLite unit lane.
 */
class DuplicatePauseHealerTest extends \PHPUnit\Framework\TestCase
{
    /** A live, queued pause row at placement position 201, overridable. */
    private function row(array $over = []): array
    {
        return array_merge([
            'id' => 1, 'created' => '2026-05-01 10:30:00', 'expires' => '2026-06-01 10:00:00',
            'ended' => null, 'expired' => null, 'queued' => 2,
            'placement_position' => 201, 'is_current' => 0,
        ], $over);
    }

    private function noFrontier(): callable
    {
        return function () { $this->fail('frontier resolver must not be called here'); };
    }

    public function testSingleConsideredRowIsNoop(): void
    {
        $d = DuplicatePauseHealer::classify([$this->row()], false, $this->noFrontier());
        $this->assertSame('noop', $d['action']);
    }

    public function testLegacyNullIterationClusterGoesToReview(): void
    {
        $rows = [$this->row(['id' => 1]), $this->row(['id' => 2])];
        $d = DuplicatePauseHealer::classify($rows, true, $this->noFrontier());
        $this->assertSame('review', $d['action']);
        $this->assertStringContainsString('legacy', $d['reason']);
    }

    public function testTwoTerminalSiblingsGoToReview(): void
    {
        $rows = [
            $this->row(['id' => 1, 'ended' => '2026-06-01 10:00:00', 'queued' => 0]),
            $this->row(['id' => 2, 'expired' => '2026-07-01 10:00:00', 'queued' => 0]),
        ];
        $d = DuplicatePauseHealer::classify($rows, false, $this->noFrontier());
        $this->assertSame('review', $d['action']);
        $this->assertStringContainsString('terminal', $d['reason']);
    }

    public function testSupersedesLiveSpuriousThatIsNotCurrent(): void
    {
        $rows = [
            $this->row(['id' => 10, 'created' => '2026-05-01 10:30:00']),   // canonical (earliest live)
            $this->row(['id' => 11, 'created' => '2026-05-02 09:00:00']),   // spurious, not current
        ];
        $d = DuplicatePauseHealer::classify($rows, false, $this->noFrontier());
        $this->assertSame('heal', $d['action']);
        $this->assertSame(10, $d['canonical_id']);
        $this->assertSame([11], $d['supersede_ids']);
        $this->assertNull($d['repoint']);
    }

    /** The classic AMOR re-park: the later live duplicate is current and
     *  re-armed; supersede it and repoint to the earliest (correct) sibling. */
    public function testRepointsToLiveCanonicalWhenCurrentSitsOnSpurious(): void
    {
        $rows = [
            $this->row(['id' => 10, 'created' => '2026-05-01 10:30:00', 'expires' => '2026-06-01 10:00:00']),
            $this->row(['id' => 11, 'created' => '2026-06-01 11:00:00', 'expires' => '2026-07-06 10:00:00', 'is_current' => 1]),
        ];
        $d = DuplicatePauseHealer::classify($rows, false, $this->noFrontier());
        $this->assertSame('heal', $d['action']);
        $this->assertSame(10, $d['canonical_id']);
        $this->assertSame([11], $d['supersede_ids']);
        $this->assertSame(['cid' => 10, 'pos' => 201], $d['repoint']);
    }

    /** Canonical already ended (cascaded) and the live current dup is a
     *  backward re-park: repoint to the single live downstream frontier. */
    public function testRepointsToDownstreamFrontierWhenCanonicalTerminal(): void
    {
        $rows = [
            $this->row(['id' => 10, 'ended' => '2026-06-01 10:00:00', 'queued' => 0]),  // canonical (terminal)
            $this->row(['id' => 11, 'is_current' => 1]),                                // live re-park, current
        ];
        $resolver = fn($pos, $excl) => ['id' => 777, 'position' => 213];
        $d = DuplicatePauseHealer::classify($rows, false, $resolver);
        $this->assertSame('heal', $d['action']);
        $this->assertSame([11], $d['supersede_ids']);
        $this->assertSame(['cid' => 777, 'pos' => 213], $d['repoint']);
    }

    public function testReviewWhenCurrentSpuriousHasNoUnambiguousFrontier(): void
    {
        $rows = [
            $this->row(['id' => 10, 'ended' => '2026-06-01 10:00:00', 'queued' => 0]),
            $this->row(['id' => 11, 'is_current' => 1]),
        ];
        $d = DuplicatePauseHealer::classify($rows, false, fn($pos, $excl) => null);
        $this->assertSame('review', $d['action']);
        $this->assertStringContainsString('repoint target', $d['reason']);
    }

    public function testReviewWhenSpuriousLiveHasNonPositiveQueued(): void
    {
        $rows = [
            $this->row(['id' => 10]),
            $this->row(['id' => 11, 'queued' => 0]),   // live but un-queued: don't guess
        ];
        $d = DuplicatePauseHealer::classify($rows, false, $this->noFrontier());
        $this->assertSame('review', $d['action']);
        $this->assertStringContainsString('queued<=0', $d['reason']);
    }

    public function testThreeLiveRowsKeepEarliestSupersedeRest(): void
    {
        $rows = [
            $this->row(['id' => 10, 'created' => '2026-05-01 10:00:00']),
            $this->row(['id' => 11, 'created' => '2026-05-01 10:00:05']),
            $this->row(['id' => 12, 'created' => '2026-05-01 10:00:09']),
        ];
        $d = DuplicatePauseHealer::classify($rows, false, $this->noFrontier());
        $this->assertSame('heal', $d['action']);
        $this->assertSame(10, $d['canonical_id']);
        $this->assertSame([11, 12], $d['supersede_ids']);
    }
}
