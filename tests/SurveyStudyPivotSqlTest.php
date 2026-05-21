<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for SurveyStudy::buildPivotSql — the pure SQL-building helper
 * underlying the form_v2 read-side pivot from survey_items_display into
 * the wide-shape output.
 *
 * The full pivot path (pivotedResultsStatement → buildPivotSql →
 * prepare → execute) needs live MariaDB to exercise; the SQL-shape
 * coverage here doesn't, so it stays in the default unit lane.
 *
 * Contract under test:
 *  - One MAX(CASE WHEN item_id = X THEN answer END) column per scorable
 *    item, aliased to the item's name (backtick-escaped).
 *  - Linked mode emits session metadata first, then item columns, in
 *    the same order getResults's wide path does.
 *  - Unlinked mode emits item columns only and orders by RAND().
 *  - Iteration comes from survey_unit_sessions.study_iteration with a
 *    ROW_NUMBER() COALESCE fallback for backfill-pending rows.
 *  - Filters (session prefix/eq, run_id) bind parameters and append to
 *    the WHERE clause; do not interpolate.
 *  - Pagination order_by is allowlisted; unknown keys silently fall
 *    back to session_id (no SQL-injection sink).
 */
class SurveyStudyPivotSqlTest extends TestCase
{
    private function items()
    {
        return array(
            101 => 'age',
            102 => 'mood',
            103 => 'has_pet',
        );
    }

    public function testLinkedModeEmitsMetadataAndPivotColumns(): void
    {
        list($sql, $bindings) = SurveyStudy::buildPivotSql(
            42,
            $this->items(),
            false, // unlinked
            true,  // get_all
            null,  // filter
            null,  // paginate
            null   // runId
        );

        $this->assertStringContainsString('`rs`.`session` AS `session`', $sql);
        $this->assertStringContainsString('`us`.`id` AS `session_id`', $sql);
        $this->assertStringContainsString("MIN(`sid`.`created`) AS `created`", $sql);
        $this->assertStringContainsString("MAX(`sid`.`saved`) AS `modified`", $sql);
        $this->assertStringContainsString("`us`.`ended` AS `ended`", $sql);
        $this->assertStringContainsString("`us`.`expired` AS `expired`", $sql);

        $this->assertStringContainsString(
            "MAX(CASE WHEN `sid`.`item_id` = 101 THEN `sid`.`answer` END) AS `age`",
            $sql
        );
        $this->assertStringContainsString(
            "MAX(CASE WHEN `sid`.`item_id` = 102 THEN `sid`.`answer` END) AS `mood`",
            $sql
        );
        $this->assertStringContainsString(
            "MAX(CASE WHEN `sid`.`item_id` = 103 THEN `sid`.`answer` END) AS `has_pet`",
            $sql
        );

        $this->assertSame(array(':study_id' => 42), $bindings);
    }

    public function testIterationReadsFromUnitSessionStudyIteration(): void
    {
        list($sql, ) = SurveyStudy::buildPivotSql(
            42, $this->items(), false, true, null, null, null
        );

        // The expression is a COALESCE: prefer the long-form column,
        // fall back to ROW_NUMBER() when the backfill hasn't reached
        // a particular row yet.
        $this->assertStringContainsString('`us`.`study_iteration`', $sql);
        $this->assertStringContainsString('ROW_NUMBER() OVER (ORDER BY `us`.`id` ASC)', $sql);
        $this->assertStringContainsString('AS `iteration`', $sql);

        // No LEFT JOIN to a per-study results table — iteration is
        // self-contained in survey_unit_sessions now.
        $this->assertDoesNotMatchRegularExpression('/LEFT JOIN `survey_\d+`/', $sql);
        $this->assertStringNotContainsString('`wide`.', $sql);
    }

    public function testUnlinkedModeStripsMetadataAndRandomizes(): void
    {
        list($sql, ) = SurveyStudy::buildPivotSql(
            42, $this->items(), true, true, null, null, null
        );

        $this->assertStringNotContainsString('`rs`.`session` AS `session`', $sql);
        $this->assertStringNotContainsString('`us`.`id` AS `session_id`', $sql);
        $this->assertStringNotContainsString('AS `iteration`', $sql);
        $this->assertStringContainsString('ORDER BY RAND()', $sql);
        // Item columns survive.
        $this->assertStringContainsString("AS `age`", $sql);
    }

    public function testUnlinkedTestingOnlyAppendsTestingFilter(): void
    {
        list($sql, ) = SurveyStudy::buildPivotSql(
            42, $this->items(), true, false, null, null, null
        );

        $this->assertStringContainsString('`rs`.`testing` = 1', $sql);
    }

    public function testSessionPrefixFilterBindsParameter(): void
    {
        list($sql, $bindings) = SurveyStudy::buildPivotSql(
            42,
            $this->items(),
            false, true,
            array('session' => 'XYZP'),
            null, null
        );

        $this->assertStringContainsString('`rs`.`session` LIKE :session_like', $sql);
        $this->assertSame('XYZP%', $bindings[':session_like']);
    }

    public function testSessionFullCodeFilterBindsEqualityParameter(): void
    {
        $code = str_repeat('a', 64);
        list($sql, $bindings) = SurveyStudy::buildPivotSql(
            42,
            $this->items(),
            false, true,
            array('session' => $code),
            null, null
        );

        $this->assertStringContainsString('`rs`.`session` = :session_eq', $sql);
        $this->assertSame($code, $bindings[':session_eq']);
    }

    public function testRunIdFilterBindsParameter(): void
    {
        list($sql, $bindings) = SurveyStudy::buildPivotSql(
            42, $this->items(), false, true, null, null, 7
        );

        $this->assertStringContainsString('`rs`.`run_id` = :run_id', $sql);
        $this->assertSame(7, $bindings[':run_id']);
    }

    public function testPaginationAllowlistsOrderColumn(): void
    {
        list($sql, ) = SurveyStudy::buildPivotSql(
            42, $this->items(), false, true, null,
            array('offset' => 0, 'limit' => 50, 'order' => 'desc', 'order_by' => 'created'),
            null
        );

        $this->assertStringContainsString("ORDER BY MIN(`sid`.`created`) DESC", $sql);
        $this->assertStringContainsString('LIMIT 50 OFFSET 0', $sql);
    }

    public function testPaginationStripsTablePrefixFromOrderKey(): void
    {
        // The wide caller passes order_by="survey_42.session_id";
        // the pivot should strip the prefix and allowlist the suffix.
        list($sql, ) = SurveyStudy::buildPivotSql(
            42, $this->items(), false, true, null,
            array('offset' => 0, 'limit' => 50, 'order_by' => 'survey_42.session_id'),
            null
        );

        $this->assertStringContainsString('ORDER BY `us`.`id` ASC', $sql);
    }

    public function testPaginationFallsBackToSessionIdForUnknownOrderKey(): void
    {
        // Defends against an attacker-controlled order_by reaching ORDER
        // BY verbatim (the same class of bug DB_Select::order() fixed
        // for the wide path).
        list($sql, ) = SurveyStudy::buildPivotSql(
            42, $this->items(), false, true, null,
            array('offset' => 0, 'limit' => 50, 'order_by' => 'age; DROP TABLE users'),
            null
        );

        $this->assertStringContainsString('ORDER BY `us`.`id`', $sql);
        $this->assertStringNotContainsString('DROP TABLE', $sql);
    }

    public function testItemNamesWithBackticksAreEscaped(): void
    {
        // Hypothetical pathological item name. createResultsTable would
        // refuse it at upload, but the pivot should still be safe.
        list($sql, ) = SurveyStudy::buildPivotSql(
            42,
            array(101 => 'evil`name'),
            false, true, null, null, null
        );

        $this->assertStringContainsString("AS `evil``name`", $sql);
    }

    public function testGroupByIncludesStudyIteration(): void
    {
        list($sql, ) = SurveyStudy::buildPivotSql(
            42, $this->items(), false, true, null, null, null
        );

        // GROUP BY must list study_iteration explicitly so
        // ONLY_FULL_GROUP_BY accepts the SELECT (functional dependency
        // through the unit-session PK isn't always detected).
        $this->assertMatchesRegularExpression(
            '/GROUP BY[^;]*`us`\.`study_iteration`/s',
            $sql
        );
    }
}
