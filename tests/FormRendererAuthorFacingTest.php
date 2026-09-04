<?php

use PHPUnit\Framework\TestCase;

/**
 * form_v2 must not show study-authoring diagnostics to participants.
 *
 * Two things used to leak into the participant document:
 *   - the "these item types have not yet been end-to-end smoke-tested on
 *     form_v2" banner (deliberately ungated, on the theory that an admin-role
 *     check is impossible on the participant subdomain), and
 *   - the `r(...) in showif` spreadsheet validation error, which reached the
 *     .alert-danger banner and the item's own error slot.
 *
 * Both are now gated on FormRenderer::isAuthorFacingSession(), which reads the
 * run session (preview / testing / cron) rather than an admin cookie — so it
 * works across the origin split.
 */
class FormRendererAuthorFacingTest extends TestCase
{
    private function renderer($testingStudy, $testing, $cron = false, $layout = 'default')
    {
        $runSession = $this->createMock(RunSession::class);
        $runSession->method('isTestingStudy')->willReturn($testingStudy);
        $runSession->method('isTesting')->willReturn($testing);
        $runSession->method('isCron')->willReturn($cron);
        $runSession->method('getRun')->willReturn($this->createMock(Run::class));

        $unitSession = $this->createMock(UnitSession::class);
        $unitSession->runSession = $runSession;
        $unitSession->validatedStudyItems = array();
        $unitSession->method('getDbConnection')->willReturn($this->createMock(DB::class));

        $study = $this->createMock(SurveyStudy::class);
        $study->id = 42;
        $study->name = 'teststudy';
        $study->layout = $layout;

        return new FormRenderer($study, $unitSession);
    }

    private function call($renderer, $method)
    {
        $ref = new ReflectionMethod(FormRenderer::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($renderer);
    }

    private function setRenderedItems($renderer, array $items)
    {
        $ref = new ReflectionProperty(SpreadsheetRenderer::class, 'renderedItems');
        $ref->setAccessible(true);
        $ref->setValue($renderer, $items);
    }

    public function testParticipantSessionIsNotAuthorFacing()
    {
        $this->assertFalse($this->call($this->renderer(false, false), 'isAuthorFacingSession'));
    }

    public function testAdminPreviewIsAuthorFacing()
    {
        $this->assertTrue($this->call($this->renderer(true, false), 'isAuthorFacingSession'));
    }

    public function testTestingRunSessionIsAuthorFacing()
    {
        $this->assertTrue($this->call($this->renderer(false, true), 'isAuthorFacingSession'));
    }

    public function testCronSessionIsAuthorFacing()
    {
        $this->assertTrue($this->call($this->renderer(false, false, true), 'isAuthorFacingSession'));
    }

    /** THE regression: participants must never see the unverified-types banner. */
    public function testUnverifiedTypesNoticeIsHiddenFromParticipants()
    {
        $renderer = $this->renderer(false, false);
        $this->setRenderedItems($renderer, array((object) array('type' => 'audio')));
        $this->assertSame('', $this->call($renderer, 'renderUnverifiedTypesNotice'));
    }

    public function testUnverifiedTypesNoticeIsShownToAuthors()
    {
        $renderer = $this->renderer(true, false);
        $this->setRenderedItems($renderer, array(
            (object) array('type' => 'audio'),
            (object) array('type' => 'text'),
        ));
        $html = $this->call($renderer, 'renderUnverifiedTypesNotice');
        $this->assertStringContainsString('audio', $html);
        $this->assertStringNotContainsString('text</code>', $html);
    }

    /** No unverified type present: nothing, even for an author. */
    public function testNoticeOmittedWhenNoUnverifiedTypePresent()
    {
        $renderer = $this->renderer(true, false);
        $this->setRenderedItems($renderer, array((object) array('type' => 'text')));
        $this->assertSame('', $this->call($renderer, 'renderUnverifiedTypesNotice'));
    }

    /** Solo layout has nowhere to put an author hint. */
    public function testNoticeOmittedInSoloLayoutEvenForAuthors()
    {
        $renderer = $this->renderer(true, false, false, 'solo');
        $this->setRenderedItems($renderer, array((object) array('type' => 'video')));
        $this->assertSame('', $this->call($renderer, 'renderUnverifiedTypesNotice'));
    }
}
