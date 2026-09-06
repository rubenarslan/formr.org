<?php

/**
 * A client can post `name[]=x` against any item. The scalar items used to
 * hand the array straight to trim() / strlen() / filter_var() / strtotime()
 * / preg_match() / htmlspecialchars(), which TypeErrors on PHP 8 and turned
 * the survey page into a 500. They reject the reply with an error instead —
 * and `number` keeps its own message for a required item rather than the
 * generic "You missed entering some required information".
 */
class ItemNonScalarReplyTest extends \PHPUnit\Framework\TestCase {

    private function item(array $row) {
        $item = (new ItemFactory(array()))->make($row);
        $this->assertNotFalse($item, 'fixture item must construct');
        return $item;
    }

    public static function scalarTypes() {
        return array(
            array('number'), array('text'), array('textarea'), array('letters'), array('email'),
            array('url'), array('color'), array('date'), array('datetime'), array('check'),
        );
    }

    /** @dataProvider scalarTypes */
    public function testArrayReplyIsRejectedNotFatal($type) {
        $item = $this->item(array('name' => 'q1', 'type' => $type));
        $item->validateInput(array('1'));
        $this->assertNotEmpty($item->error, "$type must set an error for an array reply");
    }

    public function testNumberKeepsItsOwnMessageForRequiredItem() {
        $item = $this->item(array('name' => 'age', 'type' => 'number'));
        $this->assertNull($item->validateInput(array('1')));
        $this->assertSame('This is not a valid number.', $item->error);
    }

    public function testScalarRepliesStillValidate() {
        $number = $this->item(array('name' => 'age', 'type' => 'number'));
        $number->validateInput('42');
        $this->assertEmpty($number->error);

        $email = $this->item(array('name' => 'em', 'type' => 'email'));
        $this->assertSame('a@example.org', $email->validateInput('a@example.org'));
        $this->assertEmpty($email->error);

        $text = $this->item(array('name' => 'q', 'type' => 'text'));
        $this->assertSame('hello', $text->validateInput('hello'));
        $this->assertEmpty($text->error);
    }
}
