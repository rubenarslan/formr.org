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
            'created' => $this->user->created
        ];

        return $this->response(200, 'User profile retrieved', $userData);
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
