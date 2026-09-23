<?php
use PHPUnit\Framework\TestCase;

/**
 * Rank_Item (form_v2 drag-and-drop rank order): option keywords, the
 * ", "-joined value, validation of a posted order, and the server markup the
 * bundle's items/rank.js relies on — one hidden `name[]` input per row (DOM
 * order = submitted order), empty until touched when `touch` is on.
 */
class RankItemTest extends TestCase
{
    private $choices = array('1' => 'Apple', '2' => 'Banana', '3' => 'Cherry', '4' => 'Date');

    private function make(array $options = array())
    {
        return new Rank_Item(array_merge(array(
            'type' => 'rank', 'name' => 'fruit', 'id' => 7, 'label_parsed' => 'Rank the fruit',
            'choice_list' => 'fruits', 'choices' => $this->choices,
        ), $options));
    }

    public function testOptionKeywordsParseCaseInsensitivelyAndIgnoreStrangers()
    {
        $plain = $this->make();
        $this->assertFalse($plain->startsRandom());
        $this->assertFalse($plain->requiresTouch());

        $both = $this->make(array('type_options' => ' Random  TOUCH other'));
        $this->assertTrue($both->startsRandom());
        $this->assertTrue($both->requiresTouch());

        $aliases = $this->make(array('type_options' => 'shuffle require_change'));
        $this->assertTrue($aliases->startsRandom());
        $this->assertTrue($aliases->requiresTouch());

        $this->assertTrue(Rank_Item::isOptionKeyword('Random'));
        $this->assertFalse(Rank_Item::isOptionKeyword('fruits'));
    }

    public function testValidOrderIsAcceptedAndStoredJoined()
    {
        $item = $this->make();
        $this->assertSame(array('3', '1', '4', '2'), $item->validateInput(array('3', '1', '4', '2')));
        $this->assertNull($item->error);
        $this->assertSame('3, 1, 4, 2', $item->getReply(array('3', '1', '4', '2')));
        // A stored string round-trips through validation too (form-sync replays).
        $this->assertSame(array('2', '1', '3', '4'), $item->validateInput('2, 1, 3, 4'));
    }

    public function testArrayReplyPassesTheSaveLoopShapeCheck()
    {
        // UnitSession::updateSurveyStudyRecord() runs rejectNonScalarReply()
        // before validateInput(); rank posts `name[]`, so it must opt in.
        $item = $this->make();
        $this->assertFalse($item->rejectNonScalarReply(array('3', '1', '4', '2')));
        $this->assertNull($item->error);
    }

    public function testUnknownIncompleteAndDuplicateOrdersAreRejected()
    {
        $item = $this->make();
        $item->validateInput(array('1', '2', '3', '9'));
        $this->assertStringContainsString("'9'", $item->error);

        $item = $this->make();
        $item->validateInput(array('1', '2'));
        $this->assertNotEmpty($item->error, 'a partial ranking must not validate');

        $item = $this->make();
        $item->validateInput(array('1', '1', '2', '3'));
        $this->assertNotEmpty($item->error, 'a duplicated key must not validate');
    }

    public function testEmptyAnswerBlocksRequiredButPassesOptional()
    {
        $required = $this->make();
        $required->validateInput(array('', '', '', ''));
        $this->assertNotEmpty($required->error);

        $optional = $this->make(array('optional' => 1));
        $this->assertNull($optional->validateInput(array('', '', '', '')));
        $this->assertNull($optional->error);
        $this->assertNull($optional->getReply(array()));
    }

    public function testResultFieldFitsEveryKeyJoined()
    {
        // "1, 2, 3, 4" is 10 characters.
        $this->assertStringEndsWith('VARCHAR (10) DEFAULT NULL', $this->make()->getResultField());
    }

    public function testRendersOneHiddenArrayInputPerRowInAuthoredOrder()
    {
        $html = $this->make()->render();
        // No `touch`: the inputs are filled from the start (the box is "touched").
        $this->assertStringContainsString('class="fmr-rank fmr-rank-touched" data-fmr-rank>', $html);
        $this->assertStringNotContainsString('data-rank-touch', $html);
        $this->assertSame(array('1', '2', '3', '4'), $this->rowOrder($html));
        $this->assertSame(array('1', '2', '3', '4'), $this->hiddenValues($html));
        $this->assertStringContainsString('<label class="control-label" id="item7-label">', $html);
        $this->assertStringContainsString('aria-labelledby="item7-label"', $html);
        $this->assertStringContainsString('class="fmr-rank-label">Cherry<', $html);
    }

    public function testTouchModeLeavesInputsEmptyUntilInteraction()
    {
        $html = $this->make(array('type_options' => 'touch'))->render();
        $this->assertStringContainsString('data-rank-touch="1"', $html);
        $this->assertStringNotContainsString('fmr-rank-touched', $html);
        $this->assertSame(array('1', '2', '3', '4'), $this->rowOrder($html));
        $this->assertSame(array('', '', '', ''), $this->hiddenValues($html));
    }

    public function testPresetAnswerWinsOverRandomAndCountsAsTouched()
    {
        $item = $this->make(array('type_options' => 'random touch'));
        $item->value_validated = '4, 2';   // partial previous answer: the rest follow in authored order
        $html = $item->render();
        $this->assertSame(array('4', '2', '1', '3'), $this->rowOrder($html));
        $this->assertSame(array('4', '2', '1', '3'), $this->hiddenValues($html));
        $this->assertStringContainsString('fmr-rank-touched', $html);
    }

    public function testRandomShufflesTheStartOrderAcrossRenders()
    {
        $choices = array();
        for ($i = 1; $i <= 8; $i++) $choices[(string) $i] = "Option $i";
        $seen = array();
        for ($n = 0; $n < 12; $n++) {
            $html = $this->make(array('type_options' => 'random', 'choices' => $choices))->render();
            $order = $this->rowOrder($html);
            $this->assertEqualsCanonicalizing(array_keys($choices), $order, 'every key exactly once');
            $this->assertSame($order, $this->hiddenValues($html), 'inputs follow the displayed order');
            $seen[implode(',', $order)] = true;
        }
        $this->assertGreaterThan(1, count($seen), 'twelve random renders of eight options never differed');
    }

    private function rowOrder($html)
    {
        preg_match_all('/<li class="fmr-rank-item" data-value="([^"]*)"/', $html, $m);
        return $m[1];
    }

    private function hiddenValues($html)
    {
        preg_match_all('/<input type="hidden" name="fruit\[\]" value="([^"]*)"/', $html, $m);
        return $m[1];
    }
}
