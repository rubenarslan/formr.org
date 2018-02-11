<?php

class External extends RunUnit {

	public $errors = array();
	public $id = null;
	public $session = null;
	public $unit = null;
	protected $address = null;
	protected $api_end = 0;
	protected $expire_after = 0;
	public $icon = "fa-external-link-square";
	public $type = "External";

	/**
	 * An array of unit's exportable attributes
	 * @var array
	 */
	public $export_attribs = array('type', 'description', 'position', 'special', 'address', 'api_end');

	public function __construct($fdb, $session = null, $unit = null, $run_session = NULL, $run = NULL) {
		parent::__construct($fdb, $session, $unit, $run_session, $run);

		if ($this->id):
			$vars = $this->dbh->findRow('survey_externals', array('id' => $this->id), 'id, address, api_end, expire_after');
			if ($vars):
				$this->address = $vars['address'];
				$this->api_end = $vars['api_end'] ? 1 : 0;
				$this->expire_after = (int) $vars['expire_after'];
				$this->valid = true;
			endif;
		endif;
	}

	public function create($options) {
		$this->dbh->beginTransaction();
		if (!$this->id) {
			$this->id = parent::create('External');
		} else {
			$this->modify($options);
		}

		if (isset($options['external_link'])) {
			$this->address = $options['external_link'];
			$this->api_end = $options['api_end'] ? 1 : 0;
			$this->expire_after = (int) $options['expire_after'];
		}

		$this->dbh->insert_update('survey_externals', array(
			'id' => $this->id,
			'address' => $this->address,
			'api_end' => $this->api_end,
			'expire_after' => $this->expire_after,
		));
		$this->dbh->commit();
		$this->valid = true;

		return true;
	}

	public function displayForRun($prepend = '') {
		$dialog = '<p><label>External link: <br>
			<textarea style="width:388px;"  data-editor="r" class="form-control full_width" rows="2" type="text" name="external_link">' . h($this->address) . '</textarea></label></p>
		<p><input type="hidden" name="api_end" value="0"><label><input type="checkbox" name="api_end" value="1"' . ($this->api_end ? ' checked ' : '') . '> end using <abbr class="initialism" title="Application programming interface. Better not check this if you don\'t know what it means">API</abbr></label></p>
		<p><label>Expire after <input type="number" style="width:80px" name="expire_after" class="form-control" value="'.$this->expire_after.'"> minutes</label></p>
		<p>Enter a URL like <code>http://example.org?code={{login_code}}</code> and the user will be sent to that URL, replacing <code>{{login_code}}</code> with that user\'s code. Enter R-code to e.g. send more data along: <code>paste0(\'http:example.org?code={{login_link}}&<br>age=\', demographics$age)</code>.</p>
		';

		$dialog .= '<p class="btn-group"><a class="btn btn-default unit_save" href="ajax_save_run_unit?type=External">Save</a>
		<a class="btn btn-default unit_test" href="ajax_test_unit?type=External">Test</a></p>';


		$dialog = $prepend . $dialog;

		return parent::runDialog($dialog, 'fa-external-link-square');
	}

	public function removeFromRun($special = null) {
		return $this->delete($special);
	}

	private function isR($address) {
		if (substr($address, 0, 4) == "http") {
			return false;
		}
		return true;
	}
	
	private function isAddress($address) {
		return ! $this->isR($address);
	}

	private function makeAddress($address) {
		$login_link = run_url($this->run_name, null, array('code' => urlencode($this->session)));
		$address = str_replace("{{login_link}}", $login_link, $address);
		$address = str_replace("{{login_code}}", $this->session, $address);
		return $address;
	}

	public function test() {
		if ($this->isR($this->address)) {
			if ($results = $this->getSampleSessions()) {
				if (!$results) {
					return false;
				}
				
				$this->run_session_id = current($results)['id'];

				$opencpu_vars = $this->getUserDataInRun($this->address);
				$ocpu_session = opencpu_evaluate($this->address, $opencpu_vars, '', null, true);
				$output = opencpu_debug($ocpu_session, null, 'text');
			} else {
				$output = '';
			}
		} else {
			$output = '<a href="'.$this->address.'">'.$this->address."</a>";
		}

		$this->session = "TESTCODE";
		echo $this->makeAddress($output);
	}

	private function hasExpired() {
		$expire = $this->expire_after;
		if ($expire === 0) {
			return false;
		} else {
			$last = $this->run_session->unit_session->created;
			if (!$last) {
				return false;
			}
			$query = 'SELECT :last <= DATE_SUB(NOW(), INTERVAL :expire_after MINUTE) AS no_longer_active';
			$params = array('last' => $last, 'expire_after' => $expire);
			return (bool)$this->dbh->execute($query, $params, true);
		}
	}

	public function exec() {
		// never redirect, if we're just in the cronjob. just text for expiry
		if ($this->called_by_cron) {
			if ($this->hasExpired()) {
				$this->expire();
				return false;
			}
		}

		// if it's the user, redirect them or do the call
		if ($this->isR($this->address)) {
			$goto = null;
			$opencpu_vars = $this->getUserDataInRun($this->address);
			$result = opencpu_evaluate($this->address, $opencpu_vars);

			if ($result=== null) {
				return true; // don't go anywhere, wait for the error to be fixed!
			}
			elseif($result === FALSE) {
				$this->end();
				return false; // go on, no redirect
			} 
			elseif($this->isAddress($result) ) {
				$goto = $result;
			}
		} else { // the simplest case, just an address
			$goto = $this->address;
		}
		
		// replace the code placeholder, if any
		$goto = $this->makeAddress($goto);
		
		// never redirect if we're just in the cron job
		if (!$this->called_by_cron) {
			// sometimes we aren't able to control the other end
			if (!$this->api_end) {
				$this->end();
			}
			
			redirect_to($goto);
			return false;
		}
		return true;
	}

}
