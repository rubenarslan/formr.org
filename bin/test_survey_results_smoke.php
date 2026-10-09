#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for getting survey data out (SurveyStudy::getResults and
 * its pivotedResultsStatement, getResultsByItemsPerSession,
 * getItemDisplayResults, getResultCount, and the exports the admin builds on
 * them). Answers go in through the real write path
 * (UnitSession::createSurveyStudyRecord / updateSurveyStudyRecord / end), so
 * both the wide results table and survey_items_display hold rows:
 *
 *   - the long read (a v2 study, or a v1 study with form_v2_long_reads on) returns one row per
 *     unit session with every stored answer — a 0 kept as "0", a multiple
 *     choice as "1, 2", unanswered as NULL — plus session/ended metadata;
 *     notes and submit buttons are no columns;
 *   - with form_v2_long_reads off (the default), a v1 study reads the wide table instead,
 *     and it returns the same answers for the same sessions;
 *   - the session filter (prefix and exact 64-char code, its % and _ taken
 *     literally), the complete /
 *     incomplete filters, a run id and pagination (limit, offset, order)
 *     narrow both reads the same way; an unknown saved filter returns nothing;
 *   - getResultCount counts finished / begun / testers / real users;
 *   - getItemDisplayResults and getResultsByItemsPerSession return one row per
 *     item shown per session, in display order, with the answer; filtered and
 *     paginated too;
 *   - an unlinked study hides everything but test sessions and the session
 *     identity; the long-form readers refuse it;
 *   - the downloads: the run's TSV export (AdminRunController) and the
 *     survey's CSV / JSON results and item-display exports (AdminSurveyController)
 *     carry the same rows; so does the backup taken before results are
 *     deleted (backupTSV).
 *
 * What it guards (each was broken until the coverage pass found it):
 * getResultsByItemsPerSession with no matching session (it built `IN ()`);
 * the .tab exports keeping a multi-line answer inside its row (fields were
 * never quoted); the wide-read backup keeping integer 0s (fromArray() used
 * to skip them); and a run bundle's Survey unit with a new study being
 * placed in the run on import.
 *
 * Run: docker exec formr_app php /var/www/formr/bin/test_survey_results_smoke.php
 * Creates and removes its own run, study, sessions and files.
 */
require_once dirname(__FILE__) . '/../setup.php';
if (php_sapi_name() !== 'cli') { die("CLI only\n"); }

$db = DB::getInstance();
$failures = 0;
function ok($cond, string $label, $detail = null): void {
    global $failures;
    echo $cond ? "  \e[32mOK\e[0m  {$label}\n" : "  \e[31mFAIL\e[0m {$label}" . ($detail !== null ? ' — ' . var_export($detail, true) : '') . "\n";
    if (!$cond) { $failures++; }
}
/** Flip one setting for the rest of this process (Config has no setter). */
function setConfig(string $key, $value): void {
    $p = new ReflectionProperty(Config::class, 'config');
    $p->setAccessible(true);
    $all = $p->getValue();
    $all[$key] = $value;
    Config::initialize($all);
}
/** Run a download in a child process (the exports write php://output and exit). */
function download(string $php, string $to): string {
    $code = 'require ' . var_export(APPLICATION_ROOT . 'setup.php', true) . ';' . $php;
    $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['file', $to, 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    proc_close($proc);
    return (string) @file_get_contents($to);
}
/** Result rows keyed by session code, answer columns only, values as strings. */
function answers(array $rows, array $cols): array {
    $out = [];
    foreach ($rows as $r) {
        $out[$r['session']] = array_map(function ($c) use ($r) { return array_key_exists($c, $r) && $r[$c] !== null ? (string) $r[$c] : null; }, array_combine($cols, $cols));
    }
    ksort($out);
    return $out;
}

$owner = $db->execute('SELECT id FROM survey_users ORDER BY id LIMIT 1', [], false, true);
if (!$owner) { fwrite(STDERR, "No survey_users row to own the test run.\n"); exit(2); }
$GLOBALS['user'] = new User((int) $owner['id']);
$suffix = bin2hex(random_bytes(3));
$runId = $db->insert('survey_runs', [
    'user_id' => (int) $owner['id'], 'name' => 'ressmoke_' . $suffix,
    'created' => mysql_now(), 'modified' => mysql_now(), 'cron_active' => 0,
]);
$run = new Run(null, $runId);
$studyName = 'ressmoke_' . $suffix;
$item = function ($order, $name, $type, array $extra = []) {
    return $extra + ['name' => $name, 'type' => $type, 'label' => ucfirst($name), 'order' => $order, 'item_order' => $order, 'optional' => 1];
};
$bundle = ['units' => [[
    'type' => 'Survey', 'position' => 10, 'description' => 'results smoke',
    'survey_data' => ['name' => $studyName, 'items' => [
        $item(1, 'intro', 'note'),
        $item(2, 'txt', 'text'),
        $item(3, 'num', 'number', ['type_options' => '0,10,1']),
        $item(4, 'mc1', 'mc', ['choice_list' => 'zot', 'choices' => ['0' => 'zero', '1' => 'one', '2' => 'two']]),
        $item(5, 'mm', 'mc_multiple', ['choice_list' => 'zot']),
        $item(6, 'long', 'textarea'),
        $item(7, 'sub', 'submit', ['label' => 'Go']),
    ]],
], [
    'type' => 'Endpage', 'position' => 20, 'description' => 'end', 'body' => 'Done.',
]]];
$cols = ['txt', 'num', 'mc1', 'mm', 'long'];
$study = null; $sessions = []; $unitIds = []; $files = [];
function teardown() {
    global $db, $study, $run, $sessions, $unitIds, $files;
    foreach ($sessions as $s) { try { $db->exec('DELETE FROM survey_run_sessions WHERE id = :id', ['id' => $s['rs']->id]); } catch (Throwable $e) {} }
    try { if ($study && $study->valid) { $db->exec('DELETE FROM survey_study_metrics WHERE study_id = :s', ['s' => $study->id]); $study->delete(); } } catch (Throwable $e) {}
    try { if ($run && $run->id) { $run->delete(); } } catch (Throwable $e) {}
    foreach ($unitIds as $u) { try { $db->exec('DELETE FROM survey_units WHERE id = :id', ['id' => $u]); } catch (Throwable $e) {} }
    foreach ($files as $f) { @unlink($f); }
}

try {
    echo "== survey results smoke ==\n";
    ok($run->importUnits(json_encode($bundle)) !== false, 'run bundle imported');
    $study = SurveyStudy::loadByUserAndName($GLOBALS['user'], $studyName);
    $units = $run->getAllUnitIds();
    $unitIds = array_map('intval', array_column($units, 'unit_id'));
    // A bundle's Survey unit whose study is new is placed in the run (it was
    // created but left out once `run` became unassignable from props).
    ok(in_array((int) $study->id, $unitIds, true), 'the imported Survey unit is placed in the run', $units);
    $survey = RunUnitFactory::make($run, ['id' => (int) $study->id]);
    $study = $survey->surveyStudy;
    ok($survey instanceof Survey && $study && $study->valid && $study->rendering_mode !== 'v2', 'a v1 Survey unit and its study', $study ? $study->rendering_mode : null);

    // Four participants, answering through the real write path.
    $plan = [
        'A' => ['testing' => 0, 'answers' => ['txt' => 'alpha', 'num' => '0', 'mc1' => '0', 'mm' => ['1', '2'], 'long' => "line one\nline\ttwo"], 'end' => true],
        'B' => ['testing' => 0, 'answers' => ['txt' => 'beta'], 'end' => false],
        'C' => ['testing' => 1, 'answers' => ['txt' => 'gamma', 'num' => '7', 'mc1' => '2'], 'end' => true],
        'D' => ['testing' => 0, 'answers' => [], 'end' => false],
    ];
    foreach ($plan as $key => $p) {
        $code = substr('RS' . $suffix . $key . bin2hex(random_bytes(32)), 0, 64);
        $rsId = $db->insert('survey_run_sessions', ['run_id' => $runId, 'session' => $code, 'created' => mysql_now(), 'position' => 10, 'testing' => $p['testing']]);
        $rs = new RunSession($code, $run);
        $rs->position = 10;
        $rs->createUnitSession($survey);
        $us = $rs->currentUnitSession;
        $us->createSurveyStudyRecord();
        $sessions[$key] = ['rs' => $rs, 'us' => $us, 'code' => $code];
        // The incomplete filter reads "answered after it began" (created <>
        // modified): let B's answers land in a later second than its start.
        if ($key === 'B') { sleep(1); }
        if ($p['answers']) {
            ok($us->updateSurveyStudyRecord($p['answers']) !== false && !$us->errors, "{$key}: answers stored", $us->errors);
        }
        if ($p['end']) { $us->end(); }
    }
    $codes = array_map(function ($s) { return $s['code']; }, $sessions);
    $expected = [
        $codes['A'] => ['txt' => 'alpha', 'num' => '0', 'mc1' => '0', 'mm' => '1, 2', 'long' => "line one\nline\ttwo"],
        $codes['B'] => ['txt' => 'beta', 'num' => null, 'mc1' => null, 'mm' => null, 'long' => null],
        $codes['C'] => ['txt' => 'gamma', 'num' => '7', 'mc1' => '2', 'mm' => null, 'long' => null],
        $codes['D'] => ['txt' => null, 'num' => null, 'mc1' => null, 'mm' => null, 'long' => null],
    ];
    ksort($expected);

    echo "\n-- long read (a v1 study opted in: form_v2_long_reads on) --\n";
    setConfig('form_v2_long_reads', true);
    $study = SurveyStudy::loadById($study->id);
    $long = $study->getResults();
    ok(count($long) === 4, 'one row per unit session', count($long));
    $row = $long[0] ?? [];
    ok(array_keys($row) === array_merge(['session', 'session_id', 'iteration', 'created', 'modified', 'ended', 'expired'], $cols), 'metadata then one column per stored item (no note, no submit)', array_keys($row));
    ok(answers($long, $cols) === $expected, 'every answer as stored', answers($long, $cols));
    $byCode = array_column($long, null, 'session');
    ok($byCode[$codes['A']]['ended'] !== null && $byCode[$codes['C']]['ended'] !== null && $byCode[$codes['B']]['ended'] === null, 'ended set for the finished sessions only');
    ok((int) $byCode[$codes['A']]['session_id'] === (int) $sessions['A']['us']->id, 'session_id is the unit session');
    $iters = array_map('intval', array_column($long, 'iteration'));
    sort($iters);
    ok(count(array_unique($iters)) === 4 && min($iters) >= 1, 'each session has its own study iteration', $iters);
    $count = $study->getResultCountLive();
    ok(array_map('intval', $count) == ['finished' => 1, 'begun' => 2, 'testers' => 1, 'real_users' => 3], 'counts: 1 finished, 2 begun, 1 tester, 3 real users', $count);

    // The same filters, run id and pagination against both reads.
    $filters = function (string $mode) use ($study, $codes, $runId, $cols, $suffix) {
        $s = function ($rows) { $c = array_column($rows, 'session'); sort($c); return $c; };
        $ids = function (array $keys) use ($codes) { $c = array_map(function ($k) use ($codes) { return $codes[$k]; }, $keys); sort($c); return $c; };
        // LIKE's wildcards in a session filter are characters to match: every
        // code here shares the prefix 'RS<suffix>', none has a '_' after it
        // and none starts with '%'.
        $wild = 'RS' . $suffix . '_';
        ok($study->getResults(null, ['session' => $wild]) === [] && $study->getResults(null, ['session' => '%']) === [],
            "{$mode}: a session filter's % and _ are taken literally", $s($study->getResults(null, ['session' => $wild])));
        ok(array_sum(array_map('intval', $study->getResultCountLive(null, ['session' => $wild]))) === 0,
            "{$mode}: in the counts too", $study->getResultCountLive(null, ['session' => $wild]));
        ok($s($study->getResults(null, ['session' => substr($codes['A'], 0, 9)])) === $ids(['A']), "{$mode}: a session prefix filters to that session");
        ok($s($study->getResults(null, ['session' => $codes['C']])) === $ids(['C']), "{$mode}: an exact 64-character code filters to that session");
        ok($s($study->getResults(null, ['results' => 'complete'])) === $ids(['A', 'C']), "{$mode}: complete = the ended sessions", $s($study->getResults(null, ['results' => 'complete'])));
        ok($s($study->getResults(null, ['results' => 'incomplete'])) === $ids(['B', 'D']), "{$mode}: incomplete = open and answered later, or never answered", $s($study->getResults(null, ['results' => 'incomplete'])));
        ok(count($study->getResults(null, null, null, $runId)) === 4 && $study->getResults(null, null, null, $runId + 999999) === [], "{$mode}: a run id scopes the rows");
        $page = $study->getResults(null, null, ['offset' => 1, 'limit' => 2, 'order' => 'desc', 'order_by' => 'session_id']);
        ok(array_column($page, 'session') === [$codes['C'], $codes['B']], "{$mode}: page 2 of 2-per-page, newest first", array_column($page, 'session'));
        $only = $study->getResults(['txt', 'num']);
        ok(isset($only[0]) && array_key_exists('txt', $only[0]) && ($mode === 'wide' || !array_key_exists('mc1', $only[0])), "{$mode}: an item list narrows the columns", array_keys($only[0] ?? []));
    };
    $filters('long');
    ok($study->getResults(null, ['results' => 'bogus_saved_filter']) === [], 'long: an unknown saved filter returns nothing rather than wrong rows');

    echo "\n-- wide read (a v1 study, form_v2_long_reads off: the default) --\n";
    setConfig('form_v2_long_reads', false);
    $wide = $study->getResults();
    ok(count($wide) === 4 && array_key_exists('session', $wide[0]) && !array_key_exists('study_id', $wide[0]), 'one row per session from the wide table (study_id dropped)', array_keys($wide[0] ?? []));
    ok(answers($wide, $cols) === $expected, 'the same answers as the long read', answers($wide, $cols));
    $wideBy = array_column($wide, null, 'session');
    ok((int) $wideBy[$codes['A']]['iteration'] === (int) $byCode[$codes['A']]['iteration'] && ($wideBy[$codes['B']]['ended'] === null) === ($byCode[$codes['B']]['ended'] === null),
        'iteration and ended agree with the long read');
    $filters('wide');
    ok(array_map('intval', $study->getResultCountLive()) == array_map('intval', $count), 'the wide count agrees', $study->getResultCountLive());
    // The backup taken before results are deleted or a column is dropped
    // (SurveyStudy::backupResults → backupTSV(getResults())).
    foreach (['wide' => false, 'long' => true] as $mode => $flag) {
        setConfig('form_v2_long_reads', $flag);
        $bak = APPLICATION_ROOT . 'tmp/backups/results/ressmoke_' . $suffix . '_' . $mode . '.tab';
        $files[] = $bak;
        (new SpreadsheetReader())->backupTSV($study->getResults(), $bak);
        $bl = file($bak, FILE_IGNORE_NEW_LINES);
        $bh = explode("\t", $bl[0] ?? '');
        $bc = null;
        foreach ($bl as $l) { $f = explode("\t", $l); if ($f[0] === $codes['C']) { $bc = array_combine($bh, array_pad($f, count($bh), '')); } }
        // The wide read returns INT columns as ints, which fromArray() used to
        // leave blank (loose null comparison); the long read returns text.
        $cA = null;
        foreach ($bl as $l) { $f = explode("\t", $l); if ($f[0] === $codes['A']) { $cA = array_combine($bh, array_pad($f, count($bh), '')); } }
        ok($bc !== null && $bc['num'] === '7', "{$mode} backup: C's row is in it", $bc);
        ok($cA !== null && $cA['num'] === '0' && $cA['mc1'] === '0', "{$mode} backup: A's 0 answers are kept", $cA ? ['num' => $cA['num'], 'mc1' => $cA['mc1']] : null);
    }
    setConfig('form_v2_long_reads', true);

    echo "\n-- long-form readers: one row per item per session --\n";
    $itemsPerSession = (int) $db->execute('SELECT COUNT(*) AS n FROM survey_items_display WHERE session_id = :s', ['s' => $sessions['A']['us']->id], false, true)['n'];
    ok($itemsPerSession === 7, 'createSurveyStudyRecord scheduled all seven items', $itemsPerSession);
    foreach (['getItemDisplayResults', 'getResultsByItemsPerSession'] as $reader) {
        $rows = $study->$reader();
        ok(count($rows) === 4 * $itemsPerSession, "{$reader}: every item of every session", count($rows));
        $a = array_values(array_filter($rows, function ($r) use ($codes) { return $r['session'] === $codes['A']; }));
        ok(array_column($a, 'item_name') === ['intro', 'txt', 'num', 'mc1', 'mm', 'long', 'sub'], "{$reader}: in display order", array_column($a, 'item_name'));
        $ans = array_column($a, 'answer', 'item_name');
        ok($ans['txt'] === 'alpha' && $ans['num'] === '0' && $ans['mc1'] === '0' && $ans['mm'] === '1, 2' && $ans['long'] === "line one\nline\ttwo", "{$reader}: A's answers", $ans);
        ok((int) $a[0]['unit_session_id'] === (int) $sessions['A']['us']->id && $ans['intro'] === null, "{$reader}: keyed by unit session, notes unanswered");
        $d = array_filter($rows, function ($r) use ($codes) { return $r['session'] === $codes['D'] && $r['answer'] !== null; });
        ok($d === [], "{$reader}: D answered nothing");
        $txt = $study->$reader(['txt']);
        ok(count($txt) === 4 && array_unique(array_column($txt, 'item_name')) === ['txt'], "{$reader}: an item list narrows the rows", count($txt));
        $mine = $study->$reader(null, ['session' => substr($codes['B'], 0, 9)]);
        ok(count($mine) === $itemsPerSession && array_unique(array_column($mine, 'session')) === [$codes['B']], "{$reader}: the session filter", count($mine));
    }
    ok(count($study->getItemDisplayResults(null, null, ['offset' => 2, 'limit' => 3])) === 3, 'getItemDisplayResults pages by row');
    $page = $study->getResultsByItemsPerSession(null, null, ['offset' => 1, 'limit' => 2]);
    $pageSessions = array_values(array_unique(array_column($page, 'session')));
    ok($pageSessions === [$codes['B'], $codes['C']] && count($page) === 2 * $itemsPerSession, 'getResultsByItemsPerSession pages by session', $pageSessions);
    $complete = array_values(array_unique(array_column($study->getResultsByItemsPerSession(null, ['results' => 'complete']), 'session')));
    sort($complete);
    $want = [$codes['A'], $codes['C']]; sort($want);
    ok($complete === $want, 'getResultsByItemsPerSession: the complete filter', $complete);
    // Nothing matches: the admin page past the last page, or a filter that
    // finds no session.
    foreach (['a session filter that matches nothing' => [['session' => 'NOSUCHSESSION'], null], 'a page past the last one' => [null, ['offset' => 40, 'limit' => 20]]] as $what => $args) {
        try { $r = $study->getResultsByItemsPerSession(null, $args[0], $args[1]); $err = null; } catch (Throwable $e) { $r = null; $err = $e->getMessage(); }
        // No matching session must not build `IN ()` (show_itemdisplay?page=N
        // past the end was a 500).
        ok($r === [], "getResultsByItemsPerSession: {$what} returns no rows", $err);
    }

    echo "\n-- an unlinked study --\n";
    $study->unlinked = 1;
    $un = $study->getResults();
    ok(count($un) === 1 && array_keys($un[0]) === $cols, 'long: only the test session, and no session identity (fewer than 10 real users)', $un);
    ok(isset($un[0]) && $un[0]['txt'] === 'gamma', "long: it is C's row");
    setConfig('form_v2_long_reads', false);
    $un = $study->getResults();
    ok(count($un) === 1 && !array_key_exists('session', $un[0]) && !array_key_exists('session_id', $un[0]) && $un[0]['txt'] === 'gamma', 'wide: the same', $un);
    setConfig('form_v2_long_reads', true);
    ok($study->getItemDisplayResults() === [] && $study->getResultsByItemsPerSession() === [], 'the long-form readers return nothing');
    $study->unlinked = 0;

    echo "\n-- the downloads --\n";
    $dir = APPLICATION_ROOT . 'tmp/backups/results';
    // The run's export (AdminRunController::exportSurveyResultsAction).
    $tsv = $dir . '/' . $run->name . '-' . $study->name . '.tab';
    $files[] = $tsv;
    $SPR = new SpreadsheetReader();
    ok($SPR->exportTSV($study->getResults(null, null, null, $runId, true), $study->name, $tsv) === true, 'the run export writes its .tab file');
    // Read it as TSV (tab-delimited, a field quoted only when it holds a
    // tab, quote or newline): a row is a record, not a physical line.
    $records = [];
    if (($fh = fopen($tsv, 'r')) !== false) {
        while (($rec = fgetcsv($fh, 0, "\t", '"', '')) !== false) { $records[] = $rec; }
        fclose($fh);
    }
    $header = $records[0] ?? [];
    ok(array_slice($header, 0, 2) === ['session', 'session_id'] && array_slice($header, -5) === $cols, 'header: session metadata and the answer columns', $header);
    $tsvRows = [];
    foreach (array_slice($records, 1) as $f) { if (isset($f[0]) && isset($expected[$f[0]])) { $tsvRows[$f[0]] = array_combine($header, array_pad($f, count($header), '')); } }
    ok(count($tsvRows) >= 3 && $tsvRows[$codes['C']]['txt'] === 'gamma' && $tsvRows[$codes['C']]['num'] === '7', "C's row carries its answers", $tsvRows[$codes['C']] ?? null);
    // An answer holding a newline or a tab stays inside its row (A's
    // textarea answer here); it split the row when fields were never quoted.
    ok(count($records) === 5 && isset($tsvRows[$codes['A']]) && $tsvRows[$codes['A']]['long'] === "line one\nline\ttwo",
        'a multi-line answer stays inside its row', ['records' => count($records), 'A' => $tsvRows[$codes['A']] ?? null]);

    // The survey's results and item-display downloads (AdminSurveyController).
    $login = '$GLOBALS["user"] = new User(' . (int) $owner['id'] . '); $s = SurveyStudy::loadById(' . (int) $study->id . '); $spr = new SpreadsheetReader();';
    $csvPath = $dir . '/ressmoke_' . $suffix . '.csv';
    $files[] = $csvPath;
    download($login . '$spr->exportInRequestedFormat($s->getResults(null, null, null, null, true), $s->name, "csv");', $csvPath);
    $fh = fopen($csvPath, 'r');
    $csvHeader = fgetcsv($fh);
    $csv = [];
    while (($f = fgetcsv($fh)) !== false) { if ($csvHeader && count($f) === count($csvHeader)) { $csv[] = array_combine($csvHeader, $f); } }
    fclose($fh);
    $csvAnswers = array_map(function ($r) { return array_map(function ($v) { return $v === '' ? null : $v; }, $r); }, answers($csv, $cols));
    ok($csvAnswers === $expected, 'the CSV results download has every answer (a 0 included)', $csvAnswers);

    $jsonPath = $dir . '/ressmoke_' . $suffix . '.json';
    $files[] = $jsonPath;
    $files[] = APPLICATION_ROOT . 'tmp/downloads/' . $study->name . '.json';   // exportJSON's working copy
    $raw = download($login . '$spr->exportInRequestedFormat($s->getItemDisplayResults(null, null, null, true), $s->name, "json");', $jsonPath);
    // With use_xsendfile the web server sends exportJSON's working copy; the
    // body is empty then.
    if ($raw === '') { $raw = (string) @file_get_contents(APPLICATION_ROOT . 'tmp/downloads/' . $study->name . '.json'); }
    $json = json_decode($raw, true);
    ok(is_array($json) && count($json) === 4 * $itemsPerSession, 'the JSON item-display download has every row', is_array($json) ? count($json) : substr($raw, 0, 300));
    $aLong = array_values(array_filter((array) $json, function ($r) use ($codes) { return ($r['session'] ?? null) === $codes['A'] && ($r['item_name'] ?? null) === 'long'; }));
    ok(isset($aLong[0]) && $aLong[0]['answer'] === "line one\nline\ttwo", "and A's multi-line answer intact", $aLong[0] ?? null);
} catch (Throwable $e) {
    echo "  \e[31mEXCEPTION\e[0m " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
}
teardown();
$left = (int) $db->execute('SELECT COUNT(*) AS n FROM survey_studies WHERE name = :n', ['n' => $studyName], false, true)['n']
      + (int) $db->execute('SELECT COUNT(*) AS n FROM survey_runs WHERE id = :id', ['id' => $runId], false, true)['n']
      + (int) $db->execute('SELECT COUNT(*) AS n FROM survey_run_sessions WHERE run_id = :id', ['id' => $runId], false, true)['n']
      + ($unitIds ? (int) $db->execute('SELECT COUNT(*) AS n FROM survey_units WHERE id IN (' . implode(',', $unitIds) . ')', [], false, true)['n'] : 0);
$leftFiles = array_filter($files, 'file_exists');
ok($left === 0 && !$leftFiles, 'teardown removed the run, units, sessions, study and files', [$left, $leftFiles]);
echo "\n" . ($failures ? "\e[31m{$failures} failure(s)\e[0m" : "\e[32mall passed\e[0m") . "\n";
exit($failures ? 1 : 0);
