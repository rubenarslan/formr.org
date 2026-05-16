<?php

use PHPUnit\Framework\TestCase;

/**
 * Exposes the protected scoping helpers on ApiBase so they can be tested
 * without dragging the full dispatcher / OAuthHelper / Site bootstrap
 * into the test. Mirrors the _ApiBaseScopeFixture pattern in
 * ApiBaseScopeTest.php.
 */
class _ApiBaseScopingFixture extends ApiBase
{
    public function callMayAccessRun($runId)
    {
        return $this->clientMayAccessRun($runId);
    }

    public function callMayAccessSurvey($surveyId)
    {
        return $this->clientMayAccessSurvey($surveyId);
    }
}

class ApiBaseScopingTest extends TestCase
{
    /** @var PDO */
    private static $pdo;

    public static function setUpBeforeClass(): void
    {
        // tests/bootstrap.php already initialised an in-memory SQLite
        // connection. Reuse it so the helper queries that hit
        // $this->db->prepare() find rows we seed below.
        self::$pdo = DB::getInstance()->pdo();
        self::$pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS survey_run_units (
                id INTEGER PRIMARY KEY,
                run_id INTEGER NOT NULL,
                unit_id INTEGER NOT NULL,
                position INTEGER
            )
SQL);
        self::$pdo->exec('DELETE FROM survey_run_units');
        // run_A has survey 100 and 101; run_B has survey 200.
        self::$pdo->exec("INSERT INTO survey_run_units (id, run_id, unit_id, position) VALUES
            (1, 1, 100, 10),
            (2, 1, 101, 20),
            (3, 2, 200, 10)");
    }

    /**
     * Build a fixture with $allowedIds frozen into the per-request cache,
     * bypassing the OAuthHelper round-trip we don't want in unit tests.
     *
     * @param int[]|null $allowedIds Pass [] for "unrestricted",
     *     [42, 57] for restricted, or null to leave the loader
     *     uninitialised (which forces a DB hit).
     */
    private function makeFixture($allowedIds)
    {
        $fixtureRef = new ReflectionClass(_ApiBaseScopingFixture::class);
        /** @var _ApiBaseScopingFixture $obj */
        $obj = $fixtureRef->newInstanceWithoutConstructor();

        // $db is declared `protected` on ApiBase (parent class) — that
        // property is reachable from the subclass ReflectionClass.
        $dbProp = $fixtureRef->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($obj, DB::getInstance());

        if ($allowedIds !== null) {
            // cachedAllowedRunIds + allowedRunIdsLoaded are PRIVATE on
            // ApiBase, so reflection has to use the declaring class.
            $baseRef = new ReflectionClass(ApiBase::class);
            $cacheProp = $baseRef->getProperty('cachedAllowedRunIds');
            $cacheProp->setAccessible(true);
            $cacheProp->setValue($obj, $allowedIds);
            $loadedProp = $baseRef->getProperty('allowedRunIdsLoaded');
            $loadedProp->setAccessible(true);
            $loadedProp->setValue($obj, true);
        }

        return $obj;
    }

    // --- clientMayAccessRun -------------------------------------------

    public function testRunAccessUnrestrictedWhenAllowlistEmpty()
    {
        $base = $this->makeFixture([]);
        $this->assertTrue($base->callMayAccessRun(1));
        $this->assertTrue($base->callMayAccessRun(9999));
    }

    public function testRunAccessGrantedForAllowlistedId()
    {
        $base = $this->makeFixture([42, 57]);
        $this->assertTrue($base->callMayAccessRun(42));
        $this->assertTrue($base->callMayAccessRun(57));
    }

    public function testRunAccessDeniedForNonAllowlistedId()
    {
        $base = $this->makeFixture([42]);
        $this->assertFalse($base->callMayAccessRun(43));
        $this->assertFalse($base->callMayAccessRun(0));
    }

    public function testRunAccessUsesStrictComparisonIntVsString()
    {
        // tokenData['client_id'] is a string; runs are unsigned ints.
        // Make sure the in_array check doesn't false-positive on loose
        // comparisons between '42' and 42 in either direction.
        $base = $this->makeFixture([42]);
        $this->assertTrue($base->callMayAccessRun('42'));
        // '42abc' must not match — fixture casts via (int) before
        // comparing.
        $this->assertFalse($base->callMayAccessRun('43'));
    }

    // --- clientMayAccessSurvey -----------------------------------------

    public function testSurveyAccessUnrestrictedWhenAllowlistEmpty()
    {
        $base = $this->makeFixture([]);
        $this->assertTrue($base->callMayAccessSurvey(100));
        $this->assertTrue($base->callMayAccessSurvey(999)); // orphan still passes
    }

    public function testSurveyAccessGrantedWhenSurveyInAllowlistedRun()
    {
        $base = $this->makeFixture([1]);
        $this->assertTrue($base->callMayAccessSurvey(100));
        $this->assertTrue($base->callMayAccessSurvey(101));
    }

    public function testSurveyAccessDeniedForSurveyOnlyInOtherRun()
    {
        $base = $this->makeFixture([1]);
        $this->assertFalse($base->callMayAccessSurvey(200));
    }

    public function testSurveyAccessDeniedForOrphanSurvey()
    {
        $base = $this->makeFixture([1, 2]);
        // 300 has no survey_run_units row → unreachable through any run
        $this->assertFalse($base->callMayAccessSurvey(300));
    }

    public function testSurveyAccessGrantedWhenSharedAcrossMultipleAllowlistedRuns()
    {
        // Seed a survey that appears in both runs and confirm a client
        // with both runs allowlisted still gets a single hit (no
        // duplicate-row weirdness from the LIMIT 1).
        self::$pdo->exec("INSERT INTO survey_run_units (id, run_id, unit_id, position) VALUES (4, 2, 100, 30)");
        $base = $this->makeFixture([1, 2]);
        $this->assertTrue($base->callMayAccessSurvey(100));
    }

    // --- allowedRunIds precedence (per-token wins over per-client) ----

    /**
     * Build a fixture without pre-loading the cache, so allowedRunIds()
     * lazily resolves through its real lookup path.
     */
    private function makeFixtureForLazyLookup(array $tokenData)
    {
        $fixtureRef = new ReflectionClass(_ApiBaseScopingFixture::class);
        $obj = $fixtureRef->newInstanceWithoutConstructor();
        $tokenProp = $fixtureRef->getProperty('tokenData');
        $tokenProp->setAccessible(true);
        $tokenProp->setValue($obj, $tokenData);
        return $obj;
    }

    public function testPerTokenRunIdsOverridePerClientFallback()
    {
        // No client_id at all — so the per-client lookup would return
        // [] (unrestricted). With a per-token run_ids string set,
        // allowedRunIds must return that list, not fall through to
        // unrestricted.
        $base = $this->makeFixtureForLazyLookup([
            'scope'   => 'run:read',
            'user_id' => 'unused@example.com',
            'run_ids' => '42,57',
        ]);
        $this->assertSame([42, 57], $base->allowedRunIds());
        $this->assertTrue($base->callMayAccessRun(42));
        $this->assertTrue($base->callMayAccessRun(57));
        $this->assertFalse($base->callMayAccessRun(43));
    }

    public function testPerTokenRunIdsSingleEntry()
    {
        $base = $this->makeFixtureForLazyLookup([
            'scope'   => 'run:read',
            'user_id' => 'u@example.com',
            'run_ids' => '42',
        ]);
        $this->assertSame([42], $base->allowedRunIds());
    }

    public function testPerTokenRunIdsEmptyStringIsExplicitDenyEverything()
    {
        // Defensive: a token row with run_ids = '' (rather than NULL)
        // should be treated as an explicit empty allowlist, not as
        // "no restriction". The OpenCPU mint path only writes
        // non-empty lists so this state shouldn't occur in practice,
        // but if it does — fail closed.
        $base = $this->makeFixtureForLazyLookup([
            'scope'   => 'run:read',
            'user_id' => 'u@example.com',
            'run_ids' => '',
        ]);
        $this->assertSame([], $base->allowedRunIds());
    }

    public function testTokenWithoutRunIdsFieldFallsThroughToPerClient()
    {
        // run_ids key absent entirely → per-client lookup runs.
        // Stub the OAuthHelper singleton so allowedRunIds() doesn't
        // try to materialise the real Site::getOauthServer (which
        // would reach for the live MySQL connection — the unit-test
        // bootstrap only has SQLite). The stub answers
        // getRunAllowlist(...) with [] = unrestricted, mirroring an
        // empty oauth_client_runs lookup.
        $stub = new class extends OAuthHelper {
            public function __construct() { /* no Site::getOauthServer */ }
            public function getRunAllowlist($clientId) { return []; }
        };
        $singletonProp = (new ReflectionClass(OAuthHelper::class))->getProperty('instance');
        $singletonProp->setAccessible(true);
        $prev = $singletonProp->getValue();
        $singletonProp->setValue(null, $stub);
        try {
            $base = $this->makeFixtureForLazyLookup([
                'scope'   => 'run:read',
                'user_id' => 'u@example.com',
                'client_id' => 'c_test',
            ]);
            $this->assertSame([], $base->allowedRunIds());
            $this->assertTrue($base->callMayAccessRun(123));
        } finally {
            $singletonProp->setValue(null, $prev);
        }
    }

    public function testPerTokenRunIdsIgnoresNonNumericNoise()
    {
        $base = $this->makeFixtureForLazyLookup([
            'scope'   => 'run:read',
            'user_id' => 'u@example.com',
            'run_ids' => '42, , bogus, 7',
        ]);
        $this->assertSame([42, 7], $base->allowedRunIds());
    }
}
