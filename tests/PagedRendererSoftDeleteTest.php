<?php

/**
 * Patch 112 turned "remove an item from a study" from a hard
 * `DELETE FROM survey_items` (whose FK cascade also removed the item's
 * survey_items_display rows) into a soft delete. The display rows now survive
 * forever with `answered` NULL, because UnitSession::updateSurveyStudyRecord
 * loads names through SurveyStudy::getItems(), which excludes deleted items,
 * and skips anything it doesn't recognise.
 *
 * PagedSpreadsheetRenderer's page bookkeeping read survey_items_display without
 * ever joining survey_items, so those orphan rows kept counting: a participant
 * who was mid-survey when an item was removed got redirected back to that item's
 * page by emptyPreviousPageExists() on every single request, with no way out.
 *
 * These tests pin the `deleted IS NULL` filter into all four reads.
 */
class PagedRendererSoftDeleteTest extends \PHPUnit\Framework\TestCase {

    private const STUDY_ID = 7;
    private const SESSION_ID = 42;

    /** @var DB */
    private $db;

    protected function setUp(): void {
        $this->db = DB::getInstance();
        $this->dropTables();

        // column set is the union of what getPageItems() selects and what the
        // page bookkeeping filters on
        $this->db->exec('CREATE TABLE survey_items (
            id INTEGER PRIMARY KEY, study_id INTEGER, name TEXT, type TEXT,
            choice_list TEXT, type_options TEXT, label TEXT, label_parsed TEXT,
            optional INTEGER DEFAULT 0, class TEXT, showif TEXT, value TEXT,
            "order" INTEGER, deleted TEXT DEFAULT NULL)');
        $this->db->exec('CREATE TABLE survey_items_display (
            id INTEGER PRIMARY KEY, session_id INTEGER, item_id INTEGER,
            page INTEGER, display_order INTEGER, displaycount INTEGER,
            hidden INTEGER DEFAULT NULL, answer TEXT DEFAULT NULL,
            answered TEXT DEFAULT NULL)');
    }

    protected function tearDown(): void {
        $this->dropTables();
    }

    private function dropTables(): void {
        foreach (array('survey_items', 'survey_items_display') as $t) {
            $this->db->exec("DROP TABLE IF EXISTS $t");
        }
    }

    /**
     * @param array $items id => [name, page, answered(bool), deleted(bool)]
     */
    private function seed(array $items): void {
        $order = 0;
        foreach ($items as $id => $spec) {
            $deleted = !empty($spec['deleted']) ? "'2026-08-31 12:00:00'" : 'NULL';
            $answered = !empty($spec['answered']) ? "'2026-08-31 12:00:00'" : 'NULL';
            $study = self::STUDY_ID;
            $session = self::SESSION_ID;
            $page = (int) $spec['page'];
            $order++;
            $this->db->exec("INSERT INTO survey_items (id, study_id, name, type, \"order\", deleted)
                VALUES ($id, $study, '{$spec['name']}', 'text', $order, $deleted)");
            $this->db->exec("INSERT INTO survey_items_display (session_id, item_id, page, display_order, answered)
                VALUES ($session, $id, $page, $order, $answered)");
        }
    }

    /**
     * The renderer's constructor wants a live UnitSession graph (run session,
     * run, validated items). None of that is exercised by the page bookkeeping,
     * so build the object without it and inject only what the SQL reads.
     */
    private function renderer(): PagedSpreadsheetRenderer {
        $class = new ReflectionClass(PagedSpreadsheetRenderer::class);
        $renderer = $class->newInstanceWithoutConstructor();

        $unitSession = new stdClass();
        $unitSession->id = self::SESSION_ID;
        $study = new stdClass();
        $study->id = self::STUDY_ID;

        foreach (array('db' => $this->db, 'unitSession' => $unitSession, 'study' => $study) as $prop => $value) {
            $p = new ReflectionProperty(SpreadsheetRenderer::class, $prop);
            $p->setAccessible(true);
            $p->setValue($renderer, $value);
        }
        return $renderer;
    }

    private function call(string $method, ...$args) {
        $m = new ReflectionMethod(PagedSpreadsheetRenderer::class, $method);
        $m->setAccessible(true);
        return $m->invokeArgs($this->renderer(), $args);
    }

    public function testEmptyPreviousPageStillBouncesOnALiveUnansweredItem() {
        $this->seed(array(
            1 => array('name' => 'q1', 'page' => 1),
            2 => array('name' => 'q2', 'page' => 2, 'answered' => true),
        ));
        $this->assertSame(1, (int) $this->call('emptyPreviousPageExists', 2),
                'a genuinely unanswered earlier page must still send the participant back');
    }

    public function testSoftDeletedItemNoLongerTrapsTheParticipantOnItsPage() {
        $this->seed(array(
            1 => array('name' => 'q1', 'page' => 1, 'answered' => true),
            // removed from the study mid-session: never rendered, never posted,
            // so `answered` stays NULL for the life of the session
            2 => array('name' => 'gone', 'page' => 1, 'deleted' => true),
            3 => array('name' => 'q3', 'page' => 2),
        ));
        $this->assertFalse($this->call('emptyPreviousPageExists', 2),
                'a soft-deleted item must not count as an unanswered previous page');
    }

    public function testCurrentPageSkipsPagesWhoseOnlyGapIsASoftDeletedItem() {
        $this->seed(array(
            1 => array('name' => 'q1', 'page' => 1, 'answered' => true),
            2 => array('name' => 'gone', 'page' => 1, 'deleted' => true),
            3 => array('name' => 'q3', 'page' => 3),
        ));
        $this->assertSame(3, (int) $this->call('getCurrentPage'),
                'the first page with a live unanswered item is page 3, not page 1');
    }

    public function testMaxPageIgnoresPagesLeftWithOnlySoftDeletedItems() {
        $this->seed(array(
            1 => array('name' => 'q1', 'page' => 1),
            2 => array('name' => 'q2', 'page' => 2),
            3 => array('name' => 'gone', 'page' => 3, 'deleted' => true),
        ));
        $this->assertSame(2, (int) $this->call('getMaxPage'),
                'a page whose items were all removed must not extend the survey');
    }

    public function testMaxPageIsNullWhenEveryItemIsSoftDeleted() {
        $this->seed(array(
            1 => array('name' => 'gone1', 'page' => 1, 'deleted' => true),
            2 => array('name' => 'gone2', 'page' => 2, 'deleted' => true),
        ));
        // falsy => processItems() treats the study as completed rather than
        // walking the participant through pages that can never be submitted
        $this->assertEmpty($this->call('getMaxPage'));
    }

    public function testPageItemsQueryExcludesSoftDeletedItems() {
        $this->seed(array(
            1 => array('name' => 'q1', 'page' => 1),
            2 => array('name' => 'gone', 'page' => 1, 'deleted' => true),
        ));
        // $process = false returns the raw Item map without any OpenCPU work
        $items = $this->call('getPageItems', 1, false);
        $this->assertSame(array('q1'), array_keys($items));
    }

}
