<?php

/**
 * Stub graph for NotificationThrottleTest and OpenCPUDownAdminNotifyTest.
 *
 * Lives in a helper (require_once'd from setUp(), NOT at test-file scope)
 * because defining `… extends User` at file scope loads the User class
 * during PHPUnit's test discovery — which breaks
 * ClassLoaderTest::testNonExistent, whose whole point is that User is not
 * loaded until something needs it.
 */
class NTTUser extends User
{
    public function getEmailAccounts()
    {
        // Empty pre-fix so notifyStudyAdmin bails before touching a mailer;
        // post-fix the deliver() seam is overridden and this is never read.
        return [];
    }
}

class NTTRun extends Run
{
    public $ownerStub;

    public function getOwner()
    {
        return $this->ownerStub;
    }
}

class NTTRunSession extends RunSession
{
    public $runStub;

    public function getRun()
    {
        return $this->runStub;
    }

    public function isTesting()
    {
        return false;
    }
}

class NTTExposedNotification extends Notification
{
    public function canBeSentPublic(UnitSession $us, string $type): bool
    {
        return $this->canBeSent($us, $type);
    }

    public function logSentPublic(UnitSession $us, string $message, string $type, User $recipient): void
    {
        $this->logNotification($us, $message, $type, $recipient);
    }
}

class NTTRecordingNotification extends NTTExposedNotification
{
    public $delivered = [];

    protected function deliver(User $studyOwner, UnitSession $unitSession, string $message, string $type): bool
    {
        $this->delivered[] = $message;
        return true;
    }
}

/** deliver() as it looks when the mail guard refused the owner's address (vs. an SMTP failure). */
class NTTGuardRefusedNotification extends NTTExposedNotification
{
    public $attempts = 0;
    public $smtpFailure = false;

    protected function deliver(User $studyOwner, UnitSession $unitSession, string $message, string $type): bool
    {
        $this->attempts++;
        $this->lastSendGuardRefused = !$this->smtpFailure;
        return false;
    }
}

class ODANUnitSession extends UnitSession
{
    public function getRunData($q, $required = null)
    {
        return [];
    }
}

class ODANRecordingNotification extends Notification
{
    public $messages = [];

    protected function deliver(User $studyOwner, UnitSession $unitSession, string $message, string $type): bool
    {
        $this->messages[] = $message;
        return true;
    }
}
