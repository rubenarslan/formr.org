<?php

/**
 * The form_v2 R allowlist revokes a recorded call the moment nothing vouches
 * for it any more (RCallService::callRowIsLive): a row with no item anchor, a
 * row whose item row is gone, a soft-deleted item, and an item whose current
 * value or label no longer yields the recorded expression. The joined path
 * (callsForPage against MariaDB) is covered by
 * bin/test_form_v2_rcall_smoke.php; choice labels by
 * FormV2UnifiedResolutionTest.
 */
class RCallServiceAllowlistTest extends \PHPUnit\Framework\TestCase {

    private function row(array $over = array()) {
        return $over + array(
            'slot' => 'value', 'expr' => '1 + 1', 'item_id' => 7,
            'item_row_id' => 7, 'item_deleted' => null,
            'item_value' => '1 + 1', 'item_label' => 'Label',
        );
    }

    public function testALiveValueCallIsAllowed() {
        $this->assertTrue(RCallService::callRowIsLive($this->row()));
        $this->assertTrue(RCallService::callRowIsLive($this->row(array('item_value' => " 1 + 1\n"))), 'surrounding whitespace is not an edit');
    }

    public function testARowWithoutAnItemAnchorIsRefused() {
        $this->assertFalse(RCallService::callRowIsLive($this->row(array('item_id' => null))));
        $this->assertFalse(RCallService::callRowIsLive($this->row(array('item_id' => 0))));
    }

    public function testARowWhoseItemRowIsGoneIsRefused() {
        // the LEFT JOIN on survey_items missed: every item column is NULL
        $this->assertFalse(RCallService::callRowIsLive($this->row(array(
            'item_row_id' => null, 'item_value' => null, 'item_label' => null,
        ))));
    }

    public function testASoftDeletedItemIsRefused() {
        $this->assertFalse(RCallService::callRowIsLive($this->row(array('item_deleted' => '2026-09-01 10:00:00'))));
    }

    public function testAnEditedOrBlankedValueRevokesTheCall() {
        $this->assertFalse(RCallService::callRowIsLive($this->row(array('item_value' => '2 + 2'))));
        $this->assertFalse(RCallService::callRowIsLive($this->row(array('item_value' => ''))));
        $this->assertFalse(RCallService::callRowIsLive($this->row(array('item_value' => null))));
        $this->assertFalse(RCallService::callRowIsLive($this->row(array('expr' => '', 'item_value' => ''))), 'an empty expression is never live');
    }

    public function testALabelCallFollowsTheCurrentLabel() {
        $label = $this->row(array('slot' => 'label', 'expr' => 'Hi `r name`', 'item_label' => 'Hi `r name`', 'item_value' => ''));
        $this->assertTrue(RCallService::callRowIsLive($label));
        $this->assertFalse(RCallService::callRowIsLive(array('item_label' => 'Hi `r other`') + $label));
        // a label call is not vouched for by a matching value, nor vice versa
        $this->assertFalse(RCallService::callRowIsLive(array('item_label' => 'x', 'item_value' => 'Hi `r name`') + $label));
    }

    public function testSlotsTheEndpointNeverResolvesAreRefused() {
        $this->assertFalse(RCallService::callRowIsLive($this->row(array('slot' => 'showif'))));
        $this->assertFalse(RCallService::callRowIsLive($this->row(array('slot' => 'page_body'))));
    }
}
