<?php

/**
 * X4, channel half — when OpenCPU is unreachable, RunUnit::getParsedBody's
 * down-branch must notify the study admin itself, with a message that
 * names the failing unit and is not built from a stale global (X3).
 *
 * Before the fix only the R-error branch notified; the down-branch relied
 * on a duplicate call in Page::getUnitSessionOutput (throttled away in the
 * R-error case, X3-poisoned in the down case) — and every other
 * getParsedBody caller had no channel at all.
 *
 * Stubs live in tests/helpers/notification_test_stubs.php, loaded lazily
 * from setUp() so test discovery doesn't pre-load User under
 * ClassLoaderTest's feet.
 */
class OpenCPUDownAdminNotifyTest extends \PHPUnit\Framework\TestCase
{
    private $savedInstances;

    /** @var ODANRecordingNotification */
    private $recorder;

    protected function setUp(): void
    {
        require_once __DIR__ . '/helpers/notification_test_stubs.php';

        // Unreachable OpenCPU singleton (connection refused, no timeout).
        $prop = new ReflectionProperty(OpenCPU::class, 'instances');
        $prop->setAccessible(true);
        $this->savedInstances = $prop->getValue();
        $unreachable = (new ReflectionClass(OpenCPU::class))->newInstanceWithoutConstructor();
        foreach (['localUrl', 'publicUrl'] as $urlProp) {
            $p = new ReflectionProperty(OpenCPU::class, $urlProp);
            $p->setAccessible(true);
            $p->setValue($unreachable, 'http://127.0.0.1:1');
        }
        $prop->setValue(null, ['opencpu_instance' => $unreachable]);

        // Recording Notification singleton.
        $this->recorder = new ODANRecordingNotification();
        $instProp = new ReflectionProperty(Notification::class, 'instance');
        $instProp->setAccessible(true);
        $instProp->setValue(null, $this->recorder);

        // Tables getParsedBody and the notification flow touch.
        $pdo = DB::getInstance()->pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS survey_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            unit_id INTEGER, session_id INTEGER, opencpu_url TEXT, created TEXT
        )');
        $pdo->exec('DROP TABLE IF EXISTS survey_notifications');
        $pdo->exec("CREATE TABLE survey_notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id INTEGER NOT NULL, session_id INTEGER NOT NULL,
            message TEXT NOT NULL, type TEXT NOT NULL DEFAULT 'error',
            created TEXT NOT NULL, recipient_id INTEGER NOT NULL,
            fingerprint TEXT DEFAULT NULL, suppressed INTEGER NOT NULL DEFAULT 0
        )");

        $GLOBALS['opencpu_local_error'] = null;
        global $opencpu_session;
        $opencpu_session = null;
    }

    protected function tearDown(): void
    {
        $prop = new ReflectionProperty(OpenCPU::class, 'instances');
        $prop->setAccessible(true);
        $prop->setValue(null, $this->savedInstances);

        $instProp = new ReflectionProperty(Notification::class, 'instance');
        $instProp->setAccessible(true);
        $instProp->setValue(null, null);

        $GLOBALS['opencpu_local_error'] = null;
        global $opencpu_session;
        $opencpu_session = null;
    }

    public function testDownBranchNotifiesTheAdminWithAnAttributableMessage(): void
    {
        $owner = (new ReflectionClass(NTTUser::class))->newInstanceWithoutConstructor();
        $owner->id = 99;

        $run = (new ReflectionClass(NTTRun::class))->newInstanceWithoutConstructor();
        $run->id = 1;
        $run->ownerStub = $owner;
        $run->description = '';
        $run->footer_text = '';

        $runSession = (new ReflectionClass(NTTRunSession::class))->newInstanceWithoutConstructor();
        $runSession->runStub = $run;

        $page = (new ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $page->id = 33;
        $page->position = 9;
        $page->type = 'Page';
        $page->modified = mysql_datetime();
        $page->run = $run;
        $dbProp = new ReflectionProperty(Model::class, 'db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($page, DB::getInstance());

        $us = (new ReflectionClass(ODANUnitSession::class))->newInstanceWithoutConstructor();
        $us->id = 777;
        $us->runSession = $runSession;
        $us->runUnit = $page;

        // A stale note from an earlier unit must not leak into the message.
        $GLOBALS['opencpu_local_error'] = 'The R expression is empty — leftover from an earlier unit.';

        $this->assertFalse($page->getParsedBody("```{r}\nplot(1)\n```", $us));
        $this->assertCount(1, $this->recorder->messages, 'the down-branch must notify the study admin');
        $message = $this->recorder->messages[0];
        $this->assertStringContainsString('Page', $message);
        $this->assertStringContainsString('9', $message);
        $this->assertStringNotContainsString('expression is empty', $message);
    }
}
