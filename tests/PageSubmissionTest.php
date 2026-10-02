<?php

use PHPUnit\Framework\TestCase;

/**
 * PageSubmission: a POST is a submission only for the unit session its form
 * names, and a body over post_max_size (whose fields PHP dropped) is told
 * apart from one under it — with a limit of 0 meaning no limit, which once
 * made every v1 page POST look oversized.
 */
class PageSubmissionTest extends TestCase {

    private $server;
    private $post;

    protected function setUp(): void {
        $this->server = $_SERVER;
        $this->post = $_POST;
    }

    protected function tearDown(): void {
        $_SERVER = $this->server;
        $_POST = $this->post;
        PageSubmission::forget();
    }

    public function testNoLimitIsNeverExceeded() {
        foreach (array('0', '', ' 0 ') as $limit) {
            $this->assertFalse(PageSubmission::bodyExceedsPostMaxSize($limit, 50 * 1024 * 1024), var_export($limit, true));
        }
    }

    public function testALimitIsExceededOnlyAboveIt() {
        $this->assertFalse(PageSubmission::bodyExceedsPostMaxSize('8M', 1024));
        $this->assertFalse(PageSubmission::bodyExceedsPostMaxSize('8M', 8 * 1024 * 1024));
        $this->assertTrue(PageSubmission::bodyExceedsPostMaxSize('8M', 8 * 1024 * 1024 + 1));
        $this->assertTrue(PageSubmission::bodyExceedsPostMaxSize('1k', 2000));
        $this->assertFalse(PageSubmission::bodyExceedsPostMaxSize('1G', 50 * 1024 * 1024));
    }

    public function testTheRequestsOwnLengthByDefault() {
        $_SERVER['CONTENT_LENGTH'] = '2000';
        $this->assertTrue(PageSubmission::bodyExceedsPostMaxSize('1k'));
        unset($_SERVER['CONTENT_LENGTH']);
        $this->assertFalse(PageSubmission::bodyExceedsPostMaxSize('1k'));
    }

    public function testAPostIsOnlyForTheUnitSessionItNames() {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $own = $this->getMockBuilder(UnitSession::class)->disableOriginalConstructor()->getMock();
        $own->id = 41;
        $other = $this->getMockBuilder(UnitSession::class)->disableOriginalConstructor()->getMock();
        $other->id = 42;

        $_POST = array('session_id' => '41', 'q1' => 'x');
        PageSubmission::capture();
        $this->assertSame('x', PageSubmission::forUnitSession($own)['fields']['q1']);
        $this->assertNull(PageSubmission::forUnitSession($other), 'a stale tab, another unit session');

        foreach (array(array('q1' => 'x'), array('session_id' => '', 'q1' => 'x'), array('session_id' => '41abc'), array('session_id' => array('41'))) as $post) {
            $_POST = $post;
            PageSubmission::capture();
            $this->assertNull(PageSubmission::forUnitSession($own), json_encode($post));
        }

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = array('session_id' => '41');
        PageSubmission::capture();
        $this->assertNull(PageSubmission::forUnitSession($own), 'a page load is no submission');
    }
}
