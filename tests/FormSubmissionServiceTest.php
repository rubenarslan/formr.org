<?php

/**
 * FormSubmissionService's pure parts: reading a v2 write request into one
 * shape whatever the transport, and shaping one item's stored state — the
 * shape shared by the page's state block and every submit reply.
 */
class FormSubmissionServiceTest extends \PHPUnit\Framework\TestCase {

    public function testJsonPayloadIsNormalisedToWhatPostWouldCarry() {
        $body = json_encode(array(
            'page' => 2, 'unit_session_id' => 17, 'final' => true,
            'data' => array('age' => 31, 'agree' => true, 'nope' => false, 'tags' => array('a', 2), 'deep' => array(array('x')), 'name' => 'Ann'),
            'item_views' => array('shown' => array('5' => '2026-09-24 10:00:00')),
            'hidden' => array('7', 7, 'x', 0, 9),
            'uuid' => 'abc', 'client_ts' => '',
        ));
        $p = FormSubmissionService::readPayload('application/json', $body, array(), array());
        $this->assertSame(2, $p['page']);
        $this->assertSame(17, $p['unit_session_id']);
        $this->assertTrue($p['final']);
        $this->assertSame(array('age' => '31', 'agree' => '1', 'nope' => '', 'tags' => array('a', '2'), 'name' => 'Ann'), $p['data'], 'nested structures are dropped');
        $this->assertSame(array('5' => '2026-09-24 10:00:00'), $p['item_views']['shown']);
        $this->assertSame(array(7, 9), $p['hidden']);
        $this->assertSame('abc', $p['uuid']);
        $this->assertNull($p['client_ts'], 'an empty client_ts is none');
        $this->assertSame(2, $p['raw_page']);
    }

    public function testAnEmptyMultipleChoiceIsOneShapeOnBothTransports() {
        $p = FormSubmissionService::readPayload('application/json', json_encode(array('data' => array('m' => array()))), array(), array());
        $this->assertSame(array('m' => ''), $p['data'], 'JSON [] becomes the empty placeholder multipart posts');
    }

    public function testDefaultsAndBadJson() {
        $this->assertNull(FormSubmissionService::readPayload('application/json', 'not json', array(), array()));
        $this->assertNull(FormSubmissionService::readPayload('', '"a string"', array(), array()));
        $p = FormSubmissionService::readPayload('application/json', '{}', array(), array());
        $this->assertSame(1, $p['page']);
        $this->assertSame(0, $p['unit_session_id']);
        $this->assertFalse($p['final']);
        $this->assertSame(array(), $p['data']);
        $this->assertSame(array(), $p['hidden']);
        $this->assertNull($p['raw_page']);
        $p = FormSubmissionService::readPayload('application/json', '{"final":"false"}', array(), array());
        $this->assertFalse($p['final']);
    }

    public function testMultipartPayloadMergesUploadsBesideTheAnswers() {
        $post = array(
            'page' => '3', 'unit_session_id' => '4', 'final' => '1', 'uuid' => 'u1', 'client_ts' => '2026-09-24 10:00:00',
            'data' => array('name' => 'Ann', 'tags' => array('a')),
            'item_views' => array('answered' => array('5' => '2026-09-24 10:00:01')),
            'hidden' => array('8'),
        );
        $files = array('files' => array(
            'name' => array('photo' => 'me.png'), 'type' => array('photo' => 'image/png'),
            'tmp_name' => array('photo' => '/tmp/php1'), 'error' => array('photo' => 0), 'size' => array('photo' => 12),
        ));
        $p = FormSubmissionService::readPayload('multipart/form-data; boundary=x', '', $post, $files);
        $this->assertSame(3, $p['page']);
        $this->assertSame(4, $p['unit_session_id']);
        $this->assertTrue($p['final']);
        $this->assertSame('Ann', $p['data']['name']);
        $this->assertSame(array('a'), $p['data']['tags']);
        $this->assertSame(array('name' => 'me.png', 'type' => 'image/png', 'tmp_name' => '/tmp/php1', 'error' => 0, 'size' => 12), $p['data']['photo']);
        $this->assertSame(array(8), $p['hidden']);
        $this->assertSame('2026-09-24 10:00:00', $p['client_ts']);
        $this->assertSame('3', $p['raw_page'], 'the envelope keeps the raw value for FormSyncPayload');
    }

    private function item(array $row) {
        return (new ItemFactory(array('yn' => array('1' => 'yes', '2' => 'no'))))->make($row + array('id' => 5, 'study_id' => 1));
    }

    public function testItemStateShapesWhatTheBrowserNeeds() {
        $text = $this->item(array('name' => 'q', 'type' => 'text', 'optional' => 0));
        $s = FormSubmissionService::itemState($text, array('id' => 5, 'page' => 2, 'answer' => 'hi', 'saved' => '2026-09-24 10:00:00', 'hidden' => null));
        $this->assertSame(array('id' => 5, 'page' => 2, 'answerable' => true, 'required' => true, 'answered' => true, 'saved' => true, 'value' => 'hi', 'hidden' => false), $s);

        $s = FormSubmissionService::itemState($text, array('id' => 5, 'page' => 2, 'answer' => null, 'saved' => '2026-09-24 10:00:00', 'hidden' => null));
        $this->assertFalse($s['answered'], 'a blank stored answer is not an answer');
        $this->assertTrue($s['saved'], '…but it was stored: an R default must not refill it');

        $s = FormSubmissionService::itemState($text, array('id' => 5, 'page' => null, 'answer' => null, 'saved' => null, 'hidden' => 1));
        $this->assertSame(1, $s['page'], 'no display row yet: page 1');
        $this->assertFalse($s['answered']);
        $this->assertNull($s['value']);
        $this->assertTrue($s['hidden']);

        $s = FormSubmissionService::itemState($text, array('id' => 5, 'page' => 1, 'answer' => 'x', 'saved' => null, 'hidden' => 0));
        $this->assertFalse($s['answered'], 'an answer without a saved stamp was never stored');
    }

    public function testOptionalNoteAndFileItemsAreNeverRequiredAndFilesShipNoPath() {
        $optional = $this->item(array('name' => 'q', 'type' => 'text', 'optional' => 1));
        $this->assertFalse(FormSubmissionService::itemState($optional, array('id' => 5, 'answer' => null, 'saved' => null))['required']);
        $this->assertTrue(FormSubmissionService::itemState($optional, array('id' => 5, 'answer' => null, 'saved' => null))['answerable']);
        $note = $this->item(array('name' => 'n', 'type' => 'note', 'optional' => 0, 'label' => 'x'));
        $noteState = FormSubmissionService::itemState($note, array('id' => 5, 'answer' => null, 'saved' => null));
        $this->assertFalse($noteState['required']);
        $this->assertFalse($noteState['answerable'], 'a note takes no input');
        $file = $this->item(array('name' => 'f', 'type' => 'file', 'optional' => 0));
        $s = FormSubmissionService::itemState($file, array('id' => 5, 'answer' => 'assets/tmp/x.png', 'saved' => '2026-09-24 10:00:00'));
        $this->assertTrue($s['required']);
        $this->assertTrue($s['answered']);
        $this->assertNull($s['value'], 'the stored path is not shipped');
    }

    public function testMultiValueAnswersArriveAsArrays() {
        $multi = $this->item(array('name' => 'm', 'type' => 'mc_multiple', 'choice_list' => 'yn', 'optional' => 1));
        $this->assertSame(array('1', '2'), FormSubmissionService::itemState($multi, array('id' => 5, 'answer' => '1, 2', 'saved' => 'now'))['value']);
        $this->assertSame(array(), FormSubmissionService::itemState($multi, array('id' => 5, 'answer' => '', 'saved' => 'now'))['value']);
        $this->assertNull(FormSubmissionService::itemState($multi, array('id' => 5, 'answer' => null, 'saved' => null))['value']);
    }

    public function testRequiredIsTheItemsResolvedFlagAndBlankIsNeverRequired() {
        $blank = $this->item(array('name' => 'b', 'type' => 'blank', 'optional' => null, 'label' => 'x'));
        $this->assertFalse(FormSubmissionService::isRequired($blank), 'a blank has nothing to answer');
        $this->assertFalse(FormSubmissionService::isAnswerable($blank));
        $hidden = $this->item(array('name' => 'h', 'type' => 'hidden', 'optional' => null));
        $this->assertFalse(FormSubmissionService::isRequired($hidden), 'an empty optional cell takes the type default (hidden: optional)');
        $text = $this->item(array('name' => 't', 'type' => 'text', 'optional' => null));
        $this->assertTrue(FormSubmissionService::isRequired($text), 'text defaults to required');
    }

    public function testANoInputValueReachesTheBrowserOnlyWhenAShowifReadsIt() {
        $random = $this->item(array('name' => 'cond', 'type' => 'random', 'type_options' => '1,2'));
        $row = array('id' => 5, 'answer' => '2', 'saved' => 'now');
        $this->assertNull(FormSubmissionService::itemState($random, $row, array())['value'], 'no showif reads it');
        $this->assertTrue(FormSubmissionService::itemState($random, $row, array())['answered']);
        $this->assertSame('2', FormSubmissionService::itemState($random, $row, array('cond' => true))['value'], 'a showif reads it');
        $text = $this->item(array('name' => 't', 'type' => 'text'));
        $this->assertSame('x', FormSubmissionService::itemState($text, array('id' => 5, 'answer' => 'x', 'saved' => 'now'), array())['value'], 'an input\'s own answer is always shipped');
    }
}
