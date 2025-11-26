<?php

class RateLimitService
{
    protected DB $db;
    protected array $thresholds;
    protected bool $testing;
    protected bool $isSelfMessage;

    /**
     * @param DB $db Database connection
     * @param bool $testing Whether this is a test run
     * @param bool $isSelfMessage Whether the message is being sent to the sender/admin
     */
    public function __construct(DB $db, bool $testing = false, bool $isSelfMessage = false) 
    {
        $this->db = $db;
        $this->testing = $testing;
        $this->isSelfMessage = $isSelfMessage;
        $this->thresholds = \Config::get("email_thresholds");
    }

    /**
     * Check if sending is allowed based on rate limits
     * 
     * @param string $recipient The recipient's identifier (email/session)
     * @param string $table The log table to check ('survey_email_log' or 'push_logs')
     * @return array ['allowed' => bool, 'message' => string|null]
     */
    public function isAllowedToSend(string $recipient, string $table): array
    {
        $counts = $this->getMessageCounts($recipient, $table);
        
        if ($this->isSelfMessage) {
            if ($counts['in_last_1m'] >= $this->thresholds['in_last_1m_testing'] || 
                $counts['in_last_1d'] >= $this->thresholds['in_last_1d_testing']) {
                return [
                    'allowed' => false,
                    'message' => sprintf(
                        "Too many messages are being sent to the study administrator, %d messages today. Please wait a little.", 
                        $counts['in_last_1d']
                    )
                ];
            }
            return ['allowed' => true, 'message' => null];
        }

        // Check thresholds in order of most restrictive to least
        if ($counts['in_last_1m'] >= $this->thresholds['in_last_1m']) {
            if ($counts['in_last_1m'] <= $this->thresholds['in_last_1m'] && $this->testing) {
                return [
                    'allowed' => true,
                    'message' => sprintf(
                        "We already sent %d messages to this recipient in the last minute. A message was sent because you're testing, but it would have been delayed for a real user.",
                        $counts['in_last_1m']
                    )
                ];
            }
            return [
                'allowed' => false,
                'message' => sprintf(
                    "We already sent %d messages to this recipient in the last minute. No message was sent.",
                    $counts['in_last_1m']
                )
            ];
        }

        if ($counts['in_last_10m'] >= $this->thresholds['in_last_10m']) {
            if ($counts['in_last_10m'] <= $this->thresholds['in_last_10m_testing'] && $this->testing) {
                return [
                    'allowed' => true,
                    'message' => sprintf(
                        "We already sent %d messages to this recipient in the last 10 minutes. A message was sent because you're testing, but it would have been delayed for a real user.",
                        $counts['in_last_10m']
                    )
                ];
            }
            return [
                'allowed' => false,
                'message' => sprintf(
                    "We already sent %d messages to this recipient in the last 10 minutes. No message was sent.",
                    $counts['in_last_10m']
                )
            ];
        }

        if ($counts['in_last_1h'] >= $this->thresholds['in_last_1h']) {
            if ($counts['in_last_1h'] <= $this->thresholds['in_last_1h_testing'] && $this->testing) {
                return [
                    'allowed' => true,
                    'message' => sprintf(
                        "We already sent %d messages to this recipient in the last hour. A message was sent because you're testing, but it would have been delayed for a real user.",
                        $counts['in_last_1h']
                    )
                ];
            }
            return [
                'allowed' => false,
                'message' => sprintf(
                    "We already sent %d messages to this recipient in the last hour. No message was sent.",
                    $counts['in_last_1h']
                )
            ];
        }

        if (!$this->testing) {
            if ($counts['in_last_1d'] >= $this->thresholds['in_last_1d']) {
                return [
                    'allowed' => false,
                    'message' => sprintf(
                        "We already sent %d messages to this recipient in the last day. No message was sent.",
                        $counts['in_last_1d']
                    )
                ];
            }

            if ($counts['in_last_1w'] >= $this->thresholds['in_last_1w']) {
                return [
                    'allowed' => false,
                    'message' => sprintf(
                        "We already sent %d messages to this recipient in the last week. No message was sent.",
                        $counts['in_last_1w']
                    )
                ];
            }
        }

        return ['allowed' => true, 'message' => null];
    }

    /**
     * Get message counts for different time periods
     * 
     * @param string $recipient The recipient's identifier
     * @param string $table The log table to check
     * @return array Message counts for different time periods
     * @throws \InvalidArgumentException If invalid table name provided
     */
    protected function getMessageCounts(string $recipient, string $table): array
    {
        // Whitelist allowed table names
        $allowedTables = ['survey_email_log', 'push_logs'];
        if (!in_array($table, $allowedTables, true)) {
            throw new \InvalidArgumentException('Invalid table name provided');
        }

        $statusField = $table === 'survey_email_log' ? 'status = 1' : "status = 'success'";
        $recipientField = $table === 'survey_email_log' ? 'recipient' : 'unit_session_id';

        $sql = "SELECT
            SUM(created > DATE_SUB(NOW(), INTERVAL 1 MINUTE)) AS in_last_1m,
            SUM(created > DATE_SUB(NOW(), INTERVAL 10 MINUTE)) AS in_last_10m,
            SUM(created > DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS in_last_1h,
            SUM(created > DATE_SUB(NOW(), INTERVAL 1 DAY)) AS in_last_1d,
            SUM(1) AS in_last_1w
            FROM `{$table}`
            WHERE {$recipientField} = :recipient 
            AND {$statusField}
            AND created > DATE_SUB(NOW(), INTERVAL 7 DAY)";

        return $this->db->execute($sql, [':recipient' => $recipient], false, true);
    }
} 