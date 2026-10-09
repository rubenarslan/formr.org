<?php
use PHPUnit\Framework\TestCase;

/**
 * An mc_multiple / select_multiple posts its hidden '' placeholder before
 * the ticked keys. Mc_Item::validateInput skipped the choice check whenever
 * the first unknown value was that '', i.e. on every real post, so any value
 * after it was accepted and stored.
 */
class McMultipleChoiceCheckTest extends TestCase
{
    private function make()
    {
        return new McMultiple_Item(array(
            'type' => 'mc_multiple', 'name' => 'pets', 'id' => 3, 'optional' => 1,
            'choice_list' => 'pets', 'choices' => array('1' => 'Cat', '2' => 'Dog', '3' => 'Fish'),
        ));
    }

    public function testAnUnknownChoiceAfterThePlaceholderIsRefused()
    {
        $item = $this->make();
        $item->validateInput(array('', '1', '999'));
        $this->assertNotEmpty($item->error);
        $this->assertStringContainsString('999', (string) $item->error);
    }

    public function testThePlaceholderAndKnownChoicesPass()
    {
        foreach (array(array('', '1', '3'), array(''), array('2')) as $reply) {
            $item = $this->make();
            $item->validateInput($reply);
            $this->assertEmpty($item->error, implode(',', $reply));
        }
    }
}
