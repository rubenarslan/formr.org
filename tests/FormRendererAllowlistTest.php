<?php

use PHPUnit\Framework\TestCase;

/**
 * RAllowlistExtractor::ensure — the bulk allowlist path used by FormRenderer.
 *
 * survey_r_calls is a pure function of the study definition (one row per
 * dynamic value / label item), and every participant render asks for it:
 * ensure() does one SELECT per study plus an INSERT for genuinely missing
 * rows only, so a steady-state render performs zero writes.
 *
 * The DB is mocked rather than seeded: the statements under test are MariaDB-
 * specific (INSERT ... ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)) and
 * the unit lane runs on SQLite. What matters here is *which* statements are
 * issued, which is exactly what the mock records.
 */
class FormRendererAllowlistTest extends TestCase
{
    /** @var string[] SQL passed to DB::prepare, in order */
    private $sqls = array();

    /**
     * @param array $existingRows rows survey_r_calls already holds for the study
     * @param array $insertIds ids handed back by lastInsertId(), in order
     */
    private function dbMock(array $existingRows, array $insertIds = array(99))
    {
        $this->sqls = array();

        $cursor = 0;
        $selectStmt = $this->createMock(PDOStatement::class);
        $selectStmt->method('fetch')->willReturnCallback(function () use ($existingRows, &$cursor) {
            return isset($existingRows[$cursor]) ? $existingRows[$cursor++] : false;
        });
        $insertStmt = $this->createMock(PDOStatement::class);

        $sqls = &$this->sqls;
        $db = $this->createMock(DB::class);
        $db->method('prepare')->willReturnCallback(function ($sql) use (&$sqls, $selectStmt, $insertStmt) {
            $sqls[] = $sql;
            return stripos($sql, 'INSERT') !== false ? $insertStmt : $selectStmt;
        });
        $ids = $insertIds;
        $idCursor = 0;
        $db->method('lastInsertId')->willReturnCallback(function () use ($ids, &$idCursor) {
            $id = isset($ids[$idCursor]) ? $ids[$idCursor] : end($ids);
            $idCursor++;
            return $id;
        });
        return $db;
    }

    private function row($id, $slot, $itemId, $expr)
    {
        return array(
            'id' => $id,
            'slot' => $slot,
            'item_id' => $itemId,
            'expr_hash' => hash('sha256', $expr),
        );
    }

    private function insertCount()
    {
        $n = 0;
        foreach ($this->sqls as $sql) {
            if (stripos($sql, 'INSERT') !== false) {
                $n++;
            }
        }
        return $n;
    }

    /** THE regression: an already-recorded call must not be written again. */
    public function testExistingRowsAreReusedWithoutWriting()
    {
        $db = $this->dbMock(array($this->row(7, 'value', 11, 'nrow(mystudy)')));

        $out = RAllowlistExtractor::ensure($db, 42, array(
            array('slot' => 'value', 'expr' => 'nrow(mystudy)', 'item_id' => 11),
        ));

        $key = RAllowlistExtractor::callKey('value', 11, hash('sha256', 'nrow(mystudy)'));
        $this->assertSame(array($key => 7), $out);
        $this->assertCount(1, $this->sqls, 'one SELECT, no writes');
        $this->assertSame(0, $this->insertCount());
        $this->assertStringContainsString('SELECT', $this->sqls[0]);
    }

    /** Only the missing row is inserted; the present one keeps its id. */
    public function testOnlyMissingRowsAreInserted()
    {
        $db = $this->dbMock(array($this->row(7, 'value', 11, 'nrow(mystudy)')), array(31));

        $out = RAllowlistExtractor::ensure($db, 42, array(
            array('slot' => 'value', 'expr' => 'nrow(mystudy)', 'item_id' => 11),
            array('slot' => 'label', 'expr' => 'Hi `r name`', 'item_id' => 12),
        ));

        $valueKey = RAllowlistExtractor::callKey('value', 11, hash('sha256', 'nrow(mystudy)'));
        $labelKey = RAllowlistExtractor::callKey('label', 12, hash('sha256', 'Hi `r name`'));
        $this->assertSame(7, $out[$valueKey]);
        $this->assertSame(31, $out[$labelKey]);
        $this->assertSame(1, $this->insertCount());
    }

    /** Identical (slot, item, expr) requests collapse to one row. */
    public function testDuplicateRequestsCollapse()
    {
        $db = $this->dbMock(array(), array(31));

        $out = RAllowlistExtractor::ensure($db, 42, array(
            array('slot' => 'value', 'expr' => 'nrow(x)', 'item_id' => 11),
            array('slot' => 'value', 'expr' => 'nrow(x)', 'item_id' => 11),
        ));

        $this->assertCount(1, $out);
        $this->assertSame(1, $this->insertCount());
    }

    /** Same expression on two items stays two rows (see the UNIQUE index). */
    public function testSameExpressionOnTwoItemsStaysTwoRows()
    {
        $db = $this->dbMock(array(), array(31, 32));

        $out = RAllowlistExtractor::ensure($db, 42, array(
            array('slot' => 'value', 'expr' => 'nrow(x)', 'item_id' => 11),
            array('slot' => 'value', 'expr' => 'nrow(x)', 'item_id' => 12),
        ));

        $this->assertCount(2, $out);
        $this->assertSame(2, $this->insertCount());
        $this->assertNotSame(
            RAllowlistExtractor::callKey('value', 11, hash('sha256', 'nrow(x)')),
            RAllowlistExtractor::callKey('value', 12, hash('sha256', 'nrow(x)'))
        );
    }

    public function testEnsureInsertDoesNotBumpModified()
    {
        $db = $this->dbMock(array(), array(31));
        RAllowlistExtractor::ensure($db, 42, array(
            array('slot' => 'value', 'expr' => 'nrow(x)', 'item_id' => 11),
        ));
        $insert = $this->sqls[1];
        $this->assertStringContainsString('LAST_INSERT_ID(id)', $insert);
        $this->assertStringNotContainsString('modified', $insert);
    }

    public function testEnsureWithNoCallsTouchesNothing()
    {
        $db = $this->dbMock(array());
        $this->assertSame(array(), RAllowlistExtractor::ensure($db, 42, array()));
        $this->assertCount(0, $this->sqls);
    }
}
