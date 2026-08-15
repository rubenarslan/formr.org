<?php
/**
 * Every value that can be written into `survey_unit_sessions.result`, the
 * unit that owns it, and how it is reached.
 *
 * Shared ground truth for two consumers:
 *   - tests/ResultCatalogueTest.php — CI guard. Fails if the source grows a
 *     result literal that is not listed here, or if an entry here no longer
 *     appears in the source. Keeps this file honest without a DB.
 *   - tests/e2e/result-coverage-*.spec.js — the browser lane. Fixtures built
 *     through the formr MCP, traversed as a participant, asserted on the row.
 *     bin/result_coverage_report.php scores what those specs observed against
 *     this file.
 *
 * `reach` values:
 *   web         — a participant request through RunSession::execute()
 *   cron        — needs a daemon pass (queue pickup / sweep)
 *   smtp        — written by the mail daemon after an SMTP outcome
 *   admin       — an admin action (test button, force-move, structure edit)
 *   unreachable — provably cannot be persisted; `why` cites the audit finding
 *   dead        — the code path itself is unreachable
 *
 * The unreachable/dead entries are the point of this file as much as the
 * reachable ones: they are the audit's claims mechanised, so a future change
 * that makes one persistable shows up as a coverage surprise rather than
 * going unnoticed. See documentation/agent_doc/LOG_STATE_AUDIT.md.
 */
return [
    // ---- Survey -----------------------------------------------------
    'survey_started'      => ['unit' => 'Survey', 'reach' => 'web',  'how' => 'first render creates the results row'],
    'survey_filling_out'  => ['unit' => 'Survey', 'reach' => 'web',  'how' => 'page submitted, more pages remain'],
    // v1.8.0 flipped these two. Before resolveEndResult(), end() re-derived
    // the result from the unit TYPE, so survey_completed was written and
    // immediately overwritten by survey_ended — it was unreachable. Now the
    // unit's own verdict survives, so a genuine completion records
    // survey_completed and survey_ended is only what end() falls back to when
    // nothing was logged this pass (a forced advance, a queue-driven end).
    // Analyses filtering on result='survey_ended' to count completions must
    // be updated; that is the point of the change, since the two were
    // previously indistinguishable.
    'survey_completed'    => ['unit' => 'Survey', 'reach' => 'web',  'how' => 'final page submitted; the unit logs its own verdict'],
    'survey_ended'        => ['unit' => 'Survey', 'reach' => 'admin', 'how' => "end() type default when the unit logged nothing this pass"],
    'error_survey'        => ['unit' => 'Survey', 'reach' => 'web',  'how' => 'exception in processStudy (e.g. missing results table)'],
    'expired'             => ['unit' => 'Survey', 'reach' => 'cron', 'how' => 'X+Y or Z deadline passes -> expire()'],

    // ---- Pause ------------------------------------------------------
    'pause_waiting'       => ['unit' => 'Pause',  'reach' => 'web',  'how' => 'participant arrives before the deadline'],
    'pause_ended'         => ['unit' => 'Pause',  'reach' => 'cron', 'how' => 'deadline passes -> end() type default'],
    'error_pause_relative_to'  => ['unit' => 'Pause', 'reach' => 'unreachable',
                              'why'  => 'persisted by isExpired(), then overwritten by pause_waiting in the same request'],
    'error_pause_wait_minutes' => ['unit' => 'Pause', 'reach' => 'unreachable',
                              'why'  => 'same overwrite as error_pause_relative_to'],

    // ---- Wait -------------------------------------------------------
    'wait_ended_by_user'  => ['unit' => 'Wait',   'reach' => 'web',  'how' => 'any non-cron request while parked -> run_to body'],
    'wait_ended'          => ['unit' => 'Wait',   'reach' => 'cron', 'how' => 'deadline elapses -> end() type default'],

    // ---- Email ------------------------------------------------------
    'email_queued'        => ['unit' => 'Email',  'reach' => 'web',  'how' => 'queued for the mail daemon'],
    'email_sent'          => ['unit' => 'Email',  'reach' => 'smtp', 'how' => 'mail daemon delivered it'],
    'error_email'         => ['unit' => 'Email',  'reach' => 'web',  'how' => 'synchronous send failed (use_queue=false)'],
    'error_email_not_sent'        => ['unit' => 'Email', 'reach' => 'smtp', 'how' => 'SMTP rejected the message'],
    'error_email_invalid_recipient' => ['unit' => 'Email', 'reach' => 'smtp', 'how' => 'recipient failed FILTER_VALIDATE_EMAIL in the daemon'],
    'error_email_invalid_subject'   => ['unit' => 'Email', 'reach' => 'smtp', 'how' => 'empty subject at send time'],
    'email_gave_up'       => ['unit' => 'Email',  'reach' => 'smtp', 'how' => 'retry budget or TTL exhausted'],
    'email_skipped_user_disabled' => ['unit' => 'Email', 'reach' => 'web', 'how' => 'participant opted out or snoozed'],
    'email_skipped_user_active'   => ['unit' => 'Email', 'reach' => 'web', 'how' => 'cron_only unit reached by a human request'],
    'no_recipient'        => ['unit' => 'Email',  'reach' => 'web',  'how' => 'recipient field resolved to nothing'],
    'no_sender'           => ['unit' => 'Email',  'reach' => 'web',  'how' => 'no email account configured'],
    'no_email_subject'    => ['unit' => 'Email',  'reach' => 'web',  'how' => 'subject knit returned nothing'],
    'no_email_body'       => ['unit' => 'Email',  'reach' => 'web',  'how' => 'body knit returned nothing'],
    'invalid_email'       => ['unit' => 'Email',  'reach' => 'web',  'how' => 'recipient is not a valid address'],
    'error_send_eligible' => ['unit' => 'Email',  'reach' => 'unreachable',
                              'why'  => 'rate-limit warning written to $this->messages, read only on the success path'],

    // ---- PushMessage ------------------------------------------------
    'sent'                => ['unit' => 'PushMessage', 'reach' => 'web', 'how' => 'push accepted by the push service'],
    'error'               => ['unit' => 'PushMessage', 'reach' => 'web', 'how' => 'exception during send'],
    'no_subscription'     => ['unit' => 'PushMessage', 'reach' => 'web', 'how' => 'participant has no push subscription'],
    'message_parse_failed'=> ['unit' => 'PushMessage', 'reach' => 'web', 'how' => 'message knit returned nothing'],
    'title_parse_failed'  => ['unit' => 'PushMessage', 'reach' => 'web', 'how' => 'title R was supposed to knit and returned nothing'],

    // ---- External ---------------------------------------------------
    'external_redirect'      => ['unit' => 'External', 'reach' => 'web', 'how' => 'plain address, participant redirected'],
    'external_r_redirect'    => ['unit' => 'External', 'reach' => 'web', 'how' => 'R address returned a URL'],
    'external_r_call_no_redirect' => ['unit' => 'External', 'reach' => 'web', 'how' => 'R address returned FALSE'],
    'external_compute'       => ['unit' => 'External', 'reach' => 'web', 'how' => 'R address returned a non-address value'],
    'external_wait_for_api'  => ['unit' => 'External', 'reach' => 'web', 'how' => 'api_end unit waiting for a callback'],
    'external_ended'         => ['unit' => 'External', 'reach' => 'web', 'how' => 'end() type default, or the end-last-external callback'],
    'error_opencpu'          => ['unit' => 'External', 'reach' => 'web', 'how' => 'R address failed to evaluate'],

    // ---- Page / Endpage ---------------------------------------------
    'ended'               => ['unit' => 'Page',   'reach' => 'web',  'how' => 'endpage rendered, run session ends'],
    'ended_by_queue'      => ['unit' => 'Page',   'reach' => 'unreachable',
                              'why'  => 'the cron branch returns wait_user, so end() is never reached under cron for an Endpage'],
    'error_opencpu_down'  => ['unit' => 'Page',   'reach' => 'web',  'how' => 'knit failed because OpenCPU was unreachable'],
    'success_knitted'     => ['unit' => 'Page',   'reach' => 'unreachable',
                              'why'  => 'set on $this->messages; Page never reads it (compare Email.php:522)'],

    // ---- Shuffle ----------------------------------------------------
    'group_N'             => ['unit' => 'Shuffle', 'reach' => 'web', 'how' => 'group_1..group_N, one per configured group',
                              'pattern' => '/^group_\d+$/'],

    // ---- Branch / SkipForward / SkipBackward ------------------------
    'skip_true'           => ['unit' => 'SkipForward', 'reach' => 'web', 'how' => 'condition TRUE -> jump to if_true'],
    'skip_false'          => ['unit' => 'SkipForward', 'reach' => 'web', 'how' => 'condition FALSE -> continue'],
    'skip_to'             => ['unit' => 'SkipForward', 'reach' => 'web', 'how' => 'numeric condition >= 2 -> computed jump'],
    'skip_to_invalid'     => ['unit' => 'SkipForward', 'reach' => 'web', 'how' => 'computed jump to a position with no unit'],
    'error_opencpu_r'     => ['unit' => 'SkipForward', 'reach' => 'web', 'how' => 'condition failed to evaluate, or a nested list, or a dangling if_true'],
    'waiting_deprecated'  => ['unit' => 'SkipForward', 'reach' => 'cron', 'how' => 'legacy automatically_jump=0 under cron'],

    // ---- Engine / cross-unit ----------------------------------------
    'ended_by_queue_rse'  => ['unit' => '(engine)', 'reach' => 'cron',  'how' => 'daemon reached a unit of an already-ended run session'],
    'moved'               => ['unit' => '(engine)', 'reach' => 'admin', 'how' => 'forceMoveOn / API advance'],
    'swept_stale'         => ['unit' => '(engine)', 'reach' => 'cron',  'how' => 'stalled-session sweep, only on a row with a NULL result'],
    'run_structure_replaced' => ['unit' => '(engine)', 'reach' => 'admin', 'how' => 'structure replaced under live sessions; COALESCE, so only on a NULL result'],
    'unit_removed_from_run'  => ['unit' => '(engine)', 'reach' => 'admin', 'how' => 'unit deleted under live sessions; COALESCE, so only on a NULL result'],
    'manual_admin_push'   => ['unit' => '(engine)', 'reach' => 'unreachable',
                              'why'  => 'forceTo() calls end() first, so logResult()\'s `ended IS NULL` guard matches zero rows'],

    // ---- Dead code --------------------------------------------------
    'security_token_error' => ['unit' => 'Survey',  'reach' => 'dead',
                               'why' => '$ignore_post is passed by value and set nowhere; left over from the v0.25.1 CSRF removal'],
    'privacy_tos_consent'  => ['unit' => 'Privacy', 'reach' => 'dead',
                               'why' => 'Privacy is not in SupportedUnits and survey_privacy exists in no schema file'],
];
