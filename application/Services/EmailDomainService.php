<?php

/**
 * Recipient-domain plausibility for the mail guard: a syntactically valid
 * address whose domain has neither an MX nor an A/AAAA record cannot receive
 * mail, so sending to it only burns reputation. Verdicts are cached in
 * survey_mail_domain_cache (default 24h) so DNS is consulted at most once per
 * domain per window.
 *
 * Fail-open by design: PHP's resolver functions expose no timeout knob, and a
 * flaky resolver must not block real mail. We only fail CLOSED on an answer
 * we can call authoritative-ish: the target domain resolves to nothing while
 * a control lookup (this instance's own domain) succeeds in the same process,
 * proving the resolver itself is alive. Resolver down -> send anyway, log it.
 *
 * Optionally, mail_guard.disposable_domains_file names a text file (one
 * domain per line, # comments) of domains to refuse outright. Empty/absent
 * by default.
 */
class EmailDomainService {

    /** @var array<string,bool> per-request memo */
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
        if (isset(self::$memo[$domain])) {
            return self::$memo[$domain];
        }
        $verdict = static::resolveVerdict($domain);
        self::$memo[$domain] = $verdict;
        return $verdict;
    }

    protected static function resolveVerdict($domain) {
        if (static::isBlocklisted($domain)) {
            return false;
        }

        $cached = static::cachedVerdict($domain);
        if ($cached !== null) {
            return $cached;
        }

        if (static::hasMailRecords($domain)) {
            static::cacheVerdict($domain, true);
            return true;
        }

        // Nothing found. Only fail closed if the resolver demonstrably works:
        // an instance can always resolve its own admin domain, so if that
        // control lookup fails too, DNS is down and we fail open (and do NOT
        // cache the non-answer).
        if (!static::resolverAlive()) {
            formr_log("MAIL GUARD: DNS resolver unavailable while checking '{$domain}'; failing open.", 'MailGuard');
            return true;
        }

        static::cacheVerdict($domain, false);
        return false;
    }

    /** MX first, then A/AAAA fallback per RFC 5321 implicit MX. */
    protected static function hasMailRecords($domain) {
        $fqdn = $domain . '.'; // absolute — keep resolv.conf search domains out of it
        $found = @checkdnsrr($fqdn, 'MX') || @checkdnsrr($fqdn, 'A') || @checkdnsrr($fqdn, 'AAAA');
        if ($found) {
            self::$sawSuccessfulLookup = true;
        }
        return $found;
    }

    protected static function resolverAlive() {
        if (self::$sawSuccessfulLookup) {
            return true; // this very process already resolved something
        }
        // Control lookup: the instance's own host where available (CLI
        // contexts may have no WEBROOT), else a domain that always exists.
        $control = parse_url(site_url(), PHP_URL_HOST) ?: 'gmail.com';
        if (@checkdnsrr($control . '.', 'A')) {
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
