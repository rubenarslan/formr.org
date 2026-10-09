<?php

require_once __DIR__ . '/helpers/SpreadsheetFixture.php';

/**
 * Pieces of form_v2's single resolution path for dynamic content:
 * - `sticky` is recorded as written (the allowlist's revocation check compares
 *   the item's current value with the recorded expression) and expanded only
 *   at evaluation, on every page — later pages used to evaluate the bare word;
 * - R in a choice label is a 'choice_label' call, live only while the item's
 *   choice list still carries that label;
 * - a study's own Next/Submit wording overrides the built-in strings (#322);
 * - a pattern item's first choice is its mismatch message (#269), and email
 *   inputs require a dot in the domain like the server does (#327).
 */
class FormV2UnifiedResolutionTest extends \PHPUnit\Framework\TestCase {

    use SpreadsheetFixture;

    private function service($studyName = 'mystudy') {
        $study = (new ReflectionClass(SurveyStudy::class))->newInstanceWithoutConstructor();
        $study->id = 1;
        $study->name = $studyName;
        $unitSession = (new ReflectionClass(UnitSession::class))->newInstanceWithoutConstructor();
        return new RCallService($unitSession, $study);
    }

    public function testStickyExpandsToTheLastNonMissingAnswer() {
        $this->assertSame('tail(na.omit(mystudy$mood), 1)', $this->service()->expandValueExpr('sticky', 'mood'));
        $this->assertSame('tail(na.omit(mystudy$mood), 1)', $this->service()->expandValueExpr(" sticky\n", 'mood'));
    }

    public function testOtherValuesAndOddNamesAreLeftAlone() {
        $this->assertSame('paste("a", "b")', $this->service()->expandValueExpr('paste("a", "b")', 'mood'));
        $this->assertSame('sticky', $this->service()->expandValueExpr('sticky', 'x; system("id")'));
    }

    private function choiceRow($expr, array $labels) {
        return array(
            'slot' => 'choice_label', 'expr' => $expr, 'item_id' => 7,
            'item_row_id' => 7, 'item_deleted' => null, 'item_value' => null, 'item_label' => 'q',
            'item_choice_labels' => $labels,
        );
    }

    public function testChoiceLabelCallIsLiveOnlyWhileTheListCarriesIt() {
        $this->assertTrue(RCallService::callRowIsLive($this->choiceRow('`r 1 + 1`', array('1' => '`r 1 + 1`', '2' => 'two'))));
        $this->assertFalse(RCallService::callRowIsLive($this->choiceRow('`r 1 + 1`', array('1' => '`r 2 + 2`'))));
        $this->assertFalse(RCallService::callRowIsLive($this->choiceRow('`r 1 + 1`', array())));
    }

    public function testStudyWordingOverridesTheBuiltInNavigation() {
        $study = (new ReflectionClass(SurveyStudy::class))->newInstanceWithoutConstructor();
        $study->next_button_label = 'Onward';
        $study->submit_button_label = null;
        $table = FormStrings::table('de', FormStrings::studyOverrides($study));
        $this->assertSame('Onward', $table['nav.next']);
        $this->assertSame('Onward', $table['nav.continue']);
        $this->assertSame(FormStrings::get('de', 'nav.submit'), $table['nav.submit'], 'no Submit override: built-in German');
        $this->assertSame(FormStrings::table('en'), FormStrings::table('en', array('nav.bogus' => 'x', 'nav.next' => '')));
    }

    public function testButtonLabelsAreOneLineOfPlainText() {
        $this->assertNull(SurveyStudy::normalizeButtonLabel('   '));
        $this->assertNull(SurveyStudy::normalizeButtonLabel(array('x')));
        $this->assertSame('Go on', SurveyStudy::normalizeButtonLabel("  <b>Go</b>\n on "));
        $this->assertSame(100, mb_strlen(SurveyStudy::normalizeButtonLabel(str_repeat('ä', 150))));
    }

    public function testPatternItemTakesItsMessageFromItsFirstChoice() {
        $factory = new ItemFactory(array('zipmsg' => array('1' => 'Five <em>digits</em>, please')));
        $item = $factory->make(array('name' => 'zip', 'type' => 'text', 'type_options' => '[0-9]{5}', 'choice_list' => 'zipmsg', 'optional' => 1));
        $this->assertSame(array(), $item->validate()['val_errors'], 'a pattern item may carry its message as a choice');
        $this->assertStringContainsString('title="Five digits, please"', $item->render());

        $plain = $factory->make(array('name' => 'plain', 'type' => 'text', 'choice_list' => 'zipmsg', 'optional' => 1));
        $this->assertNotEmpty($plain->validate()['val_errors'], 'without a pattern, choices on a text item are still an error');
    }

    public function testAPatternItemKeepsATextColumnDespiteItsMessageChoice() {
        $factory = new ItemFactory(array('msg' => array('1' => 'Five digits, please')));
        $item = $factory->make(array('name' => 'zip', 'type' => 'text', 'type_options' => '[0-9]{5}', 'choice_list' => 'msg', 'optional' => 1));
        $this->assertSame('`zip` TEXT DEFAULT NULL', $item->getResultField(), 'the message is not an answer option; the answer is text');
        $mc = $factory->make(array('name' => 'm', 'type' => 'mc', 'choice_list' => 'msg', 'optional' => 1));
        $this->assertStringContainsString('TINYINT', $mc->getResultField(), 'real options still type the column');
    }

    public function testSheetAuthorsWriteThePatternInTypeAndTheMessageInChoice1() {
        $reader = new SpreadsheetReader();
        $reader->readItemTableFile($this->makeWorkbook(array('survey' => array(
            array('name', 'type', 'label', 'choice1'),
            array('zip', 'text [0-9]{5}', 'Postcode', 'Please enter five digits.'),
        ))));
        $this->assertEmpty($reader->errors, implode(' | ', $reader->errors));
        $row = reset($reader->survey);
        $this->assertSame('[0-9]{5}', (string) $row['type_options']);
        $lists = array();
        foreach ($reader->choices as $c) {
            $lists[$c['list_name']][$c['name']] = $c['label'];
        }
        $item = (new ItemFactory($lists))->make($row);
        $this->assertSame(array(), $item->validate()['val_errors']);
        $this->assertStringContainsString('title="Please enter five digits."', $item->render());
    }

    public function testEmailRequiresADotInTheDomainInTheBrowserToo() {
        $email = (new ItemFactory(array()))->make(array('name' => 'em', 'type' => 'email', 'optional' => 1));
        $html = $email->render();
        $this->assertStringContainsString('pattern="[^@\s]+@[^@\s]+\.[^@\s]+"', $html);
        $this->assertStringContainsString('title="', $html);
        $pattern = '/^(?:' . Email_Item::BROWSER_PATTERN . ')$/';
        $this->assertSame(1, preg_match($pattern, 'first.last+tag@sub.example.org'));
        $this->assertSame(0, preg_match($pattern, 'bla@bla'), 'the address PHP rejects (#327)');
        $this->assertFalse(filter_var('bla@bla', FILTER_VALIDATE_EMAIL));
    }
}
