<?php

class External extends RunUnit {

	public $errors = array();
	public $id = null;
	public $session = null;
	public $unit = null;
	private $address = null;
	private $api_end = 0;
	public $icon = "fa-external-link-square";
	public $type = "External";

	public function __construct($fdb, $session = null, $unit = null, $run_session = NULL, $run = NULL) {
		parent::__construct($fdb, $session, $unit, $run_session, $run);

		if ($this->id):
			$vars = $this->dbh->findRow('survey_externals', array('id' => $this->id), 'id, address, api_end');
			if ($vars):
				$this->address = $vars['address'];
				$this->api_end = $vars['api_end'] ? 1 : 0;
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

		if (isset($options['address'])) {
			$this->address = $options['address'];
			$this->api_end = $options['api_end'] ? 1 : 0;
		}

		$this->dbh->insert_update('survey_externals', array(
			'id' => $this->id,
			'address' => $this->address,
			'api_end' => $this->api_end,
		));
		$this->dbh->commit();
		$this->valid = true;

		return true;
	}

	public function displayForRun($prepend = '') {
		$dialog = '<p><label>Address: <br>
			<textarea style="width:388px;"  data-editor="r" class="form-control full_width" rows="2" type="text" name="address">' . h($this->address) . '</textarea></label></p>
		<p><input type="hidden" name="api_end" value="0"><label><input type="checkbox" name="api_end" value="1"' . ($this->api_end ? ' checked ' : '') . '> end using <abbr class="initialism" title="Application programming interface. Better not check this if you don\'t know what it means">API</abbr></label></p>
		<p>Enter a URL like <code>http://example.org?code={{login_code}}</code> and the user will be sent to that URL, replacing <code>{{login_code}}</code> with that user\'s code. Enter R-code to e.g. send more data along: <code>paste0(\'http:example.org?code={{login_link}}&<br>age=\', demographics$sex)</code>.</p>
		';

		$dialog .= '<p class="btn-group"><a class="btn btn-default unit_save" href="ajax_save_run_unit?type=External">Save.</a>
		<a class="btn btn-default unit_test" href="ajax_test_unit?type=External">Preview.</a></p>';


		$dialog = $prepend . $dialog;

		return parent::runDialog($dialog, 'fa-external-link-square');
	}

	public function removeFromRun() {
		return $this->delete();
	}

	private function isR() {
		if (substr($this->address, 0, 4) == "http") {
			return false;
		}
		return true;
	}

	private function makeAddress($address) {
		$login_link = site_url("{$this->run_name}?code=". urlencode($this->session));
		$address = str_replace("{{login_link}}", $login_link, $address);
		$address = str_replace("{{login_code}}", $this->session, $address);
		return $address;
	}

	public function test() {
		if ($this->isR()) {
			if ($results = $this->getSampleSessions()) {
				if (!$results) {
					return false;
				}
				
				$this->run_session_id = current($results)['id'];

				$opencpu_vars = $this->getUserDataInRun($this->dataNeeded($this->dbh, $this->address));
				$ocpu_session = opencpu_evaluate($this->address, $opencpu_vars, '', null, true);
				$output = opencpu_debug($ocpu_session);
			} else {
				$output = '';
			}
		} else {
			$output = '<a href="'.$this->address.'">'.$this->address."</a>";
		}

		$this->session = "TESTCODE";
		echo $this->makeAddress($output);
	}

	public function exec() {
		if ($this->called_by_cron) {
			return true; // never show to the cronjob
		}

		if ($this->isR()) {

			if ($this->address=== null) {
				return true; // don't go anywhere, wait for the error to be fixed!
			}
		}

		if($this->isR() AND $this->address === FALSE) {
			return false;
		}

		$this->address = $this->makeAddress($this->address);
		if (!$this->api_end) {
			$this->end();
		}

		redirect_to($this->address);
		return false;
	}

}
