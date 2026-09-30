<?php

/**
 * form_v2 showif helper names are reserved item names.
 *
 * The Alpine component that drives v2 showifs (webroot/assets/form/js/showif/
 * alpine.js) holds one top-level field per item name *next to* its helper
 * methods, because a showif references item names bare. An item
 * called `answered` would therefore overwrite `answered()`; every showif that
 * calls the helper then throws, the directive's try/catch turns that into
 * `undefined`, and `undefined` means "keep the visibility the server rendered"
 * — so those showifs freeze instead of erroring visibly.
 *
 * The client refuses to write such a field, and validation refuses the name at
 * import so it can't be created in the first place. Item::RESERVED_NAMES is the
 * one list; SpreadsheetReader reads it too.
 */
class ShowifReservedNamesTest extends \PHPUnit\Framework\TestCase {

    private function collect(SurveyStudy $study, Item $item): bool {
        $m = new ReflectionMethod(SurveyStudy::class, 'collectItemValidation');
        $m->setAccessible(true);
        return $m->invoke($study, $item);
    }

    private function item(array $row): Item {
        $item = (new ItemFactory(array()))->make($row);
        $this->assertNotFalse($item, 'fixture item must construct');
        return $item;
    }

    public function testItemNamedAnsweredIsRejected() {
        $study = new SurveyStudy();
        $item = $this->item(array('name' => 'answered', 'type' => 'text'));

        $this->assertFalse($this->collect($study, $item), 'an item named `answered` must not validate');
        $this->assertCount(1, $study->errors);
        $this->assertStringContainsString("'answered'", $study->errors[0]);
        $this->assertStringContainsString('reserved', $study->errors[0]);
    }

    /**
     * @dataProvider reservedNames
     */
    public function testEveryReservedNameIsRejected(string $name) {
        $item = $this->item(array('name' => $name, 'type' => 'text'));
        $result = $item->validate();
        $this->assertNotEmpty(
            preg_grep('/reserved/', $result['val_errors']),
            "'$name' must be rejected as reserved"
        );
    }

    public static function reservedNames(): array {
        return array_map(function ($name) {
            return array($name);
        }, Item::RESERVED_NAMES);
    }

    /**
     * A v1 study may already have an item named like a helper (from before
     * the names were reserved). SurveyStudy::addItems marks such names, so
     * re-uploading the study keeps working; the results-table columns stay
     * forbidden regardless.
     */
    public function testAnExistingV1ItemMayKeepAHelperName() {
        $item = $this->item(array('name' => 'last', 'type' => 'text'));
        $item->allowLegacyHelperName();
        $this->assertSame(array(), $item->validate()['val_errors'], 'an existing `last` item must stay uploadable');

        $column = $this->item(array('name' => 'created', 'type' => 'text'));
        $column->allowLegacyHelperName();
        $this->assertNotEmpty(preg_grep('/reserved/', $column->validate()['val_errors']), '`created` is never an item name');
    }

    public function testOrdinaryNamesStillValidate() {
        // near-misses: differing case and a legitimate word that merely contains
        // a helper name must stay usable.
        foreach (array('answered_at', 'isna', 'Contains', 'last_seen', 'q1') as $name) {
            $item = $this->item(array('name' => $name, 'type' => 'text'));
            $result = $item->validate();
            $this->assertSame(array(), $result['val_errors'], "'$name' should be a legal item name");
        }
    }

    /**
     * The PHP list and the client's guard list must not drift apart: the server
     * rejects these names so the client never *has* to hit its guard, but the
     * guard is what protects studies imported before this rule existed.
     */
    public function testClientGuardListCoversEveryHelperName() {
        $js = file_get_contents(__DIR__ . '/../webroot/assets/form/js/showif/alpine.js');
        $this->assertNotFalse($js, 'alpine.js must be readable');
        $this->assertSame(1, preg_match(
            '/export const RESERVED_FIELD_NAMES = new Set\(\[(.*?)\]\)/s', $js, $m
        ), 'alpine.js must export RESERVED_FIELD_NAMES');
        preg_match_all("/'([^']+)'/", $m[1], $names);
        $clientNames = $names[1];

        // Every method the fmrForm component defines must be in the client set …
        $this->assertSame(1, preg_match(
            "/Alpine\.data\('fmrForm'.*?\n    \}\)\);/s", $js, $component
        ), 'the fmrForm component literal must be findable');
        preg_match_all('/^\s{8}(\w+)\(/m', $component[0], $methods);
        foreach ($methods[1] as $method) {
            $this->assertContains($method, $clientNames, "alpine.js method `$method` must be reserved client-side");
        }

        // … and every *helper* (not the DB columns) must also be reserved server-side.
        $helpers = array_values(array_filter($clientNames, function ($n) {
            return $n[0] !== '_' && $n !== 'init';
        }));
        $this->assertNotEmpty($helpers);
        foreach ($helpers as $helper) {
            $this->assertContains($helper, Item::RESERVED_NAMES, "`$helper` must be reserved in Item::RESERVED_NAMES");
        }
        // … and Item::SHOWIF_HELPERS (what the compat scanner treats as known
        // identifiers) is exactly that helper set.
        sort($helpers);
        $serverHelpers = Item::SHOWIF_HELPERS;
        sort($serverHelpers);
        $this->assertSame($helpers, $serverHelpers, 'Item::SHOWIF_HELPERS must list the fmrForm helpers, no more, no less');
    }
}
