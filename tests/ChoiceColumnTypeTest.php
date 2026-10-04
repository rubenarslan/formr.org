<?php
use PHPUnit\Framework\TestCase;

/**
 * A choice item's results column is typed from its choice codes
 * (Item::chooseResultFieldBasedOnChoices). Under STRICT_ALL_TABLES a code
 * the column cannot hold fails the whole page write, so the type has to
 * grow with the largest code: 999 needs a SMALLINT, a "refused" 99999999 or
 * a date code 20260101 an INT.
 */
class ChoiceColumnTypeTest extends TestCase
{
    private function columnFor(array $codes)
    {
        $choices = array();
        foreach ($codes as $code) {
            $choices[$code] = "label {$code}";
        }
        $item = new Mc_Item(array('type' => 'mc', 'name' => 'q', 'id' => 1, 'choice_list' => 'l', 'choices' => $choices));
        return $item->getResultField();
    }

    public function testTheColumnHoldsTheLargestCode()
    {
        $cases = array(
            'TINYINT UNSIGNED' => array(1, 2, 3),
            'SMALLINT UNSIGNED' => array(1, 2, 999),
            'MEDIUMINT UNSIGNED' => array(1, 40000),
            'INT UNSIGNED' => array(1, 2, 99999999),
            'INT UNSIGNED ' => array(20260101, 20261231),
            'BIGINT UNSIGNED' => array(1, 5000000000),
            'INT DEFAULT' => array(-9000000, 1),
            'FLOAT UNSIGNED' => array('0.5', 1),
        );
        foreach ($cases as $type => $codes) {
            $this->assertStringStartsWith('`q` ' . trim($type) . ' ', $this->columnFor($codes), implode(',', $codes));
        }
    }
}
