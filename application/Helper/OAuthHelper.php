<?php

use ParagonIE\HiddenString\HiddenString;

/**
 * This class acts as a Data Access Object for the OAuth2 Library
 * imported from https://github.com/bshaffer/oauth2-server-php
 */
class OAuthHelper
{

    /**
     * @var \OAuth2\Server
     */
    protected $server;

    /**
     *
     * @var \OAuth2\Storage\Pdo
     */
    protected $storage;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var OAuthHelper
     */
    public static $instance;

    const DEFAULT_REDIRECT_URL = 'https://formr.org';

    /**
     * Reserved label for the auto-managed credential that
     * createAccessTokenForUser mints on demand for short-lived internal
     * tokens (OpenCPU bridge). Hidden from the user-facing list; users
     * cannot create or rotate a credential with this label.
     */
    const INTERNAL_LABEL = 'internal';

    /**
     * Hard cap on label length. Matches the VARCHAR(64) column added in
     * patch 054.
     */
    const LABEL_MAX_LENGTH = 64;

    public function __construct($config = array())
    {
        $this->server = Site::getOauthServer();
        $this->storage = $this->server->getStorage('client');
        $this->config = array_merge(array(
            'client_table' => 'oauth_clients',
            'access_token_table' => 'oauth_access_tokens',
            'refresh_token_table' => 'oauth_refresh_tokens',
            'code_table' => 'oauth_authorization_codes',
            'user_table' => 'oauth_users',
            'jwt_table' => 'oauth_jwt',
            'jti_table' => 'oauth_jti',
            'scope_table' => 'oauth_scopes',
            'public_key_table' => 'oauth_public_keys',
        ), $config);
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Create a new OAuth client for the user, identified by a
     * user-supplied label. The (user_id, label) pair is unique — patch
     * 054. Returns plaintext client_secret wrapped in a HiddenString;
     * that is the one and only moment a caller can read the raw secret.
     * Storage holds only a hash.
     *
     * @param User $formrUser
     * @param string $label Human-readable identifier for this credential
     *     (e.g. 'dashboard-readonly', 'cron-2026'). 1..64 chars. The
     *     reserved label 'internal' is rejected — that label is owned
     *     by createAccessTokenForUser for auto-minted OpenCPU tokens.
     * @param string[] $scopes Verb scopes to grant. Must be a subset of
     *     oauth_scopes. Empty list = client_credentials tokens get
     *     empty scope and fail every checkScope() (intentional default
     *     deny). Internal callers bypass this via stamping per-token
     *     scope in createAccessTokenForUser.
     * @param int[] $runIds Run IDs the client may act on. Empty list =
     *     no run restriction. Every id must reference a survey_runs row
     *     owned by $formrUser; foreign ids cause the call to fail with
     *     false and write nothing.
     * @return array{client_id: string, client_secret: HiddenString}|false
     */
    public function createClient(User $formrUser, $label, array $scopes = [], array $runIds = [])
    {
        if (!$formrUser->canAccessApi()) {
            return false;
        }
        $label = $this->validateLabel($label, false);
        if ($label === false) {
            return false;
        }
        return $this->createClientInternal($formrUser, $label, $scopes, $runIds);
    }

    /**
     * List the user's OAuth clients (excluding the reserved 'internal'
     * client). Each row carries label / client_id / scope / run_ids /
     * created so the admin UI can render a credential table. The
     * client_secret is never returned — only the issuance/rotation
     * paths know it, and only at the moment they generate it.
     *
     * @return array<int, array{client_id:string, label:string, scopes:string[], run_ids:int[]}>
     */
    public function listClientsForUser(User $formrUser)
    {
        $db = Site::getDb();
        $stmt = $db->prepare(sprintf(
            'SELECT client_id, label, scope FROM %s WHERE user_id = :user_id AND label != :internal ORDER BY label ASC',
            $this->config['client_table']
        ));
        $stmt->execute([
            ':user_id' => $formrUser->email,
            ':internal' => self::INTERNAL_LABEL,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $clients = [];
        foreach ($rows as $row) {
            $clients[] = [
                'client_id' => $row['client_id'],
                'label' => $row['label'],
                'scopes' => $this->parseScopeString($row['scope'] ?? ''),
                'run_ids' => $this->getRunAllowlist($row['client_id']),
            ];
        }
        return $clients;
    }

    /**
     * Look up a single client by its id, scoped to the requesting user.
     * Returns null if the client doesn't exist OR is owned by someone
     * else — never differentiates so the caller can't probe for the
     * existence of foreign client_ids.
     *
     * The returned array never contains client_secret (see
     * HashedTokenOAuth2StoragePdo::getClientDetails which strips it).
     *
     * @return array|null
     */
    public function getClientForUser(User $formrUser, $clientId)
    {
        if (!is_string($clientId) || $clientId === '') {
            return null;
        }
        $db = Site::getDb();
        $row = $db->findRow($this->config['client_table'], [
            'client_id' => $clientId,
            'user_id' => $formrUser->email,
        ]);
        if (!$row) {
            return null;
        }
        // Surface the same shape as bshaffer's getClientDetails plus our
        // label; strip client_secret so it can't leak via __toString or
        // serialisation downstream.
        unset($row['client_secret']);
        return $row;
    }

    /**
     * Delete one of the user's OAuth clients, identified by client_id.
     * The (user_id, client_id) pair is verified before the cascade
     * delete: foreign clients return false and write nothing.
     */
    public function deleteClient(User $formrUser, $clientId)
    {
        $client = $this->getClientForUser($formrUser, $clientId);
        if (!$client) {
            return false;
        }
        if (($client['label'] ?? '') === self::INTERNAL_LABEL) {
            // The internal credential is auto-managed; user-driven delete
            // would just see it re-created on the next OpenCPU call. Hide
            // it from the API.
            return false;
        }
        return $this->deleteClientRow($client['client_id']);
    }

    /**
     * Rotate one client's secret. Returns plaintext client_secret
     * wrapped in a HiddenString — the only moment it's knowable.
     *
     * @param string[]|null $scopes If null, preserve. If an array
     *     (including []), replace.
     * @param int[]|null $runIds If null, preserve oauth_client_runs.
     *     If an array (including []), replace (empty = unrestricted).
     * @return array{client_id: string, client_secret: HiddenString}|false
     */
    public function rotateClient(User $formrUser, $clientId, ?array $scopes = null, ?array $runIds = null)
    {
        if (!$formrUser->canAccessApi()) {
            return false;
        }

        $client = $this->getClientForUser($formrUser, $clientId);
        if (!$client) {
            return false;
        }
        if (($client['label'] ?? '') === self::INTERNAL_LABEL) {
            return false;
        }

        if ($scopes === null) {
            $scopes = $this->parseScopeString($client['scope'] ?? '');
        } else {
            $scopes = $this->validateScopes($scopes);
            if ($scopes === false) {
                return false;
            }
        }

        if ($runIds !== null) {
            $runIds = $this->validateRunIds($formrUser, $runIds);
            if ($runIds === false) {
                return false;
            }
        }

        $details = $this->generateClientDetails($formrUser);
        $client_id = $client['client_id'];
        $client_secret = $details['client_secret'];
        $ok = $this->storage->setClientDetails(
            $client_id,
            $client_secret->getString(),
            self::DEFAULT_REDIRECT_URL,
            null,
            implode(' ', $scopes),
            $formrUser->email
        );
        if (!$ok) {
            return false;
        }
        if ($runIds !== null) {
            $this->replaceClientRuns($client_id, $runIds);
        }
        return compact('client_id', 'client_secret');
    }

    /**
     * Drop every OAuth client the user owns plus any cascade rows. Used
     * by the superadmin /user_management revoke flow as an emergency
     * kill-switch. Returns the number of clients removed (0 = the user
     * had none).
     */
    public function deleteAllClientsForUser(User $formrUser)
    {
        $clients = $this->listClientsForUser($formrUser);
        foreach ($clients as $c) {
            $this->deleteClientRow($c['client_id']);
        }
        // Also remove the internal credential — caller is revoking
        // everything, so the OpenCPU bridge for this user is going down
        // too. It'll be re-minted on next access if the user still has
        // API access.
        $internal = $this->getInternalClient($formrUser, false);
        if ($internal) {
            $this->deleteClientRow($internal['client_id']);
        }
        return count($clients);
    }

    /**
     * Read scopes + run-allowlist for a specific client by id, scoped
     * to the requesting user. Returns null if not found / not owned.
     * Used by the apiCredentialsAction prefill flow.
     *
     * @return array{scopes: string[], run_ids: int[]}|null
     */
    public function getClientScopesAndRuns(User $formrUser, $clientId)
    {
        $client = $this->getClientForUser($formrUser, $clientId);
        if (!$client) {
            return null;
        }
        return [
            'scopes' => $this->parseScopeString($client['scope'] ?? ''),
            'run_ids' => $this->getRunAllowlist($client['client_id']),
        ];
    }

    /**
     * Insert path shared by createClient (user-driven) and
     * getInternalClient (auto-managed). Skips access-control + label
     * validation; trusts callers to have done those.
     *
     * @return array{client_id: string, client_secret: HiddenString}|false
     */
    protected function createClientInternal(User $formrUser, $label, array $scopes, array $runIds)
    {
        $scopes = $this->validateScopes($scopes);
        if ($scopes === false) {
            return false;
        }
        $runIds = $this->validateRunIds($formrUser, $runIds);
        if ($runIds === false) {
            return false;
        }

        $details = $this->generateClientDetails($formrUser);
        $db = Site::getDb();
        // Pre-insert a stub row carrying the label, so that
        // setClientDetails takes the UPDATE branch (which doesn't touch
        // label) instead of the INSERT branch (which would write a NULL
        // label and trip the NOT NULL constraint added in patch 054).
        try {
            $db->insert($this->config['client_table'], [
                'client_id' => $details['client_id'],
                'client_secret' => '',
                'user_id' => $formrUser->email,
                'label' => $label,
            ]);
        } catch (\Exception $e) {
            // Most likely cause: UNIQUE(user_id, label) collision. Fail
            // closed so the caller can surface the duplicate-label
            // error from validateLabel's pre-flight (which checks the
            // listing, not the index) before retrying.
            return false;
        }

        $ok = $this->storage->setClientDetails(
            $details['client_id'],
            $details['client_secret']->getString(),
            self::DEFAULT_REDIRECT_URL,
            null,
            implode(' ', $scopes),
            $formrUser->email
        );
        if (!$ok) {
            // Roll back the stub row so a failed setClientDetails
            // doesn't leave an unusable record with empty secret.
            $db->delete($this->config['client_table'], ['client_id' => $details['client_id']]);
            return false;
        }
        $this->replaceClientRuns($details['client_id'], $runIds);
        return $details;
    }

    /**
     * Cascade-delete a client row plus its tokens / codes / JWTs.
     * Private helper — owner verification must happen at the call site.
     */
    protected function deleteClientRow($clientId)
    {
        $db = Site::getDb();
        $db->delete($this->config['client_table'], array('client_id' => $clientId));
        $db->delete($this->config['access_token_table'], array('client_id' => $clientId));
        $db->delete($this->config['refresh_token_table'], array('client_id' => $clientId));
        $db->delete($this->config['code_table'], array('client_id' => $clientId));
        $db->delete($this->config['jwt_table'], array('client_id' => $clientId));
        return true;
    }

    /**
     * Validate a user-supplied label. Returns the trimmed label on
     * success, false on failure. `$allowInternal=true` is used by the
     * auto-managed internal credential path only.
     *
     * @return string|false
     */
    protected function validateLabel($label, $allowInternal = false)
    {
        if (!is_string($label)) {
            return false;
        }
        $label = trim($label);
        if ($label === '' || strlen($label) > self::LABEL_MAX_LENGTH) {
            return false;
        }
        if (!$allowInternal && $label === self::INTERNAL_LABEL) {
            return false;
        }
        return $label;
    }

    /**
     * Get-or-create the internal credential for a user. Used by
     * createAccessTokenForUser to keep OpenCPU-bridge tokens working
     * after the schema flip to per-label credentials. Hidden from
     * listClientsForUser; users cannot rotate or delete it.
     *
     * @param bool $createIfMissing If false, returns null when there
     *     is no internal credential yet (used by deleteAllClientsForUser
     *     to skip allocating one just to immediately drop it).
     * @return array|null
     */
    protected function getInternalClient(User $formrUser, $createIfMissing = true)
    {
        $db = Site::getDb();
        $row = $db->findRow($this->config['client_table'], [
            'user_id' => $formrUser->email,
            'label' => self::INTERNAL_LABEL,
        ]);
        if ($row) {
            unset($row['client_secret']);
            return $row;
        }
        if (!$createIfMissing) {
            return null;
        }
        $details = $this->createClientInternal($formrUser, self::INTERNAL_LABEL, [], []);
        if (!$details) {
            return null;
        }
        // Re-read so the caller sees the row shape (with scope etc.)
        return $db->findRow($this->config['client_table'], [
            'client_id' => $details['client_id'],
        ]);
    }

    /**
     * Get formr user object from email
     *
     * @param string $user_email
     * @return User|boolean If no corresponding user is found, FALSE is returned
     */
    public function getUserByEmail($user_email) {
        if (!$user_email) {
            return false;
        }
        $db = Site::getDb();
        $user_id = $db->findValue('survey_users', array('email' => $user_email), 'id');
        return $user_id ? new User($user_id, null) : false;
    }

    /**
     * Generate a fresh client_id + client_secret pair from cryptographically
     * secure random bytes. Must NOT be derived from the user (id, email,
     * etc.) since those are externally guessable; the client_secret is the
     * sole authenticator on the OAuth client_credentials grant.
     *
     * The client_secret is returned as a HiddenString so it cannot leak
     * through var_dump / __toString / error logs. Callers that need the raw
     * value (to store a hash or show it once) must call ->getString().
     *
     * @param User $formrUser unused; retained for interface compatibility
     * @return array{client_id: string, client_secret: HiddenString}
     */
    protected function generateClientDetails(User $formrUser)
    {
        $client_id = bin2hex(random_bytes(16));
        $client_secret = new HiddenString(bin2hex(random_bytes(32)));
        return compact('client_id', 'client_secret');
    }

    /**
     * Return the run-id allowlist for a given oauth client. Empty array
     * = no restriction. Caller is responsible for distinguishing the two
     * semantically; ApiBase::allowedRunIds caches this per-request.
     *
     * @return int[]
     */
    public function getRunAllowlist($clientId)
    {
        if (empty($clientId)) {
            return [];
        }
        $db = Site::getDb();
        $stmt = $db->prepare("SELECT run_id FROM oauth_client_runs WHERE client_id = :cid");
        $stmt->execute([':cid' => $clientId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
    }

    /**
     * Validate that every scope is a known row in oauth_scopes. Returns
     * a de-duplicated, ordered list on success or false on first
     * unknown value — fail-closed so a typo can't silently grant an
     * empty token.
     *
     * @param string[] $scopes
     * @return string[]|false
     */
    protected function validateScopes(array $scopes)
    {
        $scopes = array_values(array_unique(array_filter(array_map('strval', $scopes), 'strlen')));
        if (empty($scopes)) {
            return [];
        }
        $db = Site::getDb();
        $placeholders = implode(',', array_fill(0, count($scopes), '?'));
        $stmt = $db->prepare("SELECT scope FROM {$this->config['scope_table']} WHERE scope IN ($placeholders)");
        $stmt->execute($scopes);
        $known = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        if (count($known) !== count($scopes)) {
            return false;
        }
        return $scopes;
    }

    /**
     * Validate that every run id is owned by $formrUser. Foreign runs
     * fail the whole call (return false) — never silently dropped —
     * since the form is supposed to only surface the user's own runs.
     *
     * @param int[] $runIds
     * @return int[]|false
     */
    protected function validateRunIds(User $formrUser, array $runIds)
    {
        $runIds = array_values(array_unique(array_filter(array_map('intval', $runIds))));
        if (empty($runIds)) {
            return [];
        }
        $db = Site::getDb();
        $placeholders = implode(',', array_fill(0, count($runIds), '?'));
        $stmt = $db->prepare(
            "SELECT id FROM survey_runs WHERE user_id = ? AND id IN ($placeholders)"
        );
        $stmt->execute(array_merge([$formrUser->id], $runIds));
        $owned = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
        if (count($owned) !== count($runIds)) {
            return false;
        }
        return $runIds;
    }

    /**
     * Replace the run allowlist for a client atomically. Empty $runIds
     * means "no restriction" — the rows are simply deleted.
     *
     * @param string $clientId
     * @param int[] $runIds
     */
    protected function replaceClientRuns($clientId, array $runIds)
    {
        $db = Site::getDb();
        $db->delete('oauth_client_runs', ['client_id' => $clientId]);
        foreach ($runIds as $runId) {
            $db->insert('oauth_client_runs', [
                'client_id' => $clientId,
                'run_id' => $runId,
            ]);
        }
    }

    /**
     * Parse a space-delimited scope string into a list. Returns [] for
     * null/empty input (the default-deny state for new clients).
     *
     * @param string|null $scopeString
     * @return string[]
     */
    /**
     * Convert a Run object, an int, or an iterable of ints into a
     * de-duplicated list of positive integers. Anything else returns
     * []. Used by createAccessTokenForUser to normalise the $forRun
     * parameter into a list of run ids that's safe to write into the
     * token row's run_ids column.
     *
     * @param mixed $forRun
     * @return int[]
     */
    protected function normaliseRunIds($forRun)
    {
        if ($forRun instanceof Run) {
            $id = (int) $forRun->id;
            return $id > 0 ? [$id] : [];
        }
        if (is_int($forRun) || (is_string($forRun) && ctype_digit($forRun))) {
            $id = (int) $forRun;
            return $id > 0 ? [$id] : [];
        }
        if (is_array($forRun) || $forRun instanceof Traversable) {
            $out = [];
            foreach ($forRun as $v) {
                if ($v instanceof Run) {
                    $id = (int) $v->id;
                } else {
                    $id = (int) $v;
                }
                if ($id > 0) {
                    $out[] = $id;
                }
            }
            return array_values(array_unique($out));
        }
        return [];
    }

    protected function parseScopeString($scopeString)
    {
        if (!is_string($scopeString) || $scopeString === '') {
            return [];
        }
        return array_values(array_filter(preg_split('/\s+/', trim($scopeString)), 'strlen'));
    }

    /**
     * Create an access token for internal API access for a given user.
     * This bypasses the standard grant flows and directly issues a token.
     *
     * Default lifetime mirrors the external client_credentials grant
     * (1 hour), so a future caller minting a token for a generic
     * "act-as this user" workflow gets a sensible default. Short-lived
     * use cases — e.g. opencpu_prepare_api_access, which embeds the
     * token into an R variable for the duration of one OpenCPU call
     * and explicitly deletes it on return — should pass an explicit
     * lifetime (~120s is the established floor) so the lifetime is a
     * safety net rather than the contract.
     *
     * @param User $formrUser The user for whom to create the token.
     * @param string|null $scope The scope for the token.
     * @param bool $includeRefreshToken Whether to include a refresh token. Defaults to false.
     * @param int $tokenLifetime Token lifetime in seconds. Defaults to 3600 (1 hour).
     * @param Run|int[]|null $forRun Per-token run allowlist. Accepts a
     *     Run object or an explicit list of run ids. When set, the
     *     token row's `run_ids` column is populated so ApiBase narrows
     *     access to exactly these runs — independent of any per-client
     *     `oauth_client_runs` allowlist. Callers that mint a token for
     *     a specific operation (OpenCPU rendering a survey, expiry
     *     cron checking a single run) should pass this; the
     *     client_credentials grant flow leaves it null (the
     *     per-credential `oauth_client_runs` allowlist takes over).
     * @return array|false The access token data or false on failure.
     */
    public function createAccessTokenForUser(User $formrUser, $scope = null, $includeRefreshToken = false, $tokenLifetime = 3600, $forRun = null)
    {
        if (!$formrUser->canAccessApi()) {
            return false;
        }

        // Internal flow uses a dedicated, auto-managed credential so the
        // user's UI-visible credentials retain their declared scope /
        // run-allowlist. The internal client carries empty scope +
        // empty allowlist; the issued token stamps its own scope and
        // run_ids inline, which is what ApiBase actually enforces.
        $client = $this->getInternalClient($formrUser, true);
        if (!$client) {
            return false;
        }

        // Configure token lifetime and create response type handler
        $config = ['access_lifetime' => $tokenLifetime];
        $accessTokenResponseType = new \OAuth2\ResponseType\AccessToken($this->storage, $this->storage, $config);

        try {
            $token = $accessTokenResponseType->createAccessToken(
                $client['client_id'],
                $formrUser->email,
                $scope,
                $includeRefreshToken
            );
        } catch (\Exception $e) {
            return false;
        }

        // Verify token creation succeeded and has required fields
        if (!$token || !is_array($token) || empty($token['access_token'])) {
            return false;
        }

        // Stamp per-token run_ids if the caller asked for it. Done as a
        // post-insert UPDATE because bshaffer's setAccessToken signature
        // is fixed by interface — adding a parameter would break the
        // grant flow's call site. Two SQL hits for internal tokens,
        // zero overhead for external ones (which pass $forRun = null).
        if ($forRun !== null) {
            $runIds = $this->normaliseRunIds($forRun);
            if (!empty($runIds)) {
                $db = Site::getDb();
                $stmt = $db->prepare(sprintf(
                    'UPDATE %s SET run_ids = :run_ids WHERE access_token = :access_token',
                    $this->config['access_token_table']
                ));
                $stmt->execute([
                    ':run_ids' => implode(',', $runIds),
                    ':access_token' => hash('sha256', $token['access_token']),
                ]);
            }
        }

        return $token;
    }

    /**
     * Hard deletes a single access token from the DB.
     * Always returns true to prevent token enumeration.
     *
     * @param string $access_token The token to invalidate.
     * @return bool Always returns true.
     */
    public function deleteAccessToken($access_token)
    {
        if (!$access_token) {
            return true;
        }

        // Tokens are stored as SHA-256 hashes (see HashedTokenOAuth2StoragePdo),
        // so we must hash the incoming raw token before issuing the delete.
        $db = Site::getDb();
        $db->delete($this->config['access_token_table'], array(
            'access_token' => hash('sha256', $access_token),
        ));

        return true;
    }
}
