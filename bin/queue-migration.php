#!/usr/bin/php
<?php
require_once dirname(__FILE__) . '/../setup.php';

function update_unit_sessions_table(DB $db) {
    $query = "SELECT unit_session_id, run_session_id, created, run, expires, execute FROM survey_sessions_queue";
    
    $stmt = $db->query($query, true);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // foreach item in the old sessions_queue table,
        // update the expires timestamp in the unit_sessions table
        $db->update('survey_unit_sessions', array(
            'expires' => mysql_datetime($row['expires']),
            'queued' => $row['execute'] ? UnitSessionQueue::QUEUED_TO_EXECUTE : UnitSessionQueue::QUEUED_TO_END,
        ), array(
            'id' => $row['unit_session_id']
        ));
    }
}


function run_stuck_pauses(DB $db) {
    $query = "SELECT survey_unit_sessions.id as unit_session_id, unit_id, run_session_id, expires, created, ended, expired, type, survey_run_sessions.run_id 
              FROM survey_unit_sessions
              LEFT JOIN survey_run_sessions ON survey_run_sessions.id = survey_unit_sessions.run_session_id 
              LEFT JOIN survey_units ON survey_units.id = survey_unit_sessions.unit_id 
              WHERE survey_units.type='PAUSE' AND (survey_unit_sessions.ended IS NULL OR survey_unit_sessions.expires <= :now)
              ";
    
    $stmt = $db->rquery($query, array('now' => mysql_datetime()));
    while ($session = $stmt->fetch(PDO::FETCH_ASSOC)) {
        
        $run = new Run($db, null, $session['run_id']);
        
        $runSession = new RunSession($db, $run->id, 'cron', $session['session'], $run);
        $unitSession = new UnitSession($db, $session['run_session_id'], $session['unit_id'], $session['unit_session_id'], false);
        
        $runSession->execute($unitSession, true);
    }
}

$opts = getopt('m:');
if ($opts['m'] === 'update_unit_sessions_table') {
    update_unit_sessions_table(DB::getInstance());
} elseif ($opts['m'] === 'run_stuck_pauses') {
    run_stuck_pauses(DB::getInstance());
}
