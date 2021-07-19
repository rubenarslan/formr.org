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
    $query = "UPDATE survey_unit_sessions
              LEFT JOIN survey_units ON survey_units.id = survey_unit_sessions.unit_id 
              SET `survey_unit_sessions`.queued = 2, `survey_unit_sessions`.expires = :now
    WHERE survey_units.type IN('Pause', 'Wait') AND 
    survey_unit_sessions.ended IS NULL AND survey_unit_sessions.expires IS NULL;";
    
    $stmt = $db->exec($query, array('now' => mysql_datetime()));
}

$opts = getopt('m:');
if ($opts['m'] === 'update_unit_sessions_table') {
    update_unit_sessions_table(DB::getInstance());
} elseif ($opts['m'] === 'run_stuck_pauses') {
    run_stuck_pauses(DB::getInstance());
}
