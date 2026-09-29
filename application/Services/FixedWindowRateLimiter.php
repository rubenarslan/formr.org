<?php

/**
 * Generic DB-backed fixed-window rate limiter (patch 113).
 *
 * Replaces the $_SESSION-stored buckets the form_v2 endpoints used: session
 * storage reset with every PHP-session-cookie rotation (re-auth via ?code=,
 * cleared cookies), which made the limit trivially bypassable, and its
 * correctness under concurrent requests relied entirely on the session file
 * lock. This limiter keys buckets server-side and counts atomically.
 *
 * Fail-open by design: if the table is missing (patch not applied yet) or the
 * write fails, the request is allowed and the problem logged — a broken
 * limiter must never lock participants out of a running study.
 */
class FixedWindowRateLimiter {

    /**
     * Count one hit against $bucketKey and report whether the caller is
     * within budget. Fixed window: the first hit of a window stamps
     * window_start; hits within $windowSeconds increment; the first hit
     * after the window restarts it.
     *
     * @param string $bucketKey e.g. "rcall:1234" (scope + run_session id) or "beacon:1.2.3.4"
     * @param int $maxPerWindow inclusive budget per window
     * @param int $windowSeconds window length
     * @return bool true = allowed, false = over budget
     */
    public static function allow($bucketKey, $maxPerWindow, $windowSeconds = 60) {
        $bucketKey = substr((string) $bucketKey, 0, 80);
        $now = time();
        try {
            $db = DB::getInstance();
            // Single atomic statement. Assignment order is load-bearing:
            // `count` is computed against the OLD window_start (MySQL
            // evaluates ODKU assignments left to right), THEN window_start
            // advances if the window rolled over.
            $stmt = $db->prepare(
                'INSERT INTO `formr_rate_limits` (bucket_key, window_start, count)
                 VALUES (:k, :now, 1)
                 ON DUPLICATE KEY UPDATE
                     count = IF(:now2 - window_start >= :win, 1, count + 1),
                     window_start = IF(:now3 - window_start >= :win2, :now4, window_start)'
            );
            $stmt->bindValue(':k', $bucketKey);
            $stmt->bindValue(':now', $now, PDO::PARAM_INT);
            $stmt->bindValue(':now2', $now, PDO::PARAM_INT);
            $stmt->bindValue(':now3', $now, PDO::PARAM_INT);
            $stmt->bindValue(':now4', $now, PDO::PARAM_INT);
            $stmt->bindValue(':win', $windowSeconds, PDO::PARAM_INT);
            $stmt->bindValue(':win2', $windowSeconds, PDO::PARAM_INT);
            $stmt->execute();

            $count = (int) $db->findValue('formr_rate_limits', array('bucket_key' => $bucketKey), 'count');

            // Opportunistic cleanup, ~1% of hits: buckets idle for a day are
            // dead weight. LIMIT keeps it from ever stalling a request.
            if (mt_rand(1, 100) === 1) {
                $db->exec(
                    'DELETE FROM `formr_rate_limits` WHERE `window_start` < :cutoff LIMIT 200',
                    array('cutoff' => $now - 86400)
                );
            }

            return $count <= $maxPerWindow;
        } catch (Exception $e) {
            formr_log('FixedWindowRateLimiter fail-open: ' . $e->getMessage());
            return true;
        }
    }
}
