<?php

/**
 * Formr.org configuration
 */
// Use sub domains for studies
$settings['use_study_subdomains'] = true;
$settings['admin_domain'] = "www.example.com";
# ideally not the same domain as the admin domain, for study subdomains use *.example.com
$settings['study_domain'] = "*.example.com";
$settings['api_domain'] = "";
$settings['protocol'] = "https://";

// Codes listed here can be entered in the sign-up box to turn
// users into admins automatically (upon email confirmation)
$settings['referrer_codes'] = array();

// Timezone
$settings['timezone'] = 'Europe/Berlin';

// Database Settings
$settings['database'] = array(
	'datasource' => 'Database/Mysql',
	'persistent' => false,
	'host' => 'localhost',
	'login' => 'user',
	'password' => 'password',
	'database' => 'database',
	'prefix' => '',
	'encoding' => 'utf8mb4',
	'unix_socket' => '',
);

// OpenCPU instance settings
$settings['opencpu_instance'] = array(
	'local_url' => 'http://opencpu:8004',
	'public_url' => 'https://public.opencpu.org',
	'r_lib_path' => '/usr/local/lib/R/site-library'
);
// (used in admin/test_opencpu)
$settings['alternative_opencpu_instance'] = array(
	'local_url' => 'http://opencpu:8004',
	'public_url' => 'https://public.opencpu.org',
	'r_lib_path' => '/usr/local/lib/R/site-library'
);

// Internal URL for R→formr API calls from OpenCPU (Docker bridge network).
// When set, `.formr$host` uses this instead of the public `api_base_url()`,
// avoiding DNS, TLS, and reverse-proxy overhead on every `formr_api_*()` call.
// Example: 'http://formr_app/api' (same Docker network as opencpu).
// Leave empty to fall back to `api_base_url()` (current behaviour).
// Requirements: the hostname must reach an Apache vhost that serves the API
// (admin/API vhost, NOT the study vhost — an unmatched Host header falls
// through to the *first* vhost, so make the routing explicit with a
// ServerAlias for the internal hostname rather than relying on vhost order).
// The OAuth bearer token travels as plaintext HTTP on this URL: only set it
// on a trusted single-host Docker network. On multi-host/overlay networks,
// encrypt the network or leave this empty.
$settings['api_internal_url'] = '';

// In-browser R fiddle (webR) that the OpenCPU debugger links to.
// Code is passed in the URL fragment, which browsers never send to the
// server. Set to '' to disable the links.
$settings['r_fiddle_url'] = 'https://fiddle.rforms.org/';

// email SMTP and queueing configuration for emails sent by the formr app itself
// for example for email confirmation and password reset
$settings['email'] = array(
	'host' => 'smtp.example.com',
	'port' => 587,
	'tls' => true,
	'from' => 'email@example.com',
	'from_name' => 'Formr',
	'username' => 'email@example.com',
	'password' => 'password',
	// use db queue for emailing
	'use_queue' => true,
	// Number of seconds for which deamon loop should rest before getting next batch
	'queue_loop_interval' => 10,
	// Number of seconds to expire (i.e delete) a queue item if it failed to get delivered
	'queue_item_ttl' => 20*60,
	// Number of times to retry an item before deleting
	'queue_item_tries' => 4,
    // an array of account IDs to skip when processing mail queue
    'queue_skip_accounts' => array(),
	// SMTP options for phpmailer
	'smtp_options' => array(),
);

// email SMTP and queueing configuration for emails sent by formr admins in studies
// maybe not be the same as the formr app email
$settings['default_admin_email'] = array(
	'host' => NULL,
	'port' => NULL,
	'tls' => true,
	'from' => NULL,
	'from_name' => NULL,
	'username' => NULL,
	'password' => NULL
);

// should PHP and MySQL errors be displayed to the users when formr is not running locally? If 0, they are only logged
$settings['display_errors_when_live'] = 0;
$settings['display_errors'] = 0;
$settings['error_to_stderr'] = 0;

// Content-Security-Policy for the admin area: 'off' | 'report-only' | 'enforce'.
// Default 'enforce' (blocks violating scripts). If the admin UI misbehaves on
// your instance (e.g. a browser-side call to a non-default OpenCPU origin),
// set this to 'report-only' to observe + report without blocking, fix the
// policy in application/Csp.php, then switch back. 'off' disables it entirely.
$settings['csp_mode'] = 'enforce';

// how many emails can be maximally sent to one email address during certain time periods
$settings['email_thresholds'] = [
	'in_last_1m' => 1,
	'in_last_1m_testing' => 3,
	'in_last_10m' => 2,
	'in_last_10m_testing' => 10,
	'in_last_1h' => 4,
	'in_last_1h_testing' => 10,
	'in_last_1d' => 10,
	'in_last_1d_testing' => 100,
	'in_last_1w' => 60
];

/*
 * Outbound-mail abuse guard.
 *
 * Failure mode this prevents: bots submit the signup / forgot-password form
 * with strangers' addresses (subscription bombing); formr mails each one; the
 * recipients complain; your provider disables SMTP for the whole mailbox.
 * Every send is checked against the suppression list (bin/suppression.php)
 * and logged in survey_mail_guard_log; unauthenticated "transactional" mail
 * (verification, password reset) is additionally subject to a duplicate-send
 * window and the rate ceilings below. Study/cron mail keeps its own limits
 * (email_thresholds above) and is never capped by these ceilings.
 * Status page: admin/advanced/mail_guard.
 *
 * The defaults are deliberately generous for humans and tight for bots: a
 * lab will never notice them, a bombing run is capped at tens of messages
 * per hour instead of thousands. Set a limit to 0 to disable that limit.
 */
$settings['mail_guard'] = array(
	'enabled' => true,
	// refuse a second verification / password-reset mail to the same address
	// within this window (a repeat request within it is almost always abuse)
	'duplicate_window_hours' => 24,
	'ip_limit_per_hour' => 10,       // transactional mail per source IP
	'subnet_limit_per_hour' => 20,   // …per /24 (IPv4) or /64 (IPv6)
	'global_limit_per_hour' => 50,   // …instance-wide; hitting it is surfaced in the admin UI
	// Signups bearing a valid referral/invite token are almost never spam, so
	// they SKIP the per-IP and per-/24 limits above (this is what lets a whole
	// workshop or course cohort sign up from one institutional NAT). They are
	// still bounded by this separate, higher instance-wide ceiling, so a leaked
	// token can't be used to bomb without limit.
	'trusted_global_limit_per_hour' => 200,
	// refuse addresses whose domain has no MX and no A/AAAA record (or a
	// null MX). Only an authoritative "no such record" fails closed; a
	// transient resolver failure (SERVFAIL, timeout) sends anyway, logs, and
	// is not cached.
	'mx_check' => true,
	'mx_cache_hours' => 24,
	// keep survey_mail_guard_log rows this long (daily prune from the
	// cleanup cron; 0 = keep forever). Longest rate-limit window is a week.
	'log_retention_days' => 90,
	// optional blocklist file: one domain per line, # comments. Empty default.
	'disposable_domains_file' => null,
	// HMAC secret for the signup form's signed timestamp and the Altcha
	// challenge; derived from the instance crypto key when null.
	'form_secret' => null,
);

/*
 * Who may register an account at all. Most lab instances need accounts for a
 * handful of researchers and hand participants session links — for those,
 * 'domains' or 'closed' removes the signup attack surface entirely. Be
 * honest with yourself: 'open' on a public instance means accepting
 * signup-mail abuse as a cost of doing business.
 *
 *   'open'     anyone (current behaviour, default)
 *   'domains'  only emails under 'allowed_domains' (whole domains, subdomains
 *              included) OR ending in an 'allowed_tlds' entry (e.g. only .edu).
 *              A single-use invitation token (issued by a superadmin under
 *              admin/advanced/registration_invites, or via
 *              bin/registration_invite.php) overrides the restriction for
 *              one person — the escape hatch for legitimate exceptions.
 *   'invite'   only with such a single-use invitation token
 *   'closed'   nobody
 *
 * Independent of the DB-backed signup:allow switch in the admin UI — both
 * must allow a signup. The honeypot + signed-timestamp bot check on the form
 * is always on; 'challenge' adds a proof-of-work widget on top:
 *   'altcha'    self-hosted, no third party, no data-protection questions
 *               (recommended if you need a challenge)
 *   'turnstile' Cloudflare — sends visitor data to a third party, which your
 *               privacy policy must then document; needs both keys below
 */
$settings['registration'] = array(
	'mode' => 'open',
	'allowed_domains' => array(), // ('domains' mode) whole domains, e.g. array('uni-example.edu', 'example.org')
	'allowed_tlds' => array(),    // ('domains' mode) accept any address ending in these TLDs, e.g. array('edu', 'ac.uk')
	'min_form_seconds' => 3,      // submissions faster than this are bots
	'form_token_ttl' => 43200,    // signed form timestamp validity (12 h)
	'challenge' => null,          // null | 'altcha' | 'turnstile'
	'turnstile_site_key' => '',
	'turnstile_secret_key' => '',
);

// What regular expression should the user codes match? Make sure your RegEx allows 64 character base64 like ^[A-Za-z0-9+-_~]{64}, as these are the codes formr generates. You can generate shorter/longer codes yourself though.
$settings['user_code_regular_expression'] = "/^[A-Za-z0-9+-_~]{64}$/";

// Should studies be required to have a non-empty privacy policy before they can go live/public?
$settings['content_settings']['studies:require_privacy_policy'] = false;

$settings['content_settings']['content:about:show'] = true;
$settings['content_settings']['content:docu:show'] = true;
$settings['content_settings']['content:docu:support_email'] = "email@example.com";
$settings['content_settings']['content:studies:show'] = true;
$settings['content_settings']['content:publications:show'] = true;
$settings['content_settings']['content:publications'] = '';
$settings['content_settings']['content:terms_of_service'] = '';
$settings['content_settings']['content:privacy_policy'] = '';
$settings['content_settings']['content:file_upload_terms'] = '';
$settings['content_settings']['content:file_upload_require_active_consent'] = false;

$settings['content_settings']['footer:link:logourl'] = '';
$settings['content_settings']['footer:link:logolink'] = '';
$settings['content_settings']['footer:imprint'] = '';

$settings['content_settings']['signup:allow'] = true;
$settings['content_settings']['signup:message'] = '';
$settings['content_settings']['signup:enable_referral_token'] = true;

// Session expiration related settings
// (for unregistered users. in seconds (defaults to a year))
$settings['expire_unregistered_session'] = 365 * 24 * 60 * 60;
// (for registered users. in seconds (defaults to a week))
$settings['expire_registered_session'] = 7 * 24 * 30 * 60;
// (for admins. in seconds (defaults to a week). has to be lower than the expiry for registered users)
$settings['expire_admin_session'] = 7 * 24 * 30 * 60;
// upper limit for all values above (defaults to their max)
$settings['session_cookie_lifetime'] = max($settings['expire_unregistered_session'], $settings['expire_registered_session'], $settings['expire_admin_session']);

// Maximum size allowed for uploaded files in MB
$settings['admin_maximum_size_of_uploaded_files'] = 50;
$settings['allowed_file_endings_for_run_upload'] = array(
	'image/jpeg' => 'jpg', 
	'image/png' => 'png', 
	'image/gif' => 'gif', 
	'image/tiff' => 'tif',
	'video/mpeg' => 'mpg', 
	'video/quicktime' => 'mov', 
	'video/x-flv' => 'flv', 
	'video/x-f4v' => 'f4v', 
	'video/x-msvideo' => 'avi',
	'audio/mpeg' => 'mp3',
	'application/pdf' => 'pdf',
	'text/csv' => 'csv', 
	'text/javascript' => 'js', 
	'text/css' => 'css', 
	'text/tab-separated-values' => 'tsv', 
	'text/plain' => 'txt',
	'text/html' => 'html'
);

// Directory for exported runs
$settings['run_exports_dir'] = APPLICATION_ROOT . 'documentation/run_components';

// Directory for uploaded survey
$settings['survey_upload_dir'] = APPLICATION_ROOT . 'tmp/backups/surveys';

// application webroot
$settings['web_dir'] = APPLICATION_ROOT . 'webroot';

// Cron settings
$settings['cron'] = array(
	// maximum time to live for a 'cron session' in minutes
	'ttl_cron' => 15,
	// maximum time to live for log file in minutes
	'ttl_lockfile' => 30,
	// Should cron be intercepted if session time is exceeded?
	'intercept_if_expired' => false,
);

// Settings for social share buttons
$settings['social_share'] = array(
	'facebook' => array(
		'url' => 'https://www.facebook.com/sharer.php?u=%{url}&t=%{title}',
		'target' => '_blank',
		'width' => 300,
		'height' => 400,
	),
	'twitter' => array(
		'url' => 'https://twitter.com/share?url=%{url}&text=%{title}',
		'target' => '_blank',
		'width' => 300,
		'height' => 400,
	),
);


// Default time lengths for email subscriptions
$settings['email_subscriptions'] = array(
	'1' => 'Subscribe to E-mails',
	'+1 week' => 'Unsubscribe for one week',
	'+2 weeks' => 'Unsubscribe for two weeks',
	'+1 month' => 'Unsubscribe for one month',
	'0' => 'Never receive emails',
);

// Limit to number of pages to skip in a survey
$settings['allowed_empty_pages'] = 100;

// Run unit session settings for the sessions queue
$settings['unit_session'] = array(
	// String representing howmany minutes to set as default expiration for unit sessions
	// @see https://php.net/manual/en/function.strtotime.php
	'queue_expiration_extension' => '+10 minutes',
	// use db queue for processing unit sessions
	'use_queue' => true,
	// Log debug messages
	'debug' => false,
);

// Configure memory limits to be set when performing certain actions
$settings['memory_limit'] = array(
	// Run
	'run_get_data' => '1024M',
	'run_import_units' => '256M',
	// Spreadsheet
	'spr_object_array' => '1024M',
	'spr_sheets_array' => '1024M',
	// Survey
	'survey_get_results' => '2048M',
	'survey_upload_items' => '256M',
);

// Absolute path to file where encryption key for creating email accounts should be stored
// Defaults to {APPLICATION_ROOT}/formr-crypto.key
$settings['encryption_key_file'] = null;

// Use this config item to enable the apache X-Sendfile header. @see https://tn123.org/mod_xsendfile/
// Before using this, make sure xsendfile is installed and configured correctly with apache
$settings['use_xsendfile'] = true;

// Reserved run names which users are not allowed to use. Must cover every
// top-level router slug (setup.php routes): in path-based (non-subdomain)
// deployments the router matches the first URL segment against the route
// table BEFORE falling through to "treat it as a run name" — a run claiming
// a slug like `survey-test` would be unreachable (participants get the
// preview controller's 403 instead of the study). isReservedName() blocks
// the name and its `name-*` prefixes, case-insensitively.
$settings['reserved_run_names'] = array(
    'api', 'test', 'delegate',
    'admin', 'run', 'public', 'survey-test',
    'formr-test-run', // Run::TEST_RUN, the internal preview pseudo-run
);

// Flag indicating whether formr is in maintenance mode
// If this is set to true, then users will see a maintenance message and cron jobs will not run
// Restart all server daemon whenever this flag is changed
$settings['in_maintenance'] = false;

// Configure IP addresses that can still access the application even in maintenance mode
// Example ['192.18.2.3', '192.18.3.4']
$settings['maintenance_ips'] = [];

// curl settings that override the default settings in the CURL class
// Use exact PHP constants as defined in https://php.net/manual/en/function.curl-setopt.php
$settings['curl'] = array(
	CURLOPT_SSL_VERIFYPEER => true,
	CURLOPT_SSL_VERIFYHOST => 2,
);

// Disable features temporarily by entering the Controller action names in this array
$settings['disabled_features'] = array(
    // RUN.controller_method_name
    // SURVEY.controller_method_name
);

// Enable form_v2 authoring.
// When true, admins can add the "Form" RunUnit type to a run and pick the
// form_v2 engine when adding a new survey. Existing Form units and studies
// already on v2 continue to load and run regardless of this flag; only those
// two ways in (the run editor's "Add Form", the "Engine" choice on "Add a new
// survey") are gated by it.
$settings['form_v2_enabled'] = false;

// Per-study wide-table dual-write for survey responses.
// Historically formr writes every answer twice: once into the long-form
// survey_items_display table, once into a per-study wide table named
// survey_NN. This flag controls only the wide write — the long path is
// always taken (it's the source of truth for form_v2's pivoted export
// and the v1 API's /api/v1/runs/{name}/results endpoint).
//
// Leave on (default): v1 studies read the wide table (see
// form_v2_long_reads below), and it is the fallback for v2's. As of patch 108
// the readers that still go to the wide table when form_v2_long_reads
// is false are:
//   - UnitSession::getRunData (OpenCPU R overlay — tail(survey, 1))
//   - SurveyStudy::getResultCount (admin survey page "begun/finished")
//   - SurveyStudy::getAverageTimeItTakes
//   - AdminRunController::backup (per-run TSV)
// Form_v2 exports (admin CSV/XLSX) pivot from the long table regardless
// and are not affected by this flag. Do NOT turn this off while
// form_v2_long_reads is false: the wide table would stop filling and
// every reader above would go stale.
$settings['form_v2_dual_write_results'] = true;

// Long-format reads for v1 studies (opt-in). form_v2 studies always read
// their answers from the long format (survey_items_display +
// survey_unit_sessions): the OpenCPU R overlay (UnitSession::getRunData),
// admin result counts / average time, getResults (exports, the results
// page, the per-run backup TSV). A v1 study reads its own results table,
// exactly as before this release, unless this is true. The long read
// re-types each column from the item definition, so R code sees the same
// data either way; turning it on for v1 is for instances that want one
// read path — keep form_v2_dual_write_results on either way.
$settings['form_v2_long_reads'] = false;

// form_v2 offline-queue per-file blob cap (megabytes). Pages with file
// uploads above this size are NOT queued offline — the participant gets a
// "submission too large to queue offline" error and is asked to retry once
// online. Default 10MB matches what the bundle hardcoded historically.
// Raise for studies that routinely collect long-form audio/video.
$settings['form_v2_offline_blob_max_mb'] = 10;

// form_v2 endpoint rate limits, per fixed 60s window (FixedWindowRateLimiter,
// patch 113; buckets keyed by run session, beacon by IP; fail-open when the
// table is missing so a misapplied patch never locks participants out).
//   rcall_per_min  — /form-render-page (it triggers OpenCPU work)
//   write_per_min  — /form-page-submit + /form-save + /form-sync (DB writes)
//   beacon_per_min — /pwa-beacon (unauthenticated logging endpoint)
//   bot_challenge_per_min — /form-bot-challenge (bot_check nonce minting;
//                    own bucket so challenge fetches can't eat the write
//                    budget, or vice versa)
$settings['form_v2_rate_limits'] = array(
    'rcall_per_min' => 30,
    'write_per_min' => 90,
    'beacon_per_min' => 10,
    'bot_challenge_per_min' => 20,
);

// Per-slot TTLs (seconds) for the survey_r_call_results cache. Values,
// labels and choice labels resolve once per page show, so they tolerate a
// long TTL; lower them if edited R should take effect sooner. A slot missing
// here keeps its built-in default (RCallService::ttlForSlot).
$settings['form_v2_rcall_ttl'] = array(
    'value' => 300,
    'label' => 300,
    'choice_label' => 300,
);

// ---------------------------------------------------------------------------
// `bot_check` item — self-hosted Altcha + Argon2id proof-of-work (form_v2).
// Read by application/Services/BotCheckChallenge.php. Every key below has a
// working built-in default, so an install that sets none of them still gets a
// functioning, fail-closed bot check. Uncomment only what you want to change.
// The per-window limit on the mint endpoint is NOT here — it lives in
// $settings['form_v2_rate_limits']['bot_challenge_per_min'] above.
// See documentation/agent_doc/bot_check_altcha.md for the threat model.
// ---------------------------------------------------------------------------

// HMAC secret the challenge signature is derived from. Never sent to the
// client. Default (unset or empty string): derived from the instance's
// at-rest crypto key (`Crypto::getKeyFile()`), which every formr install has —
// so you normally leave this alone. If neither is available the item fails
// CLOSED: mint 503s, verify() refuses every token, and the participant sees
// "Human verification is temporarily unavailable". Set it only if you want a
// secret independent of the crypto key (rotating it invalidates outstanding
// challenges; participants just get a fresh one).
// $settings['bot_check_secret'] = '';

// Proof-of-work length: how many leading bytes of the derived key the client
// must match (~256 expected hashes per byte). 1..3; out-of-range values clamp.
// An item can override per-question with `bot_check N` in its type column —
// the server always decides, never the request.
// $settings['bot_check_prefix_bytes'] = 1;

// Argon2id memory cost in KiB. This is what makes the work memory-hard
// (GPU/ASIC-resistant). The server only ever computes ONE verification hash
// (~7 ms), so raising it costs an attacker, not you. Values below 256 are
// ignored and fall back to the default.
// $settings['bot_check_argon_memory'] = 4096;

// Argon2id iterations (passes). Values below 1 fall back to the default.
// $settings['bot_check_argon_time'] = 1;

// How long a minted challenge stays valid, in seconds. The token is bound to
// the participant's session and burned after a successful verify, so a long
// window grants an attacker nothing — the default is generous so a diary page
// left open overnight still verifies. Values below 60 fall back to the default.
// $settings['bot_check_ttl'] = 86400;

// Brand
$settings['brand'] = '<span>f</span>orm<span>{`r}</span>';
$settings['brand_long'] = '<b>formr</b> survey framework';

// Settings for creating the context used in the 'copy' function when copying images from the opencpu server to formr
$settings['copy_context'] = array(
	'ssl' => array(
		"verify_peer" => false,
		"verify_peer_name" => false,
	)
);

// Settings for 2FA
$settings['2fa'] = array(
    'enabled' => true, // whether 2FA is enabled for the instance
    'required' => false, // whether 2FA is required for all users
    'allow_during_signup' => false
);

// Run session lock timeout settings (in seconds)
$settings['run_session'] = array(
    'lock_timeout' => array(
        'queue' => 0.1,  // Queue should fail fast (0.1 seconds)
        'user' => 10.0   // Users can wait longer (10 seconds)
    ),
    // Maximum automated units executed back-to-back in ONE request. Exceeding
    // it halts the request WITHOUT ending the run session (a misconfigured
    // skip cycle stays cheap per request; a genuine catch-up continues on the
    // next request). If your design legitimately chains more automated units,
    // raise this.
    'max_execution_count' => 10,
);

// How long to keep study data, i.e. what is the maxmimum expiry that can be set in a run
// To allow users to keep study data indefinitely, set this to INF
$settings['keep_study_data_for_months_maximum'] = 84;

// Issue #608: instance-wide default monthly compute budget per study admin,
// in SECONDS of unit-session execution time (incl. OpenCPU/R) summed across
// all their runs in the current calendar month. 0 = unlimited (the default,
// so nothing is limited out of the box). When a user exceeds their effective
// limit, ComputeLimitCron sets their active runs non-public and disables their
// automatic actions (cron_active = 0). Compute-closed runs are NOT reopened
// automatically — the owner must republish them (and re-enable cron) once usage
// is addressed. A superadmin can override this per user via
// survey_users.compute_limit_monthly; users cannot change their own limit.
$settings['compute_limit_monthly_default'] = 0;

// Notification settings for sending notifications to study administrators about issues with their study in minutes
$settings['notification'] = array(
    'default_throttle_minutes' => 10,
    'throttle_map' => [
        'error' => 10,
        'warning' => 10,
        'info' => 10,
    ],
);
