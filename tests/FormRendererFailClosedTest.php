<?php

use PHPUnit\Framework\TestCase;

/**
 * Test harness: processItems() with every DB/OpenCPU-bound collaborator stubbed
 * out, so the Step 1 (r(...) in showif) branch can be exercised in the SQLite
 * unit lane.
 */
class FormRendererFailClosedHarness extends FormRenderer
{
    public $stubItems = array();
    public $pageMap = array();
    public $ensuredCalls = array();

    protected function getAllUnansweredItems()
    {
        $this->unanswered = array();
        foreach ($this->stubItems as $name => $item) {
            $this->unanswered[$name] = $item;
        }
        return $this->stubItems;
    }

    protected function fetchPageMap() { return $this->pageMap; }
    protected function processAutomaticItems($items) { return $items; }
    protected function processDynamicValuesAndShowIfs(&$items) { return $items; }
    protected function processDynamicLabelsAndChoices(&$items) { return $items; }
    protected function attachStaticChoices(array &$items) {}
    protected function getRenderedStudyItems() { return array_values($this->toRender); }

    protected function ensureRCalls(array $calls)
    {
        $this->ensuredCalls = $calls;
        return array();
    }

    public function peekToRender() { return $this->toRender; }
    public function peekUnanswered() { return $this->unanswered; }
    public function peekValidationErrors() { return $this->validationErrors; }
}

/**
 * `r(...)` in a v2 `showif` must FAIL CLOSED.
 *
 * The renderer used to blank showif + js_showif and keep the item — which
 * leaves it with no condition at all, i.e. unconditionally VISIBLE. An item the
 * author gated behind an R condition was therefore shown to every participant,
 * and the author-facing explanation was rendered into the participant's page
 * (banner + item error). Now the item is hidden and pruned like a definite-
 * FALSE showif, and the explanation is author-facing only.
 */
class FormRendererFailClosedTest extends TestCase
{
    /** @var DB&PHPUnit\Framework\MockObject\MockObject */
    private $db;

    private function harness($authorFacing)
    {
        $runSession = $this->createMock(RunSession::class);
        $runSession->method('isTestingStudy')->willReturn($authorFacing);
        $runSession->method('isTesting')->willReturn(false);
        $runSession->method('isCron')->willReturn(false);
        $runSession->method('getRun')->willReturn($this->createMock(Run::class));

        $this->db = $this->createMock(DB::class);

        $unitSession = $this->createMock(UnitSession::class);
        $unitSession->runSession = $runSession;
        $unitSession->validatedStudyItems = array();
        $unitSession->method('getDbConnection')->willReturn($this->db);

        $study = $this->createMock(SurveyStudy::class);
        $study->id = 42;
        $study->name = 'teststudy';
        $study->layout = 'default';

        return new FormRendererFailClosedHarness($study, $unitSession);
    }

    private function item(array $row)
    {
        $factory = new ItemFactory(array());
        return $factory->make(array_merge(array('type' => 'text', 'id' => 1), $row));
    }

    /** THE regression: an r() showif must hide its item, not reveal it. */
    public function testRShowifHidesAndPrunesTheItem()
    {
        $harness = $this->harness(false);
        $gated = $this->item(array('id' => 5, 'name' => 'secret', 'showif' => 'r(nrow(x) > 0)'));
        $plain = $this->item(array('id' => 6, 'name' => 'always'));
        $harness->stubItems = array('secret' => $gated, 'always' => $plain);
        $harness->pageMap = array(5 => 1, 6 => 1);

        $this->db->expects($this->once())
            ->method('batchUpdateByKey')
            ->with('survey_items_display', 'item_id', array(5 => array('hidden' => 1)));

        $harness->processItems();

        $this->assertArrayNotHasKey('secret', $harness->peekToRender(), 'gated item must not render');
        $this->assertArrayNotHasKey('secret', $harness->peekUnanswered(), 'and must not block completion');
        $this->assertArrayHasKey('always', $harness->peekToRender());
        $this->assertTrue((bool) $gated->hidden);
        $this->assertSame('', $gated->showif);
    }

    /** Participants get no author-facing diagnostics (banner or item error). */
    public function testParticipantSeesNoValidationError()
    {
        $harness = $this->harness(false);
        $harness->stubItems = array(
            'secret' => $this->item(array('id' => 5, 'name' => 'secret', 'showif' => 'r(TRUE)')),
        );
        $harness->pageMap = array(5 => 1);

        $harness->processItems();

        $this->assertSame(array(), $harness->peekValidationErrors());
    }

    /** Authors do — that is how the study gets fixed. */
    public function testAuthorSeesTheValidationError()
    {
        $harness = $this->harness(true);
        $harness->stubItems = array(
            'secret' => $this->item(array('id' => 5, 'name' => 'secret', 'showif' => 'r(TRUE)')),
        );
        $harness->pageMap = array(5 => 1);

        $harness->processItems();

        $errors = $harness->peekValidationErrors();
        $this->assertArrayHasKey('secret', $errors);
        $this->assertStringContainsString('r(...) is no longer supported', $errors['secret']);
    }

    /** A plain JS showif is untouched — no hiding, no error, no write. */
    public function testJsShowifIsLeftAlone()
    {
        $harness = $this->harness(false);
        $item = $this->item(array('id' => 7, 'name' => 'followup', 'showif' => 'mc == 1'));
        $harness->stubItems = array('followup' => $item);
        $harness->pageMap = array(7 => 1);

        $this->db->expects($this->never())->method('batchUpdateByKey');

        $harness->processItems();

        $this->assertArrayHasKey('followup', $harness->peekToRender());
        $this->assertSame('mc == 1', $item->showif);
        $this->assertSame(array(), $harness->peekValidationErrors());
    }
}
