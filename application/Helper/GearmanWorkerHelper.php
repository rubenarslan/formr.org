<?php

class GearmanWorkerHelper extends GearmanWorker {

	/**
	 *
	 * @var GearmanClient
	 */
	protected $gearmanClient;

	/**
	 *
	 * @var string
	 */
	protected $logFile;

	public function __construct() {
		parent::__construct();
		$servers = Config::get('deamon.gearman_servers');
		foreach ($servers as $server) {
			list($host, $port) = explode(':', $server, 2);
			$this->addServer($host, $port);
		}
		$this->logFile = get_log_file('deamon.log');
	}

	/**
	 * Will run jobs until $amount of jobs is processed.
	 * $timeout is used for to terminate if waiting for a new job takes too long
	 * Pass $timeout = 0 for no timeout
	 *
	 * @param int $amount Amount of jobs to process , Default: 1
	 * @param int $timeout Time (in seconds) to spend waiting for jobs. , Default: 0 - no timeout. will wait infinitely
	 */
	public function doJobs($amount = 1, $timeout = 0) {
		$amount = intval($amount);
		$timeout = intval($timeout);
		if ($amount == 0) {
			$this->dbg('Running 0 jobs, exiting.');
			exit(0);
		}
		$originalAmount = $amount;
		$this->setTimeout($timeout * 1000 + 100);
		$keepWorking = true;
		while ($keepWorking) {
			if ($this->returnCode() === GEARMAN_TIMEOUT) {
				$startTime = isset($startTime) ? $startTime : time();
			}
			$this->dbg('Attempting to get job');
			$this->work();

			if ($this->returnCode() === GEARMAN_TIMEOUT) {
				$startTime = isset($startTime) ? $startTime : time();
				if (time() - $startTime >= $timeout) {
					$this->dbg("Waited for a new job for $timeout seconds, giving up.");
					// @todo notify admin that worker is going offline and use exit code 90 (expected)
					exit(0);
				}
			} else if (in_array($this->returnCode(), array(GEARMAN_WORK_EXCEPTION, GEARMAN_SUCCESS, GEARMAN_WORK_FAIL))) {

				if(in_array($this->returnCode(), array(GEARMAN_WORK_EXCEPTION, GEARMAN_WORK_FAIL))) {
					$this->dbg('GearmanError: ' . $this->error());
				}

				$this->dbg('Job complete');
				$amount -= 1;
				if ($amount == 0) {
					$this->dbg("Completed $originalAmount jobs");
					$keepWorking = false;
					// sleep abit so that process can spawn again (notify admin)
					sleep(10);
				}

			}
		}
		$this->dbg('finished');
	}

	/**
	 * 
	 * @param boolean $refresh
	 * @return GearmanClient
	 */
	protected function getGearmanClient($refresh = false) {
		if ($this->gearmanClient === null || $refresh === true) {
			$client = new GearmanClient();
			$servers = Config::get('deamon.gearman_servers');
			foreach ($servers as $server) {
				list($host, $port) = explode(':', $server, 2);
				$client->addServer($host, $port);
			}
			$this->gearmanClient = $client;
		}
		return $this->gearmanClient;
	}

	/**
	 * Debug output
	 *
	 * @param string $str
	 * @param array $args
	 * @param Run $run
	 */
	protected function dbg($str, $args = array(), Run $run = null) {
		if ($run !== null && is_object($run)) {
			$logfile = get_log_file("cron/cron-run-{$run->name}.log");
		} else {
			$logfile = $this->logFile;
		}

		if (count($args) > 0) {
			$str = vsprintf($str, $args);
		}

		$str = join(" ", array(
			date('Y-m-d H:i:s'),
			get_class($this),
			getmypid(),
			$str,
			PHP_EOL
		));
		return error_log($str, 3, $logfile);
	}

	protected function cleanup(Run $run = null) {
		global $site;
		if ($site->alerts) {
			$this->dbg("\n<alerts>\n%s\n</alerts>", array($site->renderAlerts()), $run);
		}
	}

	/**
	 * Sets job return status and writes statistics.
	 *
	 * @param GearmanJob $job
	 * @param int $GEARMAN_JOB_STATUS
	 * @param Run $run
	 */
	protected function setJobReturn(GearmanJob $job, $GEARMAN_JOB_STATUS, Run $run = null) {
		$job->setReturn($GEARMAN_JOB_STATUS);
		$this->cleanup($run);
	}

	/**
	 * Send a GEARMAN_WORK_EXCEPTION status for a job and exit with error code 225
	 * (the sleeping is added so that processes like supervisor should not attempt to restart quickly)
	 *
	 * @param GearmanJob $job
	 * @param Exception $ex
	 * @param Run $run
	 */
	protected function sendJobException(GearmanJob $job, Exception $ex, Run $run = null) {
		$job->sendException($ex->getMessage() . PHP_EOL . $ex->getTraceAsString());
		$this->cleanup($run);
		// sleep abit so that process can spawn again (notify admin)
		sleep(4);
		exit(225);
	}

}

class RunWorkerHelper extends GearmanWorkerHelper {

	public function __construct() {
		parent::__construct();
		$this->addFunction('process_run', array($this, 'processRun'));
	}

	public function processRun(GearmanJob $job) {
		$r = null;
		try {
			$run = json_decode($job->workload(), true);
			if (empty($run['name'])) {
				$ex = new Exception("Missing parameters for job: " . $job->workload());
				return $this->sendJobException($job, $ex);
			}

			$r = new Run(DB::getInstance(), $run['name']);
			if (!$r->valid) {
				$ex = new Exception("Invalid Run {$run['name']}");
				return $this->sendJobException($job, $ex);
			}

			$this->dbg("Processing run >>> %s", array($run['name']), $r);
			$dues = $r->getCronDues();
			$i = 0;
			foreach ($dues as $session) {
				$data = array(
					'session' => $session,
					'run_name' => $run['name'],
					'run_id' => $run['id'],
				);
				$this->getGearmanClient()->doBackground('process_run_session', json_encode($data));
				$i++;
			}

			$this->dbg("%s sessions in the run '%s' were queued for processing", array($i, $run['name']), $r);
		} catch (Exception $e) {
			$this->dbg("Error: " . $e->getMessage() . PHP_EOL . $e->getTraceAsString());
			return $this->sendJobException($job, $e, $r);
		}

		$this->setJobReturn($job, GEARMAN_SUCCESS, $r);
	}
}

class RunSessionWorkerHelper extends GearmanWorkerHelper {

	public function __construct() {
		parent::__construct();
		$this->addFunction('process_run_session', array($this, 'processRunSession'));
	}

	public function processRunSession(GearmanJob $job) {
		$r = null;
		try {
			$session = json_decode($job->workload(), true);

			if (empty($session['session'])) {
				$ex = new Exception("Missing parameters for job: " . $job->workload());
				return $this->sendJobException($job, $ex);
			}

			$r = new Run(DB::getInstance(), $session['run_name']);
			if (!$r->valid) {
				$ex = new Exception("Invalid Run {$session['run_name']}");
				return $this->sendJobException($job, $ex);
			}
			$owner = $r->getOwner();
			//$this->dbg("Processing run session >>> %s > %s", array($session['run_name'], $session['session']), $r);

			$run_session = new RunSession(DB::getInstance(), $r->id, 'cron', $session['session'], $r);
			$types = $run_session->getUnit(); // start looping thru their units.
			if ($types === false) {
				$error = "This session '{$session['session']}' caused problems";
				alert($error, 'alert-danger');
				//throw new Exception($error);
			}
		} catch (Exception $e) {
			$this->dbg("Error: " . $e->getMessage() . PHP_EOL . $e->getTraceAsString());
			return $this->sendJobException($job, $e, $r);
		}
		// @todo. Echo types
		$this->setJobReturn($job, GEARMAN_SUCCESS, $r);
	}
}
