#!/usr/bin/php
<?php
require_once dirname(__FILE__) . '/../setup.php';

function _e($str) {
    echo $str . "\n\n";
}

/**
 * Migrate existing special units to new table
 * 
 */
function migrateSpecialUnits() {
    $sql = "SELECT survey_runs.id, survey_runs.reminder_email, survey_runs.service_message, survey_runs.overview_script FROM survey_runs";
    $db = DB::getInstance();
    $stmt = $db->prepare($sql);
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['reminder_email'])) {
            $data = array(
                'id' => $row['reminder_email'],
                'run_id' => $row['id'],
                'type' => 'ReminderEmail',
                'description' => $db->findValue('survey_run_units', array('unit_id' => $row['reminder_email']), 'description'),
            );
            $db->insert_update('survey_run_special_units', $data);
        }

        if (!empty($row['service_message'])) {
            $data = array(
                'id' => $row['service_message'],
                'run_id' => $row['id'],
                'type' => 'ServiceMessagePage',
                'description' => $db->findValue('survey_run_units', array('unit_id' => $row['service_message']), 'description'),
            );
            $db->insert_update('survey_run_special_units', $data);
        }

        if (!empty($row['overview_script'])) {
            $data = array(
                'id' => $row['overview_script'],
                'run_id' => $row['id'],
                'type' => 'OverviewScriptPage',
                'description' => $db->findValue('survey_run_units', array('unit_id' => $row['overview_script']), 'description'),
            );
            $db->insert_update('survey_run_special_units', $data);
        }
    }
}

/**
 * Encrypt Existing email accounts
 *
 */
function encryptAccounts() {
    $DB = DB::getInstance();
    Crypto::setup();
    $accounts = $DB->select('id, username, password, auth_key')->from('survey_email_accounts')->fetchAll();
    foreach ($accounts as $account) {
        if (!$account['username'] || !$account['password']) {
            continue;
        }
        if ($account['auth_key']) {
            _e('ERROR: ' . $account['username'] . ' already has an auth key');
            continue;
        }

        $auth_key = Crypto::encrypt(array($account['username'], $account['password']), EmailAccount::AK_GLUE);
        if ($auth_key && $DB->update('survey_email_accounts', array('auth_key' => $auth_key), array('id' => (int) $account['id']))) {
            _e('SUCCESS: ' . $account['username'] . ' auth_key created');
        } else {
            _e('ERROR: ' . $account['username'] . ' auth_key NOT created (check logs)');
        }
    }
}

/**
 * Rename Runs by replacing underscore with hyphen
 * 
 */
function renameRuns() {
    $sql = "SELECT id, name FROM survey_runs";
    $db = DB::getInstance();
    $stmt = $db->prepare($sql);
    $stmt->execute();

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (strstr($row['name'], '_') === false) {
            continue;
        }

        $name = str_replace('_', '-', $row['name']);
        echo sprintf("\n Rename %s -> %s.", $row['name'], $name);
        $db->update('survey_runs', array('name' => $name), array('id' => (int) $row['id']));
    }
}

$opts = getopt('t:');
$task = !empty($opts['t']) ? $opts['t'] : null;

if ($task && function_exists($task)) {
    call_user_func($task);
    echo "\n DONE \n";
    exit(0);
} else {
    exit("\nInvalid Task '{$task}' \n");
}