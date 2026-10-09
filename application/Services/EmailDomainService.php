<?php

/**
 * Recipient-domain plausibility for the mail guard: a syntactically valid
 * address whose domain has neither an MX nor an A/AAAA record cannot receive
 * mail, so sending to it only burns reputation. Verdicts are cached in
 * survey_mail_domain_cache (default 24h) so DNS is consulted at most once per
 * domain per window.
 *
 * Fail-open by design, but honestly: dns_get_record() distinguishes an
 * authoritative "no such name / no such record" (empty array) from a
 * transient resolver failure — SERVFAIL, timeout — (false + E_WARNING),
 * which checkdnsrr() collapses into one "no". Only the authoritative answer
 * fails closed and is cached; a transient one lets the mail through, is
 * logged, and is never cached or memoised, so the next attempt asks again.
 * A control lookup still guards the one case dns_get_record() can't see: a
 * resolver that answers NXDOMAIN for everything (captive/misconfigured
 * search path), where an all-negative answer set is not evidence.
 *
 * Optionally, mail_guard.disposable_domains_file names a text file (one
 * domain per line, # comments) of domains to refuse outright. Empty/absent
 * by default.
 */
class EmailDomainService {

    /** Memo lifetime; the mail daemons are long-lived processes, so the per-process memo must expire. */
    const MEMO_TTL = 600;

    /** @var array<string,array{verdict:bool,expires:int}> per-process memo of authoritative verdicts only */
    protected static $memo = array();

    /** @var bool any lookup succeeded this process — the resolver works */
    protected static $sawSuccessfulLookup = false;

    public static function domainOf($address) {
        $address = strtolower(trim((string) $address));
        $at = strrpos($address, '@');
        if ($at === false) {
            return null;
        }
        return rtrim(substr($address, $at + 1), '.');
    }

    public static function isDeliverableAddress($address) {
        $domain = self::domainOf($address);
        if ($domain === null || $domain === '') {
            return false;
        }
        return self::isDeliverableDomain($domain);
    }

    public static function isDeliverableDomain($domain) {
        $domain = strtolower(trim($domain, ". \t"));
        if ($domain === '') {
            return false;
        }
        if (isset(self::$memo[$domain]) && self::$memo[$domain]['expires'] > time()) {
            return self::$memo[$domain]['verdict'];
        }
        unset(self::$memo[$domain]);
        $verdict = static::resolveVerdict($domain);
        if ($verdict === null) {
            return true; // transient: fail open now, ask again next time
        }
        self::$memo[$domain] = array('verdict' => $verdict, 'expires' => time() + self::MEMO_TTL);
        return $verdict;
    }

    /** Drop the per-process memo (tests; also lets a long-lived daemon be told to forget). */
    public static function resetMemo() {
        self::$memo = array();
        self::$sawSuccessfulLookup = false;
    }

    /** @return bool|null true/false = authoritative, null = could not tell (transient) */
    protected static function resolveVerdict($domain) {
        if (static::isBlocklisted($domain)) {
            return false;
        }

        $cached = static::cachedVerdict($domain);
        if ($cached !== null) {
            return $cached;
        }

        $found = static::lookupMailRecords($domain);
        if ($found === true) {
            static::cacheVerdict($domain, true);
            return true;
        }
        if ($found === null) {
            formr_log("MAIL GUARD: transient DNS failure while checking '{$domain}'; failing open (not cached).", 'MailGuard');
            return null;
        }

        // Authoritative "nothing there". Still only fail closed if the resolver
        // demonstrably resolves *something*: an instance can always resolve
        // its own admin domain, so if that control lookup fails too, the
        // negative answers are not evidence (and we do NOT cache them).
        if (!static::resolverAlive()) {
            formr_log("MAIL GUARD: DNS resolver unavailable while checking '{$domain}'; failing open (not cached).", 'MailGuard');
            return null;
        }

        static::cacheVerdict($domain, false);
        return false;
    }

    /**
     * MX first, then A/AAAA fallback per RFC 5321 implicit MX.
     *
     * @return bool|null true = records exist, false = authoritative negative
     *   (NXDOMAIN / NODATA on every type, or a null MX per RFC 7505),
     *   null = at least one lookup failed transiently and none succeeded.
     */
    protected static function lookupMailRecords($domain) {
        $fqdn = $domain . '.'; // absolute — keep resolv.conf search domains out of it
        $transient = false;
        foreach (array(DNS_MX, DNS_A, DNS_AAAA) as $type) {
            $records = @dns_get_record($fqdn, $type);
            if ($records === false) {
                $transient = true; // SERVFAIL / timeout: no verdict from this type
                continue;
            }
            if (count($records) === 0) {
                continue; // NXDOMAIN or NODATA: authoritative "not this type"
            }
            self::$sawSuccessfulLookup = true;
            if ($type === DNS_MX && static::isNullMx($records)) {
                return false; // the domain declares it never accepts mail
            }
            return true;
        }
        return $transient ? null : false;
    }

    /** RFC 7505: a single MX whose exchange is the root ("." / "") means "no mail". */
    protected static function isNullMx(array $records) {
        foreach ($records as $record) {
            $target = isset($record['target']) ? trim((string) $record['target'], '.') : '';
            if ($target !== '') {
                return false;
            }
        }
        return true;
    }

    protected static function resolverAlive() {
        if (self::$sawSuccessfulLookup) {
            return true; // this very process already resolved something
        }
        // Control lookup: the instance's own host where available (CLI
        // contexts may have no WEBROOT), else a domain that always exists.
        $control = parse_url(site_url(), PHP_URL_HOST) ?: 'gmail.com';
        $records = @dns_get_record($control . '.', DNS_A);
        if (is_array($records) && count($records) > 0) {
            self::$sawSuccessfulLookup = true;
            return true;
        }
        return false;
    }

    protected static function cachedVerdict($domain) {
        $ttl = (float) Config::get('mail_guard.mx_cache_hours', 24);
        $row = DB::getInstance()->findRow('survey_mail_domain_cache', array('domain' => $domain), array('deliverable', 'checked'));
        if (!$row) {
            return null;
        }
        if (strtotime($row['checked']) < time() - (int) ($ttl * 3600)) {
            return null; // stale — re-resolve
        }
        return (bool) $row['deliverable'];
    }

    protected static function cacheVerdict($domain, $deliverable) {
        try {
            DB::getInstance()->insert_update('survey_mail_domain_cache', array(
                'domain' => $domain,
                'deliverable' => (int) $deliverable,
                'checked' => mysql_now(),
            ), array('deliverable', 'checked'));
        } catch (Throwable $e) {
            formr_log('MAIL GUARD domain cache write failed: ' . $e->getMessage(), 'MailGuard');
        }
    }

    /** Delete cache rows older than the configured window (called from the daily cleanup cron). */
    public static function pruneCache() {
        $ttl = (float) Config::get('mail_guard.mx_cache_hours', 24);
        $cutoff = date('Y-m-d H:i:s', time() - (int) ($ttl * 3600));
        return (int) DB::getInstance()->exec('DELETE FROM survey_mail_domain_cache WHERE checked < :cutoff', array('cutoff' => $cutoff));
    }

    protected static function isBlocklisted($domain) {
        $file = Config::get('mail_guard.disposable_domains_file');
        if (!$file || !is_readable($file)) {
            return false;
        }
        static $list = null;
        if ($list === null) {
            $list = array();
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = strtolower(trim($line));
                if ($line !== '' && $line[0] !== '#') {
                    $list[$line] = true;
                }
            }
        }
        return isset($list[$domain]);
    }

}
