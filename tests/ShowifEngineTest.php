<?php
use PHPUnit\Framework\TestCase;

/**
 * A showif per engine: v1's live preview translates the R idioms into JS
 * (Item::showifToJs; the server evaluates the R), form_v2 uses the column as
 * written — it is JavaScript (Item::useVerbatimShowif, called by
 * FormRenderer on every item it renders).
 */
class ShowifEngineTest extends TestCase {

    private function item($showif) {
        return (new ItemFactory(array()))->make(array('name' => 'q2', 'type' => 'text', 'showif' => $showif));
    }

    public function testV1TranslatesTheRIdioms() {
        $this->assertSame("q1 == 1 &(typeof(q3) === 'undefined') | true",
            $this->item('q1 == 1 & is.na(q3) | TRUE')->js_showif);
        $this->assertSame('q1.toString().indexOf("a") > -1', $this->item('current(q1) %contains% "a"')->js_showif);
    }

    public function testV2UsesTheShowifAsWritten() {
        $item = $this->item('q1 == 1 & is.na(q3)');
        $item->useVerbatimShowif();
        $this->assertSame('q1 == 1 & is.na(q3)', $item->js_showif);

        $blank = $this->item('  ');
        $blank->useVerbatimShowif();
        $this->assertNull($blank->js_showif);
    }

    public function testJsOnlyMarkerStillHidesTheItemFromTheServer() {
        $this->assertTrue($this->item('q1 > 2 //js_only')->hidden);
    }
}
