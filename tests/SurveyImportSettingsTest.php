<?php

require_once __DIR__ . '/helpers/SpreadsheetFixture.php';

/**
 * A new survey takes its settings — its engine included — from the file it
 * is created from: a JSON file's "settings" or a spreadsheet's `settings`
 * sheet (the sheet the xlsx export writes). One allowlist for both,
 * SurveyStudy::importableSettings.
 */
class SurveyImportSettingsTest extends \PHPUnit\Framework\TestCase {

    use SpreadsheetFixture;

    private function read($path) {
        $reader = new SpreadsheetReader();
        $reader->readItemTableFile($path);
        return $reader;
    }

    public function testASettingsSheetIsRead() {
        $reader = $this->read($this->makeWorkbook(array(
            'survey' => array(array('type', 'name', 'label'), array('text', 'q1', 'Q1')),
            'choices' => array(array('list_name', 'name', 'label'), array('yn', '1', 'yes')),
            'settings' => array(array('item', 'value'), array('rendering_mode', 'v2'), array(), array('layout', 'solo')),
        )));
        $this->assertEmpty($reader->errors, implode(' | ', $reader->errors));
        $this->assertSame(array('rendering_mode' => 'v2', 'layout' => 'solo'), $reader->settings);
    }

    public function testASecondSheetNamedSettingsIsNotReadAsChoices() {
        // What the xlsx export writes for a survey without choices.
        $reader = $this->read($this->makeWorkbook(array(
            'survey' => array(array('type', 'name', 'label'), array('text', 'q1', 'Q1')),
            'settings' => array(array('item', 'value'), array('rendering_mode', 'v2')),
        )));
        $this->assertEmpty($reader->errors, implode(' | ', $reader->errors));
        $this->assertSame(array(), $reader->choices);
        $this->assertSame(array('rendering_mode' => 'v2'), $reader->settings);
    }

    public function testNoSettingsSheetNoSettings() {
        $reader = $this->read($this->makeWorkbook(array(
            'survey' => array(array('type', 'name', 'label'), array('text', 'q1', 'Q1')),
        )));
        $this->assertSame(array(), $reader->settings);
    }

    public function testOnlyAllowlistedValidSettingsAreImported() {
        $this->assertSame(array('rendering_mode' => 'v2'), SurveyStudy::importableSettings(array('rendering_mode' => 'v2')));
        $this->assertSame(array(), SurveyStudy::importableSettings(array('rendering_mode' => 'v3')), 'an unknown engine is dropped');
        $out = SurveyStudy::importableSettings(array('id' => 5, 'user_id' => 1, 'results_table' => 'x', 'name' => 'y', 'use_paging' => 1));
        $this->assertSame(array('use_paging' => 1), $out, 'identity and plumbing columns are never imported');
    }
}
