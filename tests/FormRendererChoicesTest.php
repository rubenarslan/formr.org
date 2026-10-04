<?php

use PHPUnit\Framework\TestCase;

/**
 * FormRenderer::attachStaticChoices gives each item its choice list. Choice
 * labels are Markdown, rendered to HTML for radio and checkbox labels — but a
 * select_or_add offers its labels as the answers themselves: tom-select shows
 * them as text, and on v2 a one-cell list is split at ";"
 * (SelectOrAddOne_Item::unpackChoices). Handed the HTML, "Tom & Jerry; Mickey"
 * was cut inside "&amp;" and showed the entity.
 */
class FormRendererChoicesTest extends TestCase
{
    private function attach(array $items, array $rows)
    {
        $study = new class($rows) extends SurveyStudy {
            private $rows;
            public function __construct($rows) { $this->rows = $rows; }
            public function getChoices($specific = null, $label = 'label') { return $this->rows; }
        };
        $renderer = (new ReflectionClass(FormRenderer::class))->newInstanceWithoutConstructor();
        $prop = new ReflectionProperty(SpreadsheetRenderer::class, 'study');
        $prop->setAccessible(true);
        $prop->setValue($renderer, $study);
        $attach = new ReflectionMethod(FormRenderer::class, 'attachStaticChoices');
        $attach->setAccessible(true);
        $attach->invokeArgs($renderer, array(&$items));
        return $items;
    }

    public function testASelectOrAddOffersItsLabelsAsWritten()
    {
        $add = new SelectOrAddOne_Item(array('type' => 'select_or_add_one', 'name' => 'pick', 'id' => 1, 'choice_list' => 'toons'));
        $add->formV2 = true;
        $mc = new Mc_Item(array('type' => 'mc', 'name' => 'radio', 'id' => 2, 'choice_list' => 'rd'));
        $items = $this->attach(array('pick' => $add, 'radio' => $mc), array(
            array('list_name' => 'toons', 'name' => 'toons', 'label' => 'Tom & Jerry; Mickey', 'label_parsed' => 'Tom &amp; Jerry; Mickey'),
            array('list_name' => 'rd', 'name' => '1', 'label' => 'R&D', 'label_parsed' => 'R&amp;D'),
        ));
        $this->assertSame(array('Tom & Jerry', 'Mickey'), $items['pick']->choices);
        // A radio label stays HTML.
        $this->assertSame(array('1' => 'R&amp;D'), $items['radio']->choices);
    }
}
