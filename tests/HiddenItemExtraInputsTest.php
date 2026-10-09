<?php
use PHPUnit\Framework\TestCase;

/**
 * Items that render a named input of their own beside the visible one — the
 * visual analog scale's hidden answer input, the geopoint's empty hidden
 * input — must be disabled with the item when a showif hides it
 * (Item::hide, or v2's hideByDefaultPendingClient). Otherwise that input is
 * submitted for an item the participant cannot see, and a required one
 * fails validation: the page cannot be finished.
 */
class HiddenItemExtraInputsTest extends TestCase
{
    private function items()
    {
        return array(
            'vas' => function () {
                return new VisualAnalogScale_Item(array('type' => 'visual_analog_scale', 'name' => 'vas1', 'id' => 9, 'label_parsed' => 'How much?'));
            },
            'geopoint' => function () {
                return new Geopoint_Item(array('type' => 'geopoint', 'name' => 'geo', 'id' => 10, 'label_parsed' => 'Where?'));
            },
        );
    }

    /** Every input of the rendered item that carries an answer (not the _item_views paradata). */
    private function namedInputs($html)
    {
        preg_match_all('/<input[^>]*\bname="(?!_item_views)[^"]+"[^>]*>/', $html, $m);
        $this->assertNotEmpty($m[0], $html);
        return $m[0];
    }

    public function testAHiddenItemSubmitsNothing()
    {
        foreach ($this->items() as $label => $make) {
            foreach (array('hide', 'hideByDefaultPendingClient') as $how) {
                $item = $make();
                $item->$how();
                foreach ($this->namedInputs($item->render()) as $input) {
                    $this->assertStringContainsString('disabled', $input, "{$label}, {$how}");
                }
            }
        }
    }

    public function testAShownItemSubmitsItsAnswer()
    {
        foreach ($this->items() as $label => $make) {
            foreach ($this->namedInputs($make()->render()) as $input) {
                $this->assertStringNotContainsString('disabled', $input, $label);
            }
        }
    }
}
