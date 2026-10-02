<?php

/**
 * form_v2 runs no server-side computation: `calculate` items, and any other
 * item that takes no input but carries R in `value`, are refused
 * (Item::formV2Unsupported). The same rule gates the upload into a v2
 * study, the switch to v2, a Form unit's study link and the compat scan.
 */
class FormV2UnsupportedItemsTest extends \PHPUnit\Framework\TestCase {

    private function item(array $row) {
        return (new ItemFactory(array()))->make($row + array('id' => 1, 'study_id' => 1, 'label' => 'x'));
    }

    public function testCalculateIsRefusedAndPointsToHidden() {
        $why = $this->item(array('name' => 'score', 'type' => 'calculate', 'value' => 'a + b'))->formV2Unsupported();
        $this->assertNotNull($why);
        $this->assertStringContainsString("'score'", $why);
        $this->assertStringContainsString('hidden item', $why);
        $this->assertNotNull($this->item(array('name' => 'c', 'type' => 'calculate'))->formV2Unsupported(), 'even without a value');
    }

    public function testNoInputItemsWithAnRValueAreRefused() {
        $this->assertNotNull($this->item(array('name' => 's', 'type' => 'server', 'value' => '1 + 1'))->formV2Unsupported());
    }

    /**
     * The check only reads the item: FormRenderer, the compat scan and the
     * required check call it on items they then render or judge.
     * needsDynamicValue() would trim the value, write a numeric default into
     * the input and drop the preset of an empty one.
     */
    public function testTheCheckChangesNothing() {
        foreach (array(' 1 + 1 ', ' 5 ', '') as $value) {
            $item = $this->item(array('name' => 's', 'type' => 'server', 'value' => $value));
            $item->presetValue = 'kept';
            $before = array($item->value, $item->presetValue, $item->input_attributes);
            $item->formV2Unsupported();
            $this->assertSame($before, array($item->value, $item->presetValue, $item->input_attributes), var_export($value, true));
        }
    }

    public function testAGetItemsValueNamesAParameterNotR() {
        $this->assertNull($this->item(array('name' => 'src', 'type' => 'get', 'value' => 'source'))->formV2Unsupported());
    }

    public function testWhatTheFormNeedsStaysAllowed() {
        $this->assertNull($this->item(array('name' => 'h', 'type' => 'hidden', 'value' => 'sample(1:2, 1)'))->formV2Unsupported(), 'hidden with R is the v2 path');
        $this->assertNull($this->item(array('name' => 't', 'type' => 'text', 'value' => 'sticky'))->formV2Unsupported());
        $this->assertNull($this->item(array('name' => 's', 'type' => 'server'))->formV2Unsupported(), 'a server item without a value records a server variable');
        $this->assertNull($this->item(array('name' => 'ip', 'type' => 'ip', 'value' => '1'))->formV2Unsupported(), 'a literal value is not R');
        $this->assertNull($this->item(array('name' => 'n', 'type' => 'note'))->formV2Unsupported());
    }
}
