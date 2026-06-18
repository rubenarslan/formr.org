<?php

class UserResource extends BaseResource
{

    public function handle()
    {
        $method = $this->getRequestMethod();
        $subPath = $this->getUriSegment(1);

        if ($subPath === 'me') {
            if ($method === 'GET') {
                $this->checkScope('user:read');
                return $this->getUserProfile();
            } elseif ($method === 'PATCH') {
                $this->checkScope('user:write');
                return $this->updateUserProfile();
            } else {
                return $this->error(405, 'Method not allowed');
            }
        }

        return $this->error(404, 'User endpoint not found');
    }

    private function getUserProfile()
    {
        $userData = [
            'id' => (int)$this->user->id,
            'email' => $this->user->email,
            'user_code' => $this->user->user_code,
            'first_name' => $this->user->first_name,
            'last_name' => $this->user->last_name,
            'affiliation' => $this->user->affiliation,
            'email_verified' => (bool)$this->user->email_verified,
            'created' => $this->user->created,
            // Capability surface for API clients (e.g. the MCP server) to
            // explain to the user what this token can/can't do up front,
            // instead of discovering limits only via 403s. Read-only
            // exposure of data the request already holds — no extra query
            // for admin/scopes; one cheap id->name lookup for the runs.
            'admin' => (int)$this->user->admin,
            'scopes' => $this->grantedScopes(),
            'allowed_runs' => $this->allowedRunsDetail(),
        ];

        return $this->response(200, 'User profile retrieved', $userData);
    }

    /**
     * The scopes granted to the calling token, as an array. Same source
     * ApiBase::checkScope() validates against (the space-delimited
     * `scope` on the token row). Empty array = no scopes granted.
     *
     * @return string[]
     */
    private function grantedScopes()
    {
        $raw = isset($this->tokenData['scope']) ? trim((string)$this->tokenData['scope']) : '';
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(preg_split('/\s+/', $raw)));
    }

    /**
     * The run allowlist for the calling client, resolved to {id, name}.
     * Returns null when the client is UNRESTRICTED (every run the user
     * owns is accessible) — see ApiBase::allowedRunIds(), where an empty
     * array carries that meaning. A non-empty list means the token can
     * only touch exactly those runs (e.g. a "single run" credential).
     *
     * @return array|null
     */
    private function allowedRunsDetail()
    {
        $allowed = $this->allowedRunIds();
        if (empty($allowed)) {
            return null;
        }
        $runs = $this->db->select('id, name')
            ->from('survey_runs')
            ->where(['user_id' => $this->user->id])
            ->whereIn('id', $allowed)
            ->fetchAll();
        return array_map(function ($r) {
            return ['id' => (int)$r['id'], 'name' => $r['name']];
        }, $runs);
    }

    private function updateUserProfile()
    {
        $body = $this->getJsonBody();

        $allowedFields = ['first_name', 'last_name', 'affiliation'];
        $updates = [];

        foreach ($allowedFields as $field) {
            if (isset($body[$field])) {
                $updates[$field] = trim($body[$field]);
            }
        }

        if (empty($updates)) {
            return $this->error(400, 'No valid fields provided for update. Allowed: ' . implode(', ', $allowedFields));
        }

        try {
            $this->db->update('survey_users', $updates, ['id' => $this->user->id]);

            foreach ($updates as $key => $val) {
                $this->user->$key = $val;
            }

            return $this->response(200, 'User profile updated', $updates);
        } catch (Exception $e) {
            return $this->error(500, 'Failed to update profile: ' . $e->getMessage());
        }
    }
}
