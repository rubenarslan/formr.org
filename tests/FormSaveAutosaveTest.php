<?php

use PHPUnit\Framework\TestCase;

/**
 * /form-save (form_v2 autosave) must never persist what a submit would reject.
 *
 * UnitSession::updateSurveyStudyRecord stamps `survey_items_display.saved` for
 * every item it writes, and FormRenderer only re-renders `saved IS NULL` — so
 * anything autosave lets through is final from the participant's point of view.
 * RunController::formSaveAction used to call the save with validate=false, which
 * persisted unverified bot_check tokens, out-of-range numbers, unknown choices
 * and arbitrary strings under file items. It now filters the posted answers
 * through RunController::filterAutosaveAnswers() (validate on a clone, drop
 * failures, skip ineligible types) and saves with validate=true.
 */
class FormSaveAutosaveTest extends TestCase
{
    private function item($class, $name, array $extra = array())
    {
        return new $class(array_merge(array('name' => $name), $extra));
    }

    private function fixtureItems()
    {
        return array(
            'human' => $this->item('BotCheck_Item', 'human', array('type' => 'bot_check')),
            'photo' => $this->item('File_Item', 'photo', array('type' => 'file')),
            'age' => $this->item('Number_Item', 'age', array('type' => 'number', 'type_options' => '1,5')),
            'mood' => $this->item('Mc_Item', 'mood', array('type' => 'mc', 'choices' => array(1 => 'good', 2 => 'bad'))),
            'note' => $this->item('Text_Item', 'note', array('type' => 'text')),
        );
    }

    public function testBotCheckAndUploadsAreNeverAutosaved()
    {
        $this->assertFalse(RunController::autosaveEligible($this->item('BotCheck_Item', 'h', array('type' => 'bot_check'))));
        $this->assertFalse(RunController::autosaveEligible($this->item('File_Item', 'f', array('type' => 'file'))));
        $this->assertFalse(RunController::autosaveEligible($this->item('Image_Item', 'i', array('type' => 'image'))));
        $this->assertFalse(RunController::autosaveEligible($this->item('Audio_Item', 'a', array('type' => 'audio'))));
        $this->assertFalse(RunController::autosaveEligible($this->item('Video_Item', 'v', array('type' => 'video'))));

        $this->assertTrue(RunController::autosaveEligible($this->item('Text_Item', 't', array('type' => 'text'))));
        $this->assertTrue(RunController::autosaveEligible($this->item('Number_Item', 'n', array('type' => 'number'))));
        $this->assertTrue(RunController::autosaveEligible($this->item('Mc_Item', 'm', array('type' => 'mc', 'choices' => array(1 => 'a')))));
    }

    public function testInvalidAndIneligibleAnswersAreDropped()
    {
        $posted = array(
            'human' => base64_encode('{"challenge":"forged"}'), // never verified → must not be stored
            'photo' => '../../etc/passwd',                    // a string under a file item
            'age' => 99,                                       // `number 1,5`
            'mood' => 7,                                       // not a choice — as a JSON int, which
                                                               // Mc_Item's is_string() check alone would miss
            'mood2' => '7',                                    // (see fixture: same item, string form)
            'ghost' => 'x',                                    // no such item
            'note' => 'fine',
        );
        $items = $this->fixtureItems();
        $items['mood2'] = $items['mood'];
        $accepted = RunController::filterAutosaveAnswers($posted, $items);
        $this->assertSame(array('note' => 'fine'), $accepted);
    }

    public function testValidAnswersPassThroughWithPostSemantics()
    {
        // JSON scalars are coerced to the strings $_POST would have carried,
        // so the autosave can never reach a laxer validator branch than submit.
        $posted = array('age' => 3, 'mood' => '2', 'note' => 'hello', 'flag' => true);
        $items = $this->fixtureItems();
        $items['flag'] = $this->item('Check_Item', 'flag', array('type' => 'check'));
        $accepted = RunController::filterAutosaveAnswers($posted, $items);
        $this->assertSame(array('age' => '3', 'mood' => '2', 'note' => 'hello', 'flag' => '1'), $accepted);
    }

    public function testNestedStructuresAreRefused()
    {
        $items = $this->fixtureItems();
        $this->assertSame(array(), RunController::filterAutosaveAnswers(array('note' => array('a' => array('b'))), $items));
        $this->assertSame(array(), RunController::filterAutosaveAnswers(array('age' => array(1, 2)), $items));
    }

    public function testValueShapeMustMatchTheItemsInput()
    {
        $items = $this->fixtureItems();
        $items['tags'] = $this->item('McMultiple_Item', 'tags', array(
            'type' => 'mc_multiple', 'choices' => array(1 => 'a', 2 => 'b', 3 => 'c'),
        ));
        // scalar under an array item, array under a scalar item: both dropped
        $this->assertSame(array(), RunController::filterAutosaveAnswers(array('tags' => '1', 'note' => array('x')), $items));
        // the right shape, with JSON ints coerced to $_POST strings
        $this->assertSame(array('tags' => array('1', '3')), RunController::filterAutosaveAnswers(array('tags' => array(1, 3)), $items));
        // an unknown choice inside the array still fails validation
        $this->assertSame(array(), RunController::filterAutosaveAnswers(array('tags' => array(1, 9)), $items));
    }

    public function testEmptyValuesAreNotAutosaved()
    {
        // A cleared field has nothing to persist; writing it would stamp
        // `saved` and drop the item from the next render.
        $items = $this->fixtureItems();
        $items['note']->optional = true;
        $posted = array('note' => '', 'age' => null, 'mood' => array());
        $this->assertSame(array(), RunController::filterAutosaveAnswers($posted, $items));
    }

    public function testEmptyAndUnknownPayloadsYieldNothing()
    {
        $items = $this->fixtureItems();
        $this->assertSame(array(), RunController::filterAutosaveAnswers(array(), $items));
        $this->assertSame(array(), RunController::filterAutosaveAnswers(array('ghost' => 1), $items));
        $this->assertSame(array(), RunController::filterAutosaveAnswers(array('note' => 'x'), array()));
        $this->assertSame(array(), RunController::filterAutosaveAnswers(array('note' => 'x'), array('note' => 'not an item')));
    }

    public function testFilteringDoesNotTouchTheStudysItemObjects()
    {
        // The real save (and the later submit) reuse these objects; the probe
        // must be a clone so a rejected autosave attempt leaves no error/reply.
        $items = $this->fixtureItems();
        RunController::filterAutosaveAnswers(array('age' => 99, 'mood' => 7, 'note' => 'ok'), $items);
        foreach ($items as $name => $item) {
            $this->assertEmpty($item->error, "$name->error was set by the probe");
            $this->assertNull($item->reply, "$name->reply was set by the probe");
        }
    }
}
