<?php

use PHPUnit\Framework\TestCase;

/**
 * Fixture subclass that exposes the protected validation helpers on
 * OAuthHelper. We can't drive the full createClient/refreshToken paths
 * from a unit test because they call bshaffer's PDO storage backend,
 * which is bound to a separate connection. The validators are what
 * actually decide whether a scope/run-id list is acceptable, so they're
 * the load-bearing pieces worth testing in isolation.
 */
class _OAuthHelperScopesFixture extends OAuthHelper
{
    public function callValidateScopes(array $scopes)
    {
        return $this->validateScopes($scopes);
    }

    public function callValidateRunIds(User $u, array $runIds)
    {
        return $this->validateRunIds($u, $runIds);
    }

    public function callReplaceClientRuns($clientId, array $runIds)
    {
        $this->replaceClientRuns($clientId, $runIds);
    }

    public function callParseScopeString($s)
    {
        return $this->parseScopeString($s);
    }
}

class OAuthHelperScopesTest extends TestCase
{
    /** @var PDO */
    private static $pdo;
    /** @var _OAuthHelperScopesFixture */
    private $helper;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = DB::getInstance()->pdo();
        self::$pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS oauth_scopes (
                scope TEXT,
                is_default INTEGER
            )
SQL);
        self::$pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS oauth_client_runs (
                client_id TEXT NOT NULL,
                run_id INTEGER NOT NULL,
                PRIMARY KEY (client_id, run_id)
            )
SQL);
        self::$pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS survey_runs (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                name TEXT
            )
SQL);
        // Wipe + seed each class run.
        self::$pdo->exec('DELETE FROM oauth_scopes');
        self::$pdo->exec("INSERT INTO oauth_scopes (scope, is_default) VALUES
            ('user:read', 0), ('user:write', 0),
            ('survey:read', 0), ('survey:write', 0),
            ('run:read', 0), ('run:write', 0),
            ('session:read', 0), ('session:write', 0),
            ('data:read', 0), ('file:read', 0), ('file:write', 0)");
        self::$pdo->exec('DELETE FROM survey_runs');
        self::$pdo->exec("INSERT INTO survey_runs (id, user_id, name) VALUES
            (1, 7, 'owned_run_a'),
            (2, 7, 'owned_run_b'),
            (99, 8, 'foreign_run')");
    }

    protected function setUp(): void
    {
        // newInstanceWithoutConstructor so we don't reach into Site for
        // an OAuth2\Server. The validators don't need it. We do need
        // the config['scope_table'] key, which lives on the parent
        // constructor — set it via reflection.
        $ref = new ReflectionClass(_OAuthHelperScopesFixture::class);
        $this->helper = $ref->newInstanceWithoutConstructor();
        $configProp = $ref->getProperty('config');
        $configProp->setAccessible(true);
        $configProp->setValue($this->helper, [
            'scope_table' => 'oauth_scopes',
            'client_table' => 'oauth_clients',
        ]);

        self::$pdo->exec('DELETE FROM oauth_client_runs');
    }

    private function makeUser($id)
    {
        // Bypass User::__construct so we don't load anything from DB.
        $u = (new ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $idProp = (new ReflectionClass(User::class))->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($u, $id);
        return $u;
    }

    // --- validateScopes ------------------------------------------------

    public function testValidateScopesAcceptsKnownScopes()
    {
        $this->assertSame(['run:read', 'run:write'], $this->helper->callValidateScopes(['run:read', 'run:write']));
    }

    public function testValidateScopesRejectsUnknownScope()
    {
        // Fail closed: one bogus value spoils the whole list.
        $this->assertFalse($this->helper->callValidateScopes(['run:read', 'totally:made:up']));
    }

    public function testValidateScopesDeduplicates()
    {
        $this->assertSame(['run:read'], $this->helper->callValidateScopes(['run:read', 'run:read']));
    }

    public function testValidateScopesAcceptsEmptyList()
    {
        // Empty scope list is the new default-deny state — must not be
        // confused with "rejection."
        $this->assertSame([], $this->helper->callValidateScopes([]));
    }

    public function testValidateScopesIgnoresEmptyStrings()
    {
        $this->assertSame(['run:read'], $this->helper->callValidateScopes(['', 'run:read', '']));
    }

    // --- validateRunIds ------------------------------------------------

    public function testValidateRunIdsAcceptsOwnedRuns()
    {
        $u = $this->makeUser(7);
        $this->assertSame([1, 2], $this->helper->callValidateRunIds($u, [1, 2]));
    }

    public function testValidateRunIdsRejectsForeignRun()
    {
        // Run 99 belongs to user 8; user 7 must not be able to scope a
        // client to it.
        $u = $this->makeUser(7);
        $this->assertFalse($this->helper->callValidateRunIds($u, [1, 99]));
    }

    public function testValidateRunIdsRejectsNonexistentRun()
    {
        $u = $this->makeUser(7);
        $this->assertFalse($this->helper->callValidateRunIds($u, [1, 12345]));
    }

    public function testValidateRunIdsAcceptsEmpty()
    {
        $u = $this->makeUser(7);
        // Empty = unrestricted; must not be conflated with "rejection."
        $this->assertSame([], $this->helper->callValidateRunIds($u, []));
    }

    public function testValidateRunIdsDeduplicatesAndCastsInt()
    {
        $u = $this->makeUser(7);
        $this->assertSame([1, 2], $this->helper->callValidateRunIds($u, ['1', 1, '2', 2]));
    }

    // --- replaceClientRuns + getRunAllowlist ---------------------------

    public function testReplaceClientRunsWritesExactlyTheGivenRows()
    {
        $this->helper->callReplaceClientRuns('client-A', [1, 2]);
        $rows = self::$pdo->query("SELECT run_id FROM oauth_client_runs WHERE client_id='client-A' ORDER BY run_id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([1, 2], array_map('intval', $rows));
    }

    public function testReplaceClientRunsAtomicallyReplacesPriorAllowlist()
    {
        $this->helper->callReplaceClientRuns('client-A', [1, 2]);
        $this->helper->callReplaceClientRuns('client-A', [2]);
        $rows = self::$pdo->query("SELECT run_id FROM oauth_client_runs WHERE client_id='client-A'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([2], array_map('intval', $rows));
    }

    public function testReplaceClientRunsWithEmptyArrayClearsAllowlist()
    {
        $this->helper->callReplaceClientRuns('client-A', [1, 2]);
        $this->helper->callReplaceClientRuns('client-A', []);
        $count = (int) self::$pdo->query("SELECT COUNT(*) FROM oauth_client_runs WHERE client_id='client-A'")->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testGetRunAllowlistReturnsEmptyForUnknownClient()
    {
        $this->assertSame([], $this->helper->getRunAllowlist('does-not-exist'));
    }

    public function testGetRunAllowlistRoundTripsRows()
    {
        $this->helper->callReplaceClientRuns('client-A', [2, 1]);
        $result = $this->helper->getRunAllowlist('client-A');
        sort($result);
        $this->assertSame([1, 2], $result);
    }

    public function testGetRunAllowlistRejectsEmptyClientId()
    {
        // Defensive — passing empty/null must not match "any client" in
        // a hypothetical future LIKE-style query.
        $this->assertSame([], $this->helper->getRunAllowlist(''));
        $this->assertSame([], $this->helper->getRunAllowlist(null));
    }

    // --- parseScopeString ----------------------------------------------

    public function testParseScopeStringHandlesEmpty()
    {
        $this->assertSame([], $this->helper->callParseScopeString(null));
        $this->assertSame([], $this->helper->callParseScopeString(''));
        $this->assertSame([], $this->helper->callParseScopeString('   '));
    }

    public function testParseScopeStringSplitsOnWhitespace()
    {
        $this->assertSame(['run:read', 'run:write'], $this->helper->callParseScopeString('run:read run:write'));
        $this->assertSame(['run:read', 'run:write'], $this->helper->callParseScopeString("  run:read\trun:write  "));
    }
}
