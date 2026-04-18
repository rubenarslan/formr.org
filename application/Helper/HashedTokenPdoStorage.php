<?php

/**
 * PDO storage that stores SHA-256 hashes of access tokens, refresh tokens,
 * and authorization codes instead of the raw values. The bshaffer OAuth2
 * library only ever looks tokens up by exact match against the value the
 * client presents, so hashing the lookup key on both write and read is
 * transparent to the rest of the library.
 *
 * The raw token is still returned to the caller that issued it (because
 * setAccessToken / setRefreshToken receive it before storage), so clients
 * see no behavioral change. A DB compromise alone no longer hands an
 * attacker a wallet of replayable bearer tokens.
 */
class HashedTokenPdoStorage extends \OAuth2\Storage\Pdo
{

    private function hashToken($token)
    {
        if ($token === null || $token === '') {
            return $token;
        }
        return hash('sha256', $token);
    }

    /* AccessTokenInterface */

    public function getAccessToken($access_token)
    {
        $hashed = $this->hashToken($access_token);
        $stmt = $this->db->prepare(sprintf(
            'SELECT * FROM %s WHERE access_token = :access_token',
            $this->config['access_token_table']
        ));
        $stmt->execute(['access_token' => $hashed]);

        if ($token = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $token['expires'] = strtotime($token['expires']);
            // Restore the raw token on the returned record so any downstream
            // code that re-uses the field still sees the original value.
            $token['access_token'] = $access_token;
            return $token;
        }
        return false;
    }

    public function setAccessToken($access_token, $client_id, $user_id, $expires, $scope = null)
    {
        $hashed = $this->hashToken($access_token);
        $expires = date('Y-m-d H:i:s', $expires);

        // Look up by hash directly (do NOT call $this->getAccessToken which
        // would expect the raw token).
        $check = $this->db->prepare(sprintf(
            'SELECT 1 FROM %s WHERE access_token = :access_token',
            $this->config['access_token_table']
        ));
        $check->execute(['access_token' => $hashed]);
        $exists = (bool) $check->fetchColumn();

        if ($exists) {
            $stmt = $this->db->prepare(sprintf(
                'UPDATE %s SET client_id=:client_id, expires=:expires, user_id=:user_id, scope=:scope WHERE access_token=:access_token',
                $this->config['access_token_table']
            ));
        } else {
            $stmt = $this->db->prepare(sprintf(
                'INSERT INTO %s (access_token, client_id, expires, user_id, scope) VALUES (:access_token, :client_id, :expires, :user_id, :scope)',
                $this->config['access_token_table']
            ));
        }

        return $stmt->execute([
            'access_token' => $hashed,
            'client_id' => $client_id,
            'user_id' => $user_id,
            'expires' => $expires,
            'scope' => $scope,
        ]);
    }

    /* RefreshTokenInterface */

    public function getRefreshToken($refresh_token)
    {
        $hashed = $this->hashToken($refresh_token);
        $stmt = $this->db->prepare(sprintf(
            'SELECT * FROM %s WHERE refresh_token = :refresh_token',
            $this->config['refresh_token_table']
        ));
        $stmt->execute(['refresh_token' => $hashed]);

        if ($token = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $token['expires'] = strtotime($token['expires']);
            // RefreshToken grant uses $this->refreshToken['refresh_token']
            // when it later calls unsetRefreshToken — restore the raw value
            // so that re-hashing on delete produces the right key.
            $token['refresh_token'] = $refresh_token;
            return $token;
        }
        return false;
    }

    public function setRefreshToken($refresh_token, $client_id, $user_id, $expires, $scope = null)
    {
        $hashed = $this->hashToken($refresh_token);
        $expires = date('Y-m-d H:i:s', $expires);

        $stmt = $this->db->prepare(sprintf(
            'INSERT INTO %s (refresh_token, client_id, user_id, expires, scope) VALUES (:refresh_token, :client_id, :user_id, :expires, :scope)',
            $this->config['refresh_token_table']
        ));

        return $stmt->execute([
            'refresh_token' => $hashed,
            'client_id' => $client_id,
            'user_id' => $user_id,
            'expires' => $expires,
            'scope' => $scope,
        ]);
    }

    public function unsetRefreshToken($refresh_token)
    {
        $stmt = $this->db->prepare(sprintf(
            'DELETE FROM %s WHERE refresh_token = :refresh_token',
            $this->config['refresh_token_table']
        ));

        return $stmt->execute(['refresh_token' => $this->hashToken($refresh_token)]);
    }

    /* AuthorizationCodeInterface */

    public function getAuthorizationCode($code)
    {
        $hashed = $this->hashToken($code);
        $stmt = $this->db->prepare(sprintf(
            'SELECT * FROM %s WHERE authorization_code = :code',
            $this->config['code_table']
        ));
        $stmt->execute(['code' => $hashed]);

        if ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $row['expires'] = strtotime($row['expires']);
            $row['authorization_code'] = $code;
            return $row;
        }
        return false;
    }

    // Signature mirrors bshaffer's PKCE-capable parent (9 params). The PKCE
    // arguments are accepted for signature compatibility but not persisted —
    // formr does not currently enable the PKCE flow and the local schema has
    // no code_challenge columns.
    public function setAuthorizationCode($code, $client_id, $user_id, $redirect_uri, $expires, $scope = null, $id_token = null, $code_challenge = null, $code_challenge_method = null)
    {
        $hashed = $this->hashToken($code);
        $expires = date('Y-m-d H:i:s', $expires);

        $check = $this->db->prepare(sprintf(
            'SELECT 1 FROM %s WHERE authorization_code = :code',
            $this->config['code_table']
        ));
        $check->execute(['code' => $hashed]);
        $exists = (bool) $check->fetchColumn();

        $hasIdToken = $id_token !== null;

        if ($hasIdToken) {
            $sql = $exists
                ? 'UPDATE %s SET client_id=:client_id, user_id=:user_id, redirect_uri=:redirect_uri, expires=:expires, scope=:scope, id_token=:id_token WHERE authorization_code=:code'
                : 'INSERT INTO %s (authorization_code, client_id, user_id, redirect_uri, expires, scope, id_token) VALUES (:code, :client_id, :user_id, :redirect_uri, :expires, :scope, :id_token)';
            $stmt = $this->db->prepare(sprintf($sql, $this->config['code_table']));
            return $stmt->execute([
                'code' => $hashed,
                'client_id' => $client_id,
                'user_id' => $user_id,
                'redirect_uri' => $redirect_uri,
                'expires' => $expires,
                'scope' => $scope,
                'id_token' => $id_token,
            ]);
        }

        $sql = $exists
            ? 'UPDATE %s SET client_id=:client_id, user_id=:user_id, redirect_uri=:redirect_uri, expires=:expires, scope=:scope WHERE authorization_code=:code'
            : 'INSERT INTO %s (authorization_code, client_id, user_id, redirect_uri, expires, scope) VALUES (:code, :client_id, :user_id, :redirect_uri, :expires, :scope)';
        $stmt = $this->db->prepare(sprintf($sql, $this->config['code_table']));
        return $stmt->execute([
            'code' => $hashed,
            'client_id' => $client_id,
            'user_id' => $user_id,
            'redirect_uri' => $redirect_uri,
            'expires' => $expires,
            'scope' => $scope,
        ]);
    }

    public function expireAuthorizationCode($code)
    {
        $stmt = $this->db->prepare(sprintf(
            'DELETE FROM %s WHERE authorization_code = :code',
            $this->config['code_table']
        ));

        return $stmt->execute(['code' => $this->hashToken($code)]);
    }
}
