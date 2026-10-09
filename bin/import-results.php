#!/usr/bin/php
<?php
/**
 * Import a survey's exported results into a run.
 *
 * Reads a results file as the admin's export writes it (CSV, German CSV, TSV,
 * XLSX or XLS: a header row of `session`, the system columns and one column
 * per item, then one row per survey session) and stores each row as a session
 * of the survey at its place in the run, the way a participant's would be:
 *
 *   - the participant (`session`, their code) is found in the run, or added
 *     to it as an ended participant at the survey's position;
 *   - the survey session gets its study iteration from the study's counter,
 *     its display rows and its results row (UnitSession::
 *     createSurveyStudyRecord), then the row's answers and times: `created`,
 *     `modified` (when the answers were stored), `ended`, `expired`;
 *   - a row neither ended nor expired is an unfinished session that nobody
 *     resumes (queued -9, SUPERSEDED), so an import can never take over a
 *     participant's live session.
 *
 * The file is checked as a whole first; nothing is written when a column is
 * not an item of the survey, a code is not a participant code or a time is
 * not a time. Then every row is written in one transaction: all or nothing.
 * A row whose participant already has a session of the survey at that place
 * started at the same time is skipped, so a file can be imported again.
 * The study's first-visit allocations wait while the transaction runs.
 */
require_once dirname(__FILE__) . '/../setup.php';

const IMPORT_SYSTEM_COLUMNS = array('session', 'session_id', 'study_id', 'iteration', 'created', 'modified', 'ended', 'expired');

function import_help() {
    echo <<<'TXT'
Import a survey's exported results (CSV, TSV, XLSX, XLS) into a run.

Usage:
  php bin/import-results.php --run=<name|id> --survey=<name|id> --file=<path> [--position=<n>] [--dry-run]

Options:
  --run       the run (name or numeric id); --run-id also works
  --survey    the survey (name or numeric id), placed in the run; --survey-id also works
  --file      the results file, as the admin's export writes it; --backup-file also works
  --position  the survey's position in the run; needed only when it is placed more than once
  --dry-run   check the file and say what would be imported, write nothing
  -h, --help  this text

TXT;
    exit(0);
}

function import_fail($message) {
    fwrite(STDERR, "import-results: {$message}\n");
    exit(1);
}

/** The options, strictly: an unknown option or a missing value is an error. */
function import_options(array $argv) {
    $takesValue = array('run' => 'run', 'run-id' => 'run', 'survey' => 'survey', 'survey-id' => 'survey',
        'file' => 'file', 'backup-file' => 'file', 'position' => 'position');
    $opts = array('dry-run' => false);
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if ($arg === '-h' || $arg === '--help') {
            import_help();
        }
        if ($arg === '--dry-run') {
            $opts['dry-run'] = true;
            continue;
        }
        if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m) || !isset($takesValue[$m[1]])) {
            import_fail("unknown option '{$arg}' (see --help)");
        }
        $value = isset($m[2]) ? $m[2] : (isset($argv[$i + 1]) ? $argv[++$i] : null);
        if ($value === null || trim($value) === '') {
            import_fail("--{$m[1]} needs a value");
        }
        $opts[$takesValue[$m[1]]] = trim($value);
    }
    foreach (array('run', 'survey', 'file') as $required) {
        if (!isset($opts[$required])) {
            import_fail("--{$required} is required (see --help)");
        }
    }
    if (isset($opts['position']) && !preg_match('/^-?\d+$/', $opts['position'])) {
        import_fail('--position must be a number');
    }
    return $opts;
}

/**
 * A time as the export writes it ('2026-09-01 10:00:00'), or as a
 * spreadsheet program may have turned it into a date cell. Null for an empty
 * cell, false for one that is not a time.
 */
function import_datetime($value, $spreadsheet) {
    if ($value === null || (is_string($value) && trim($value) === '')) {
        return null;
    }
    if ($spreadsheet && (is_int($value) || is_float($value))) {
        return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)->format('Y-m-d H:i:s');
    }
    // The export's own format only: 01/09/2026 is a different day in
    // different countries.
    $value = trim((string) $value);
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $value, $m)
            || ($time = strtotime($value)) === false || date('Y-m-d', $time) !== $m[1]) {
        return false;
    }
    return date('Y-m-d H:i:s', $time);
}

/** An answer as stored: text, a number written without a float's noise, NULL for an empty cell. */
function import_answer($value) {
    if ($value === null || $value === '') {
        return null;
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_float($value) && floor($value) == $value && abs($value) < 1e15) {
        return (string) (int) $value;
    }
    return (string) $value;
}

/**
 * The file's rows, checked: per row its sheet line, participant code, times
 * and answers. Stops with every problem listed before anything is written.
 */
function import_read(array $opts, array $items, array $deleted) {
    $file = $opts['file'];
    if (!is_file($file) || !is_readable($file)) {
        import_fail("cannot read {$file}");
    }
    try {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader(\PhpOffice\PhpSpreadsheet\IOFactory::identify($file));
        $reader->setReadDataOnly(true);
        $rows = $reader->load($file)->getSheet(0)->toArray(null, false, false, false);
    } catch (Throwable $e) {
        import_fail("cannot read {$file}: " . $e->getMessage());
    }
    // A CSV cell is text; a spreadsheet's may be a date (import_datetime).
    $spreadsheet = !($reader instanceof \PhpOffice\PhpSpreadsheet\Reader\Csv);
    $header = array_map(function ($h) { return trim((string) $h); }, (array) array_shift($rows));
    while ($header && end($header) === '') {
        array_pop($header);
    }
    if (!in_array('session', $header, true)) {
        import_fail('the file has no `session` column (the participant code)');
    }
    if (($blank = array_search('', $header, true)) !== false) {
        import_fail('column ' . ($blank + 1) . ' has no name');
    }
    $unknown = array_diff($header, IMPORT_SYSTEM_COLUMNS, array_keys($items));
    if ($unknown) {
        $gone = array_intersect($unknown, $deleted);
        import_fail('not items of the survey: ' . implode(', ', $unknown)
            . ($gone ? ' (' . implode(', ', $gone) . ' deleted from it: leave those columns out)' : ''));
    }
    if (count($header) !== count(array_unique($header))) {
        import_fail('columns appear twice: ' . implode(', ', array_unique(array_diff_assoc($header, array_unique($header)))));
    }

    $codeRule = Config::get('user_code_regular_expression');
    $records = $errors = array();
    foreach ($rows as $i => $cells) {
        $line = $i + 2;
        $row = array();
        foreach ($header as $c => $name) {
            $row[$name] = isset($cells[$c]) ? $cells[$c] : null;
        }
        if (!array_filter($row, function ($v) { return $v !== null && trim((string) $v) !== ''; })) {
            continue;
        }
        $code = trim((string) $row['session']);
        if (!preg_match($codeRule, $code)) {
            $errors[] = "row {$line}: '{$code}' is not a participant code";
        }
        $times = array();
        foreach (array('created', 'modified', 'ended', 'expired') as $column) {
            $time = isset($row[$column]) ? import_datetime($row[$column], $spreadsheet) : null;
            if ($time === false) {
                $errors[] = "row {$line}: {$column} '{$row[$column]}' is not a time (YYYY-MM-DD HH:MM:SS)";
            }
            $times[$column] = $time ?: null;
        }
        $answers = array();
        foreach (array_intersect($header, array_keys($items)) as $name) {
            $answers[$name] = import_answer($row[$name]);
        }
        $records[] = array('line' => $line, 'code' => $code, 'times' => $times, 'answers' => $answers);
    }
    if ($errors) {
        import_fail("nothing was imported:\n  " . implode("\n  ", $errors));
    }
    return $records;
}

/**
 * Store one row as a session of the survey at $placement. False when the
 * participant already has a session there that started at the same time.
 *
 * @param array $added run session id => [first start, last activity] of the
 *   participants this import adds to the run
 */
function import_row(DB $db, Run $run, RunUnit $runUnit, SurveyStudy $study, array $placement, array $items, array $wideColumns, array $record, $source, array &$added) {
    $t = $record['times'];
    $start = $t['created'] ?: mysql_now();
    $runSession = new RunSession($record['code'], $run);
    if (!$runSession->id) {
        if (!$runSession->create($record['code']) || !$runSession->id) {
            throw new RuntimeException("could not add participant {$record['code']} to the run");
        }
        $added[$runSession->id] = array($start, $start);
    } elseif ($db->entry_exists('survey_unit_sessions', array('run_session_id' => $runSession->id, 'run_unit_id' => $placement['id'], 'created' => $start))) {
        return false;
    }

    $iteration = 1 + (int) $db->execute(
        'SELECT COALESCE(MAX(`iteration`), 0) FROM `survey_unit_sessions` WHERE `run_session_id` = :rs AND `run_unit_id` = :ru',
        array('rs' => $runSession->id, 'ru' => $placement['id']), true
    );
    $id = (int) $db->insert('survey_unit_sessions', array(
        'unit_id' => $study->id, 'run_unit_id' => $placement['id'], 'iteration' => $iteration,
        'run_session_id' => $runSession->id, 'created' => $start, 'queued' => 0,
        'state' => UnitSessionQueue::STATE_PENDING,
    ));
    // The study iteration, the display rows and the results row, as a
    // participant's first visit makes them.
    (new UnitSession($runSession, $runUnit, array('id' => $id, 'load' => true)))->createSurveyStudyRecord();

    $stored = $t['modified'] ?: ($t['ended'] ?: ($t['expired'] ?: $start));
    $db->update('survey_items_display', array('created' => $start), array('session_id' => $id));
    $display = array();
    foreach ($record['answers'] as $name => $answer) {
        if ($answer !== null) {
            $display[$items[$name]] = array('answer' => $answer);
        }
    }
    if ($display) {
        $db->batchUpdateByKey('survey_items_display', 'item_id', $display,
            array('saved' => $stored, 'displaycount' => 1, 'hidden' => 0), array('session_id' => $id));
    }
    if ($wideColumns) {
        $wide = array('created' => $start, 'modified' => $stored, 'ended' => $t['ended'], 'expired' => $t['ended'] ? null : $t['expired']);
        foreach ($record['answers'] as $name => $answer) {
            if (in_array($name, $wideColumns, true)) {
                $wide[$name] = $answer;
            }
        }
        $db->update($study->results_table, $wide, array('session_id' => $id, 'study_id' => $study->id));
    }

    // Its end. An unfinished session is one nobody resumes: the participant
    // may be live at this place in the run, and their current session is the
    // newest open one there (RunSession::getCurrentUnitSession).
    $log = 'imported from ' . $source;
    if ($t['ended']) {
        $db->update('survey_unit_sessions', array('ended' => $t['ended'], 'state' => UnitSessionQueue::STATE_ENDED,
            'result' => 'survey_ended', 'result_log' => $log), array('id' => $id));
        StudyMetrics::recordCompletion((int) $study->id, $runSession->testing, $id);
    } elseif ($t['expired']) {
        $db->update('survey_unit_sessions', array('expired' => $t['expired'], 'state' => UnitSessionQueue::STATE_EXPIRED,
            'result' => 'expired', 'result_log' => $log), array('id' => $id));
    } else {
        $db->update('survey_unit_sessions', array('queued' => UnitSessionQueue::QUEUED_SUPERCEDED,
            'state' => UnitSessionQueue::STATE_SUPERSEDED, 'result_log' => $log), array('id' => $id));
    }
    if (isset($added[$runSession->id])) {
        $last = $t['ended'] ?: ($t['expired'] ?: $stored);
        $added[$runSession->id] = array(min($added[$runSession->id][0], $start), max($added[$runSession->id][1], $last));
    }
    return true;
}

$opts = import_options($argv);
$db = DB::getInstance();

$run = ctype_digit($opts['run']) ? new Run(null, (int) $opts['run']) : new Run($opts['run']);
if (!$run->valid || $run->id <= 0) {
    import_fail("no run '{$opts['run']}'");
}
$study = ctype_digit($opts['survey'])
    ? SurveyStudy::loadById((int) $opts['survey'])
    : SurveyStudy::loadByUserAndName(new User((int) $run->user_id), $opts['survey']);
if (!$study->valid || !$study->id) {
    import_fail("no survey '{$opts['survey']}'" . (ctype_digit($opts['survey']) ? '' : " of the run's owner"));
}
$placements = $db->execute(
    'SELECT `id`, `position` FROM `survey_run_units` WHERE `run_id` = :run AND `unit_id` = :study ORDER BY `position`',
    array('run' => $run->id, 'study' => $study->id)
);
if (isset($opts['position'])) {
    $placements = array_values(array_filter($placements, function ($p) use ($opts) { return (int) $p['position'] === (int) $opts['position']; }));
}
if (count($placements) !== 1) {
    import_fail($placements
        ? "survey '{$study->name}' is placed in run '{$run->name}' more than once: name its --position (" . implode(', ', array_column($placements, 'position')) . ')'
        : "survey '{$study->name}' is not placed in run '{$run->name}'" . (isset($opts['position']) ? " at position {$opts['position']}" : ''));
}
$placement = array('id' => (int) $placements[0]['id'], 'position' => (int) $placements[0]['position']);
$runUnit = RunUnitFactory::make($run, array('id' => $study->id));
if (!($runUnit instanceof Survey) || !$runUnit->surveyStudy || (int) $runUnit->surveyStudy->id !== (int) $study->id) {
    import_fail("the unit at position {$placement['position']} is not survey '{$study->name}'");
}

$items = $deleted = array();
foreach ($study->getItems('id, name, deleted', null, true) as $item) {
    if ($item['deleted'] === null) {
        $items[$item['name']] = (int) $item['id'];
    } else {
        $deleted[] = $item['name'];
    }
}
$records = import_read($opts, $items, $deleted);
$where = "survey '{$study->name}' in run '{$run->name}' at position {$placement['position']}";

if ($opts['dry-run']) {
    $codes = array_unique(array_column($records, 'code'));
    $known = $codes ? count($db->select('id')->from('survey_run_sessions')
        ->where(array('run_id' => $run->id))->whereIn('session', $codes)->fetchAll()) : 0;
    echo 'Would import ' . count($records) . " row(s) of {$where}; " . (count($codes) - $known)
        . " participant(s) are not in the run yet and would be added. Nothing was written.\n";
    exit(0);
}

$wideColumns = Config::get('form_v2_dual_write_results', true) && $study->results_table
    ? SurveyStudy::wideTableColumns($study->results_table) : array();
$added = array();
$imported = $skipped = 0;
$line = null;
$db->beginTransaction();
try {
    foreach ($records as $record) {
        $line = $record['line'];
        import_row($db, $run, $runUnit, $study, $placement, $items, $wideColumns, $record, basename($opts['file']), $added) ? $imported++ : $skipped++;
    }
    $line = null;
    // The participants this import added: ended, at the survey, spanning
    // their sessions.
    foreach ($added as $runSessionId => $span) {
        $db->update('survey_run_sessions', array('created' => $span[0], 'ended' => $span[1], 'position' => $placement['position']),
            array('id' => $runSessionId));
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    formr_log_exception($e, 'import-results');
    import_fail(($line ? "row {$line}: " : '') . $e->getMessage() . ' — nothing was imported');
}
echo "Imported {$imported} session(s) of {$where}; " . count($added) . ' participant(s) added to the run'
    . ($skipped ? "; {$skipped} row(s) skipped, imported before" : '') . ".\n";
