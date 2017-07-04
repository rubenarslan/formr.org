<?php

/* 
 * Encrypt Existing email accounts
 */

require_once dirname(__FILE__) . '/../setup.php';

function _e($str) {
	echo $str . "\n\n";
}

function encrypt_accounts($DB) {
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

encrypt_accounts(DB::getInstance());
