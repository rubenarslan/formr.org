<?php
use PHPUnit\Framework\TestCase;

/**
 * `data_quality`: a hidden input the participant's browser fills with the
 * page's record (webroot/assets/common/js/data-quality.js). It switches the
 * recording on by being on the page, and gates nothing: always optional,
 * never an error, and what is not a record is not stored.
 */
class DataQualityItemTest extends TestCase
{
    private function item(array $options = array())
    {
        return new DataQuality_Item($options + array('type' => 'data_quality', 'name' => 'dq_p1', 'id' => 7));
    }

    public function testTheFactoryKnowsTheType()
    {
        $factory = new ItemFactory(array());
        $this->assertInstanceOf(DataQuality_Item::class, $factory->make(array('type' => 'data_quality', 'name' => 'dq_p1')));
    }

    public function testItRendersAHiddenInputTheRecorderFinds()
    {
        $html = $this->item()->render();
        $this->assertMatchesRegularExpression('/<input[^>]*type="hidden"[^>]*name="dq_p1"|<input[^>]*name="dq_p1"[^>]*type="hidden"/', $html);
        $this->assertStringContainsString('data-fmr-dq="1"', $html);
        // On the page, never seen: display:none by its type's own rule, and
        // marked so the form does not take it for something to show.
        $this->assertMatchesRegularExpression('/class="[^"]*\bitem-data_quality\b/', $html);
        $this->assertMatchesRegularExpression('/class="[^"]*\bhidden\b/', $html);
        $this->assertStringContainsString('data-fmr-unseen="1"', $html);
        $this->assertTrue($this->item()->isUnseen());
        $this->assertStringNotContainsString('<label', $html);
    }

    public function testItIsOptionalWhateverTheSheetSays()
    {
        foreach (array(0, '0', false, null, 1) as $optional) {
            $item = $this->item(array('optional' => $optional));
            $this->assertSame(1, $item->optional);
            $html = $item->render();
            $this->assertStringNotContainsString('required', $html);
            $this->assertFalse(FormSubmissionService::isRequired($item));
        }
    }

    public function testAStoredRecordGoesBackIntoTheInputButNotIntoTheStateBlock()
    {
        $item = $this->item();
        $this->assertTrue($item->rendersStoredAnswer());
        $item->value_validated = '{"v":2,"keys":3}';
        $this->assertStringContainsString('value="{&quot;v&quot;:2,&quot;keys&quot;:3}"', $item->render());
        $this->assertNull($item->storedValueForClient('{"v":2,"keys":3}'));
    }

    public function testARecordIsStoredAsItIs()
    {
        $record = '{"v":2,"ld":1,"wd":0,"glob":"","fam":"chrome","kcv":0.31,"t1":-1,"drm":"wv"}';
        $item = $this->item();
        $this->assertSame($record, $item->validateInput($record));
        $this->assertNull($item->error);
        $this->assertSame($record, $item->getReply($record));
        $this->assertSame('MEDIUMTEXT DEFAULT NULL', $item->mysql_field);
        $this->assertSame('`dq_p1` MEDIUMTEXT DEFAULT NULL', $item->getResultField());
    }

    public function testWhatIsNotARecordIsStoredAsNothingAndNeverBlocksThePage()
    {
        $notRecords = array(
            'empty' => '',
            'null' => null,
            'not json' => 'hello',
            'a list' => '[1,2,3]',
            'no version' => '{"keys":3}',
            'nested' => '{"v":2,"keys":{"a":1}}',
            'too large' => '{"v":2,"glob":"' . str_repeat('x', DataQuality_Item::MAX_BYTES) . '"}',
            'an array reply' => array('{"v":2}'),
        );
        foreach ($notRecords as $label => $reply) {
            $item = $this->item();
            $this->assertFalse($item->rejectNonScalarReply($reply), $label);
            $this->assertNull($item->validateInput($reply), $label);
            $this->assertNull($item->getReply($reply), $label);
            $this->assertNull($item->error, $label);
        }
    }

    public function testOnlyFlatScalarFieldsWithPlainNamesAreKept()
    {
        $posted = json_encode(array(
            'v' => 2,
            'keys' => 12,
            'ok' => true,
            'Bad Key' => 1,
            '<script>' => 'x',
            'list' => array(1, 2),
            'long' => str_repeat('a', 500),
        ));
        // `list` nests, so the whole reply is no record.
        $this->assertNull(DataQuality_Item::cleanRecord($posted));

        $posted = json_encode(array('v' => 2, 'keys' => 12, 'ok' => true, 'Bad Key' => 1, '<script>' => 'x', 'long' => str_repeat('a', 500)));
        $stored = json_decode(DataQuality_Item::cleanRecord($posted), true);
        $this->assertSame(array('v', 'keys', 'ok', 'long'), array_keys($stored));
        $this->assertSame(DataQuality_Item::MAX_TEXT, strlen($stored['long']));
    }

    public function testItTakesNoValue()
    {
        $result = $this->item(array('value' => 'sample(1:2, 1)'))->validate();
        $this->assertNotEmpty($result['val_errors']);
        $this->assertStringContainsString('cannot have a value', $result['val_errors'][0]);
        $this->assertSame(array(), $this->item()->validate()['val_errors']);
    }

    public function testBothEnginesRunIt()
    {
        $this->assertNull($this->item()->formV2Unsupported());
        $this->assertNull($this->item()->v1Unsupported());
        // It is rendered (the browser fills it) and stored with its page.
        $this->assertTrue($this->item()->requiresUserInput());
        $this->assertTrue($this->item()->isStoredInResultsTable());
        $this->assertTrue(FormSubmissionService::autosaveEligible($this->item()));
    }

    public function testAnUploadWarnsAboutASecondOneOnAPage()
    {
        $make = function ($type, $name) {
            return (new ItemFactory(array()))->make(array('type' => $type, 'name' => $name, 'id' => 1));
        };
        $items = array(
            $make('note', 'consent'), $make('submit', 'next1'),
            $make('text', 'q1'), $make('data_quality', 'dq_p1'), $make('agent_probe', 'dq_agent_p1'), $make('submit', 'next2'),
            $make('text', 'q2'), $make('data_quality', 'dq_p2'), $make('data_quality', 'dq_p2b'), $make('submit', 'next3'),
        );
        $warnings = SurveyStudy::repeatedDataQualityItems($items);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("'dq_p2b'", $warnings[0]);
        $this->assertStringContainsString("'dq_p2'", $warnings[0]);
        $this->assertSame(array(), SurveyStudy::repeatedDataQualityItems(array_slice($items, 0, 8)));
    }

    /**
     * The classic engine's paged renderer skips a page with nothing to
     * display. The two unseen items are rendered, but they are nothing a
     * participant could see: alone with the submit button they do not keep
     * the page.
     */
    public function testAPageOfUnseenItemsHasNothingToDisplay()
    {
        $make = function ($type, $name) {
            return (new ItemFactory(array()))->make(array('type' => $type, 'name' => $name, 'id' => 1));
        };
        $renderer = (new ReflectionClass(PagedSpreadsheetRenderer::class))->newInstanceWithoutConstructor();
        $displayed = new ReflectionMethod(PagedSpreadsheetRenderer::class, 'displayedItemExists');
        $displayed->setAccessible(true);

        $items = array($make('agent_probe', 'dq_agent_p1'), $make('data_quality', 'dq_p1'), $make('submit', 'next'));
        $this->assertTrue($items[0]->isRendered() && $items[1]->isRendered(), 'they are on the page');
        $this->assertFalse($displayed->invokeArgs($renderer, array(&$items)));

        $items[] = $make('note', 'intro');
        $this->assertTrue($displayed->invokeArgs($renderer, array(&$items)));
        // A `hidden` item keeps its page, as it always has.
        $hiddenOnly = array($make('hidden', 'h'), $make('submit', 'next'));
        $this->assertTrue($displayed->invokeArgs($renderer, array(&$hiddenOnly)));
    }

    public function testAStoredResultIsEscapedInTheAdminTables()
    {
        $this->assertSame('{&quot;v&quot;:2,&quot;glob&quot;:&quot;&lt;b&gt;&quot;}', $this->item()->getEscapedResult('{"v":2,"glob":"<b>"}'));
    }
}
