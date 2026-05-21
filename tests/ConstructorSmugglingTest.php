<?php
use PHPUnit\Framework\TestCase;

/**
 * Defense-in-depth regression test for Phase B3 of the broader audit.
 *
 * Several Model subclasses' constructors call $this->assignProperties(
 * $options) before $this->load(), so a caller passing untrusted $options
 * would write any matching public property (id, user_id, admin,
 * email_verified, …) before the trusted DB row arrives. No current V1
 * endpoint reaches these constructors with attacker-controlled $options,
 * but the surface is growing — this test pins down the rule that
 * constructor $options is allowlisted against a tiny per-class set.
 */
class ConstructorSmugglingTest extends TestCase
{
    public function testUserConstructorDropsAdminEmailVerifiedLoggedIn(): void
    {
        // id=null, code=null → no DB load attempted, the smuggled props
        // would survive in pre-fix code.
        $smuggled = [
            'id' => 999,
            'admin' => 100,
            'email_verified' => 1,
            'logged_in' => true,
            'email' => 'attacker@nope.local',
        ];
        // In pre-fix code, email='nope' would survive because email is a
        // public property. In post-fix code, only 'cron' is allowlisted.
        $u = new User(null, null, $smuggled);

        $this->assertNotSame(999, $u->id, 'id should not be smuggled');
        $this->assertNotSame(100, $u->admin, 'admin must not be settable from $options');
        $this->assertNotSame(1, $u->email_verified, 'email_verified must not be settable from $options');
        $this->assertFalse($u->logged_in, 'logged_in must not be settable from $options');
        $this->assertNotSame('attacker@nope.local', $u->email, 'email must not be settable from $options');
    }

    public function testUserConstructorStillHonorsCronFlag(): void
    {
        $u = new User(null, null, ['cron' => true]);
        $this->assertTrue($u->isCron(), 'cron flag must still be settable (used by bin/cron*.php)');
    }

    public function testSurveyStudyConstructorDropsResultsTableValid(): void
    {
        $smuggled = [
            'results_table' => 'PWNED_TABLE',
            'valid' => 1,
            'created' => '2099-01-01 00:00:00',
            'modified' => '2099-01-01 00:00:00',
            'unlinked' => 1,
        ];
        // id=null, no name in $options → load() finds nothing.
        $study = new SurveyStudy(null, $smuggled);

        $this->assertNotSame('PWNED_TABLE', $study->results_table);
        $this->assertNotSame(1, $study->unlinked);
        // valid is the public property the parent Model uses to track
        // load-success; the constructor must not let request input mark
        // an unloaded row as valid.
        $this->assertFalse((bool) $study->valid);
        $this->assertNotSame('2099-01-01 00:00:00', $study->created);
        $this->assertNotSame('2099-01-01 00:00:00', $study->modified);
    }

    public function testSurveyStudyConstructorStillHonorsLookupKeys(): void
    {
        // The lookup helpers (loadByName, loadByUserAndName) construct
        // with {name, user_id}; the load() call uses these as the
        // findRow filter, but assignProperties also temporarily writes
        // them to the model. Verify the allowlist still permits this
        // legitimate use.
        $study = new SurveyStudy(null, ['name' => 'no_such_survey', 'user_id' => 42]);
        $this->assertSame('no_such_survey', $study->name);
        $this->assertSame(42, $study->user_id);
    }
}
