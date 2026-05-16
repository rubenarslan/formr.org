#!/usr/bin/php
<?php
/**
 * E2E test fixture for the Survey-expiry characterisation suite.
 *
 * Idempotent: deletes any prior fixture with the same run name before
 * recreating. Outputs one line of JSON to stdout that the Playwright
 * helper parses. Documented contract:
 *
 *   {run_name, code, run_id, run_session_id, study_id, results_table,
 *    survey_unit_session_id, endpage_id, item_ids: [...] }
 *
 * Flags:
 *   --x=<int>           expire_invitation_after (minutes), default 0
 *   --y=<int>           expire_invitation_grace (minutes), default 0
 *   --z=<int>           expire_after            (minutes), default 0
 *   --items=<int>       number of required text items, default 1
 *   --owner=<email>     admin user that owns the fixture, default
 *                       robot@researchmixtapes.com
 *   --name=<run-name>   override the default run name (e2e-expiry); useful
 *                       when several specs run in parallel CI — but defaults
 *                       are fine for workers=1
 *   --paging=<0|1>      use_paging on the Survey, default 0
 *   --use-existing      if a fixture with this name already exists, reuse
 *                       it instead of dropping/recreating (faster for
 *                       same-X/Y/Z reruns); ignored when settings differ
 */
require_once __DIR__ . '/../setup.php';

$opts = getopt('', ['x::', 'y::', 'z::', 'items::', 'owner::', 'name::', 'paging::', 'use-existing', 'pause::', 'with-unit-session']);
$x        = (int)   ($opts['x']      ?? 0);
$y        = (int)   ($opts['y']      ?? 0);
$z        = (int)   ($opts['z']      ?? 0);
$items_n  = (int)   ($opts['items']  ?? 1);
$owner    = (string)($opts['owner']  ?? 'robot@researchmixtapes.com');
$run_name = (string)($opts['name']   ?? 'e2e-expiry');
$paging   = (int)   ($opts['paging'] ?? 0);
$reuse    = array_key_exists('use-existing', $opts);
// --pause is a JSON object with optional keys: wait_minutes, relative_to,
// wait_until_time, wait_until_date. When present, a Pause unit is inserted
// between Survey (position 10) and Endpage (position 20) at position 15.
$pause_config = isset($opts['pause']) ? json_decode($opts['pause'], true) : null;

$db = DB::getInstance();

$user_id = $db->findValue('survey_users', ['email' => $owner], 'id');
if (!$user_id) {
    fwrite(STDERR, "owner '{$owner}' not found in survey_users\n");
    exit(1);
}
$user_id = (int)$user_id;

$study_name = "e2e_expiry_" . substr(md5($run_name), 0, 8);

// Tear down any prior fixture with this run name (idempotent).
$existing_run_id = $db->findValue('survey_runs', ['user_id' => $user_id, 'name' => $run_name], 'id');
if ($existing_run_id) {
    if ($reuse) {
        $study_id_existing = (int)$db->execute(
            "SELECT u.id FROM survey_units u JOIN survey_run_units ru ON ru.unit_id=u.id WHERE ru.run_id=:r AND u.type='Survey' LIMIT 1",
            ['r' => $existing_run_id], true
        );
        $rt = $db->findValue('survey_studies', ['id' => $study_id_existing], 'results_table');
        // Note: we don't reuse if X/Y/Z don't match, but checking that is more code than we need.
        // Caller passes --use-existing only when they know the settings are compatible.
        if ($study_id_existing && $rt) {
            $db->query("DELETE FROM survey_unit_sessions WHERE run_session_id IN (SELECT id FROM survey_run_sessions WHERE run_id=" . (int)$existing_run_id . ")");
            $db->query("DELETE FROM survey_run_sessions WHERE run_id = " . (int)$existing_run_id);
        }
    } else {
        $db->query("DELETE FROM survey_unit_sessions WHERE run_session_id IN (SELECT id FROM survey_run_sessions WHERE run_id=" . (int)$existing_run_id . ")");
        $db->query("DELETE FROM survey_run_sessions WHERE run_id = " . (int)$existing_run_id);
        $db->query("DELETE FROM survey_run_units WHERE run_id = " . (int)$existing_run_id);
        $db->delete('survey_runs', ['id' => $existing_run_id]);
    }
}
if (!$reuse || !$existing_run_id) {
    $existing_study_id = $db->findValue('survey_studies', ['user_id' => $user_id, 'name' => $study_name], 'id');
    if ($existing_study_id) {
        $rt = $db->findValue('survey_studies', ['id' => $existing_study_id], 'results_table');
        if ($rt) $db->query("DROP TABLE IF EXISTS `" . $rt . "`");
        $db->delete('survey_items', ['study_id' => $existing_study_id]);
        $db->delete('survey_studies', ['id' => $existing_study_id]);
        $db->delete('survey_units', ['id' => $existing_study_id]);
    }
    // Drop any orphaned Endpage by title marker.
    $endpage_marker = 'e2e_expiry_endpage_' . substr(md5($run_name), 0, 8);
    $existing_endpage_id = $db->findValue('survey_pages', ['title' => $endpage_marker], 'id');
    if ($existing_endpage_id) {
        $db->delete('survey_pages', ['id' => $existing_endpage_id]);
        $db->delete('survey_units', ['id' => $existing_endpage_id]);
    }
}

// --- Build Survey unit ---
$db->insert('survey_units', ['type' => 'Survey', 'created' => mysql_now(), 'modified' => mysql_now()]);
$study_id = (int)$db->lastInsertId();
$results_table = "s{$study_id}_" . $study_name;

$db->insert('survey_studies', [
    'id' => $study_id, 'user_id' => $user_id, 'name' => $study_name,
    'results_table' => $results_table,
    'expire_invitation_after' => $x, 'expire_invitation_grace' => $y, 'expire_after' => $z,
    'use_paging' => $paging, 'rendering_mode' => 'v1', 'valid' => 1,
    'maximum_number_displayed' => 0, 'displayed_percentage_maximum' => 0, 'add_percentage_points' => 0,
    'enable_instant_validation' => 1, 'created' => mysql_now(), 'modified' => mysql_now(),
]);

$item_ids = [];
for ($i = 1; $i <= $items_n; $i++) {
    $db->insert('survey_items', [
        'study_id' => $study_id, 'name' => "q{$i}", 'type' => 'text',
        'label' => "Q{$i}", 'optional' => 0, 'order' => $i,
    ]);
    $item_ids[] = (int)$db->lastInsertId();
}
$db->insert('survey_items', [
    'study_id' => $study_id, 'name' => 'submit_btn', 'type' => 'submit',
    'label' => 'Submit', 'optional' => 0, 'order' => $items_n + 1,
]);

$cols = '';
for ($i = 1; $i <= $items_n; $i++) $cols .= "`q{$i}` TEXT NULL,\n";
$db->query("CREATE TABLE `{$results_table}` (
    `session_id` INT UNSIGNED NOT NULL, `study_id` INT UNSIGNED NOT NULL,
    `iteration` INT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    `created` DATETIME NULL, `modified` DATETIME NULL, `ended` DATETIME NULL, `expired` DATETIME NULL,
    {$cols} PRIMARY KEY (`session_id`), INDEX `idx_study` (`study_id` ASC),
    CONSTRAINT FOREIGN KEY (`session_id`) REFERENCES `survey_unit_sessions` (`id`) ON DELETE CASCADE,
    CONSTRAINT FOREIGN KEY (`study_id`) REFERENCES `survey_studies` (`id`)
) ENGINE=InnoDB");

// --- Build optional Pause unit ---
// Always clean up any prior Pause for this run name (idempotent), even
// when the new fixture has no Pause — keeps the survey_units / survey_pauses
// tables clean across re-provisions with different unit lists.
$pause_marker = "e2e_expiry_pause_" . substr(md5($run_name), 0, 8);
$existing_pause_id = $db->execute(
    "SELECT u.id FROM survey_units u JOIN survey_pauses p ON p.id=u.id WHERE p.body=:m LIMIT 1",
    ['m' => $pause_marker], true
);
if ($existing_pause_id) {
    $db->delete('survey_pauses', ['id' => $existing_pause_id]);
    $db->delete('survey_units', ['id' => $existing_pause_id]);
}

$pause_id = null;
if ($pause_config !== null) {
    $db->insert('survey_units', ['type' => 'Pause', 'created' => mysql_now(), 'modified' => mysql_now()]);
    $pause_id = (int)$db->lastInsertId();
    // wait_minutes is decimal(13,2) — coerce empty string and the
    // string "" passed via JSON to NULL (the AMOR export uses '' for
    // null but MariaDB rejects '' for decimal columns).
    $wm = $pause_config['wait_minutes'] ?? null;
    if ($wm === '') $wm = null;
    $db->insert('survey_pauses', [
        'id' => $pause_id,
        'body' => $pause_marker,  // sentinel for idempotent cleanup
        'body_parsed' => '<p>e2e pause</p>',
        'wait_until_time' => $pause_config['wait_until_time'] ?? '00:00:00',
        'wait_until_date' => $pause_config['wait_until_date'] ?? '0000-00-00',
        'wait_minutes'    => $wm,
        'relative_to'     => $pause_config['relative_to']     ?? null,
    ]);
}

// --- Build Endpage unit ---
// Stored as type='Page' in survey_units (the class name, matching
// RunUnitFactory::SupportedUnits at RunUnit.php:5). Page.php overrides
// the runtime type to 'Endpage' in find(), but DB stores 'Page'.
$db->insert('survey_units', ['type' => 'Page', 'created' => mysql_now(), 'modified' => mysql_now()]);
$endpage_id = (int)$db->lastInsertId();
$endpage_marker = 'e2e_expiry_endpage_' . substr(md5($run_name), 0, 8);
$db->insert('survey_pages', [
    'id' => $endpage_id, 'title' => $endpage_marker, 'body' => 'Done.',
    'body_parsed' => '<p data-marker="e2e-expiry-endpage">Done.</p>', 'end' => 1,
]);

// --- Build Run + link units ---
$db->insert('survey_runs', [
    'user_id' => $user_id, 'name' => $run_name,
    'cron_active' => 1, 'public' => 2, 'locked' => 0,
    'created' => mysql_now(), 'modified' => mysql_now(),
]);
$run_id = (int)$db->lastInsertId();
$db->insert('survey_run_units', ['run_id' => $run_id, 'unit_id' => $study_id,  'position' => 10, 'description' => 'expiry survey']);
if ($pause_id !== null) {
    $db->insert('survey_run_units', ['run_id' => $run_id, 'unit_id' => $pause_id, 'position' => 15, 'description' => 'expiry pause']);
}
$db->insert('survey_run_units', ['run_id' => $run_id, 'unit_id' => $endpage_id, 'position' => 20, 'description' => 'expiry endpage']);

// --- Mint a testing run-session ---
$run = new Run(null, $run_id);
$run_session = RunSession::getNamedSession($run, 'e2eXXX' . substr(md5(mt_rand()), 0, 16), 1);
$rs_id = (int)$run_session->id;

$unit_session_id = null;
if (array_key_exists('with-unit-session', $opts)) {
    // Pre-create the Survey unit-session + populate items_display + insert
    // a results-table row, all without needing a participant visit. This is
    // what RunSession::createUnitSession + UnitSession::createSurveyStudyRecord
    // would do on a real visit; doing it here lets matrix tests skip the
    // 1-2 s Playwright round-trip per cell. Side effect: queue() runs and
    // might compute an `expires` from the initial state, which the test
    // can overwrite afterwards via setUnitSessionExpires().
    $survey_unit = RunUnitFactory::make($run, ['id' => $study_id]);
    $unit_session = new UnitSession($run_session, $survey_unit);
    $unit_session->create(true);
    $unit_session_id = (int)$unit_session->id;

    // Run the standard initial-visit setup that processStudy would run:
    // creates the results-table row + survey_items_display rows.
    $unit_session->createSurveyStudyRecord();

    // The unit-session was just created with a `created` of NOW; in the
    // bare row queue() hasn't run yet so `expires` is NULL. Tests can
    // then setUnitSessionCreated / setItemsDisplaySaved freely.
    // Also update run_session.position to the Survey's position (10).
    $db->update('survey_run_sessions', ['position' => 10], ['id' => $rs_id]);
}

echo json_encode([
    'run_name' => $run_name,
    'code' => $run_session->session,
    'run_id' => $run_id,
    'run_session_id' => $rs_id,
    'unit_session_id' => $unit_session_id,
    'study_id' => $study_id,
    'results_table' => $results_table,
    'endpage_id' => $endpage_id,
    'pause_id' => $pause_id,
    'item_ids' => $item_ids,
    'positions' => ['survey' => 10, 'pause' => $pause_id !== null ? 15 : null, 'endpage' => 20],
]) . "\n";
