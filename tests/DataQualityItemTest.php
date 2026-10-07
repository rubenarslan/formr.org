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

    /**
     * The classic engine's "Items Per Page" cuts a long page into screens.
     * The two unseen items take none of its places and are not what a page
     * is cut at: they stay with the last questions shown. Counted, they were
     * cut off, left alone with the submit button and skipped as an empty
     * page, and the page's record was never taken.
     */
    public function testItemsPerPageKeepsTheUnseenItemsWithTheLastQuestionsShown()
    {
        $id = 0;
        $make = function ($type, $name) use (&$id) {
            return (new ItemFactory(array()))->make(array('type' => $type, 'name' => $name, 'id' => ++$id));
        };
        $names = function (array $items) {
            return array_map(function ($item) { return $item->name; }, $items);
        };
        $page = array();
        foreach (array('q1', 'q2', 'q3', 'q4', 'q5') as $name) {
            $page[$name] = $make('text', $name);
        }
        $page['dq_agent_p1'] = $make('agent_probe', 'dq_agent_p1');
        $page['dq_p1'] = $make('data_quality', 'dq_p1');
        $page['next'] = $make('submit', 'next');

        // Five questions fill the page: the two rows ride along, the submit
        // button is cut off (the renderer adds its default one).
        $this->assertSame(array('q1', 'q2', 'q3', 'q4', 'q5', 'dq_agent_p1', 'dq_p1'),
            $names(SpreadsheetRenderer::withinItemsPerPage($page, 5)));
        // A page of three screens' worth: the rows go with its last screen.
        $this->assertSame(array('q1', 'q2'), $names(SpreadsheetRenderer::withinItemsPerPage($page, 2)));
        $rest = array_slice($page, 4, null, true);
        $this->assertSame(array('q5', 'dq_agent_p1', 'dq_p1', 'next'), $names(SpreadsheetRenderer::withinItemsPerPage($rest, 2)));
        // No limit, and a limit the page stays under: everything.
        $this->assertSame(array_keys($page), $names(SpreadsheetRenderer::withinItemsPerPage($page, 0)));
        $this->assertSame(array_keys($page), $names(SpreadsheetRenderer::withinItemsPerPage($page, 6)));
        // The rows first: they do not use up two of the page's places.
        $first = array('dq_agent_p1' => $page['dq_agent_p1'], 'dq_p1' => $page['dq_p1']) + $page;
        $this->assertSame(array('dq_agent_p1', 'dq_p1', 'q1', 'q2'), $names(SpreadsheetRenderer::withinItemsPerPage($first, 2)));
    }

    /**
     * A page whose questions a showif hid is skipped, and what it was left
     * with (its submit button, the items nobody sees) is hidden with it for
     * good. When the showif of one of its questions is changed by an upload,
     * the question's verdict is forgotten, and these have to come back with
     * it: the page would otherwise return without its record.
     */
    public function testAChangedShowifBringsBackWhatItsPageWasLeftWith()
    {
        $rows = array();
        foreach (array(
            array('note', 'intro'), array('submit', 'next1'),
            array('text', 'q1'), array('agent_probe', 'dq_agent_p1'), array('data_quality', 'dq_p1'), array('submit', 'next2'),
            array('text', 'q2'), array('data_quality', 'dq_p2'), array('submit', 'next3'),
            array('text', 'q3'), array('data_quality', 'dq_p3'),
        ) as $i => $row) {
            $rows[] = array('id' => $i + 1, 'type' => $row[0], 'name' => $row[1]);
        }
        // q1's showif changed: its page's probe, record and submit button.
        $this->assertSame(array(4, 5, 6), SurveyStudy::pageLeftovers($rows, array(3)));
        // Nothing of the pages whose questions did not change.
        $this->assertSame(array(), SurveyStudy::pageLeftovers($rows, array()));
        $this->assertSame(array(2), SurveyStudy::pageLeftovers($rows, array(1)));
        // A last page without a submit button of its own.
        $this->assertSame(array(11), SurveyStudy::pageLeftovers($rows, array(10)));
        $this->assertSame(array(4, 5, 6, 8, 9), SurveyStudy::pageLeftovers($rows, array(3, 7)));
    }

    /**
     * A page whose OpenCPU batch failed makes its items invalid, so that it
     * cannot be sent with a broken calculation. Not the items nobody sees:
     * the error belongs on something a participant can read.
     */
    public function testAFailedPageDoesNotPutItsErrorOnAnUnseenItem()
    {
        $factory = new ItemFactory(array());
        foreach (array('data_quality', 'agent_probe') as $type) {
            $item = $factory->make(array('type' => $type, 'name' => 'x_' . $type, 'id' => 3));
            $item->alwaysInvalid();
            $this->assertEmpty($item->error, $type);
            $this->assertStringNotContainsString('always_invalid', $item->render(), $type);
        }
        $text = $factory->make(array('type' => 'text', 'name' => 'q1', 'id' => 4));
        $text->alwaysInvalid();
        $this->assertNotEmpty($text->error);
    }

    /**
     * The page's record comes with every autosave and is always accepted.
     * It is stored with the participant's answers, not instead of them: when
     * it is all that was accepted, nothing was saved.
     */
    public function testARecordAloneIsNothingToAutosave()
    {
        $factory = new ItemFactory(array());
        $items = array(
            'dq_p1' => $this->item(),
            'email' => $factory->make(array('type' => 'email', 'name' => 'email', 'id' => 8)),
            'dq_agent_p1' => $factory->make(array('type' => 'agent_probe', 'name' => 'dq_agent_p1', 'id' => 9)),
        );
        $record = '{"v":2,"keys":3}';
        // The participant's value was refused (not an address): the record is all that is left.
        $accepted = FormSubmissionService::filterAutosaveAnswers(array('dq_p1' => $record, 'email' => 'abc'), $items);
        $this->assertSame(array('dq_p1' => $record), $accepted);
        $this->assertFalse(FormSubmissionService::holdsAnAnswer($accepted, $items));
        $this->assertFalse(FormSubmissionService::holdsAnAnswer(array(), $items));

        $accepted = FormSubmissionService::filterAutosaveAnswers(array('dq_p1' => $record, 'email' => 'a@example.org'), $items);
        $this->assertTrue(FormSubmissionService::holdsAnAnswer($accepted, $items));
        // What an agent wrote into the field nobody sees is an answer to store.
        $this->assertTrue(FormSubmissionService::holdsAnAnswer(array('dq_p1' => $record, 'dq_agent_p1' => 'yes, a model'), $items));
    }
}
