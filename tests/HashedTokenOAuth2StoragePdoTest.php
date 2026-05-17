<?php
use PHPUnit\Framework\TestCase;

/**
 * Verifies that HashedTokenOAuth2StoragePdo persists SHA-256 hashes for
 * bearer credentials (access tokens, refresh tokens, authorization codes,
 * client secrets) while remaining transparent to callers — they pass and
 * receive raw values, only the database row holds the hash.
 *
 * A DB compromise alone must not yield replayable bearer credentials, and
 * the lookup-by-hash logic must round-trip correctly.
 */
class HashedTokenOAuth2StoragePdoTest extends TestCase
{
    /** @var \PDO */
    private $pdo;
    /** @var HashedTokenOAuth2StoragePdo */
    private $storage;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Minimal schema mirroring the prod tables the storage actually
        // touches. We only create columns the storage reads/writes.
        $this->pdo->exec('CREATE TABLE oauth_clients (
            client_id TEXT PRIMARY KEY,
            client_secret TEXT,
            redirect_uri TEXT,
            grant_types TEXT,
            scope TEXT,
            user_id TEXT
        )');
        $this->pdo->exec('CREATE TABLE oauth_access_tokens (
            access_token TEXT PRIMARY KEY,
            client_id TEXT,
            user_id TEXT,
            expires DATETIME,
            scope TEXT
        )');
        $this->pdo->exec('CREATE TABLE oauth_refresh_tokens (
            refresh_token TEXT PRIMARY KEY,
            client_id TEXT,
            user_id TEXT,
            expires DATETIME,
            scope TEXT
        )');
        $this->pdo->exec('CREATE TABLE oauth_authorization_codes (
            authorization_code TEXT PRIMARY KEY,
            client_id TEXT,
            user_id TEXT,
            redirect_uri TEXT,
            expires DATETIME,
            scope TEXT,
            id_token TEXT
        )');

        $this->storage = new HashedTokenOAuth2StoragePdo($this->pdo);
    }

    private function rawRow($table, $key, $value)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE $key = :v");
        $stmt->execute([':v' => $value]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function testAccessTokenRoundtripStoresHash()
    {
        $raw = 'raw-access-token-abcdef';
        $expires = time() + 3600;
        $this->assertTrue($this->storage->setAccessToken($raw, 'cid', 'user@example.com', $expires, 'user:read'));

        // DB row holds the hash, never the raw value.
        $hash = hash('sha256', $raw);
        $row = $this->rawRow('oauth_access_tokens', 'access_token', $hash);
        $this->assertNotFalse($row, 'token should be persisted under its hash');
        $this->assertSame($hash, $row['access_token']);
        $this->assertFalse($this->rawRow('oauth_access_tokens', 'access_token', $raw));

        // getAccessToken takes the raw token and returns it as-is in the
        // result so downstream code that re-uses 'access_token' stays sane.
        $fetched = $this->storage->getAccessToken($raw);
        $this->assertIsArray($fetched);
        $this->assertSame($raw, $fetched['access_token']);
        $this->assertSame('cid', $fetched['client_id']);
        $this->assertSame('user@example.com', $fetched['user_id']);
        $this->assertSame('user:read', $fetched['scope']);

        // Wrong token must miss.
        $this->assertFalse($this->storage->getAccessToken('not-the-token'));
    }

    public function testRefreshTokenRoundtripStoresHash()
    {
        $raw = 'raw-refresh-token-xyz';
        $this->assertTrue($this->storage->setRefreshToken($raw, 'cid', 'user@example.com', time() + 3600, 'user:read'));

        $hash = hash('sha256', $raw);
        $this->assertNotFalse($this->rawRow('oauth_refresh_tokens', 'refresh_token', $hash));
        $this->assertFalse($this->rawRow('oauth_refresh_tokens', 'refresh_token', $raw));

        $fetched = $this->storage->getRefreshToken($raw);
        $this->assertIsArray($fetched);
        $this->assertSame($raw, $fetched['refresh_token']);

        // unsetRefreshToken must hash the input before deleting.
        $this->assertTrue((bool) $this->storage->unsetRefreshToken($raw));
        $this->assertFalse($this->storage->getRefreshToken($raw));
    }

    public function testAuthorizationCodeRoundtripStoresHash()
    {
        $raw = 'raw-authz-code-123';
        $this->assertTrue($this->storage->setAuthorizationCode($raw, 'cid', 'user@example.com', 'https://example/cb', time() + 600, 'user:read'));

        $hash = hash('sha256', $raw);
        $this->assertNotFalse($this->rawRow('oauth_authorization_codes', 'authorization_code', $hash));
        $this->assertFalse($this->rawRow('oauth_authorization_codes', 'authorization_code', $raw));

        $fetched = $this->storage->getAuthorizationCode($raw);
        $this->assertIsArray($fetched);
        $this->assertSame($raw, $fetched['authorization_code']);
        $this->assertSame('https://example/cb', $fetched['redirect_uri']);

        // expireAuthorizationCode must hash the input before deleting.
        $this->assertTrue((bool) $this->storage->expireAuthorizationCode($raw));
        $this->assertFalse($this->storage->getAuthorizationCode($raw));
    }

    public function testClientSecretIsHashedAndCheckedConstantTime()
    {
        $rawSecret = 'rawSuperSecret';
        $this->assertTrue($this->storage->setClientDetails('cid', $rawSecret, 'https://x/cb'));

        // Stored hash matches what we'd compute, never the raw secret.
        $row = $this->rawRow('oauth_clients', 'client_id', 'cid');
        $this->assertSame(hash('sha256', $rawSecret), $row['client_secret']);
        $this->assertNotSame($rawSecret, $row['client_secret']);

        // checkClientCredentials accepts the raw value, rejects others.
        $this->assertTrue($this->storage->checkClientCredentials('cid', $rawSecret));
        $this->assertFalse($this->storage->checkClientCredentials('cid', 'wrong'));
        $this->assertFalse($this->storage->checkClientCredentials('cid', null));
        $this->assertFalse($this->storage->checkClientCredentials('cid', ''));
        $this->assertFalse($this->storage->checkClientCredentials('nope', $rawSecret));
    }

    public function testGetClientDetailsHidesSecret()
    {
        $this->storage->setClientDetails('cid', 'rawSuperSecret', 'https://x/cb');
        $details = $this->storage->getClientDetails('cid');

        $this->assertIsArray($details);
        $this->assertArrayNotHasKey('client_secret', $details, 'getClientDetails must not return any secret material');
    }

    public function testIsPublicClientReflectsEmptySecret()
    {
        // Public client has no secret.
        $this->storage->setClientDetails('public-cid', null, 'https://x/cb');
        $this->assertTrue($this->storage->isPublicClient('public-cid'));

        $this->storage->setClientDetails('confidential-cid', 'rawSuperSecret', 'https://x/cb');
        $this->assertFalse($this->storage->isPublicClient('confidential-cid'));
    }

    public function testSetAccessTokenIsIdempotentAndUpdates()
    {
        $raw = 'rotate-me';
        $this->assertTrue($this->storage->setAccessToken($raw, 'cid', 'a@x', time() + 100, 'user:read'));
        // Repeat with a different scope — must UPDATE, not INSERT a duplicate row.
        $this->assertTrue($this->storage->setAccessToken($raw, 'cid', 'a@x', time() + 200, 'user:write'));

        $row = $this->rawRow('oauth_access_tokens', 'access_token', hash('sha256', $raw));
        $this->assertSame('user:write', $row['scope']);

        $count = $this->pdo->query("SELECT COUNT(*) FROM oauth_access_tokens")->fetchColumn();
        $this->assertSame('1', (string) $count);
    }
}
