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
					// @todo notify admin that worker is going offline
					exit(90);
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
	 */
	protected function dbg($str) {
		//$this->logFile = get_log_file('errors.log');
		$args = func_get_args();
		if (count($args) > 1) {
			$str = vsprintf(array_shift($args), $args);
		}

		$str = join(" ", array(
			date('Y-m-d H:i:s'),
			get_class($this),
			getmypid(),
			$str,
			PHP_EOL
		));
		return error_log($str, 3, $this->logFile);
	}

	protected function cleanup() {
		global $site;
		if ($site->alerts) {
			$this->dbg("\n<alerts>\n%s\n</alerts>", $site->renderAlerts());
		}
	}

	/**
	 * Sets job return status and writes statistics.
	 *
	 * @param GearmanJob $job
	 * @param $GEARMAN_JOB_STATUS
	 */
	protected function setJobReturn(GearmanJob $job, $GEARMAN_JOB_STATUS) {
		$job->setReturn($GEARMAN_JOB_STATUS);
		$this->cleanup();
	}

}

class RunWorkerHelper extends GearmanWorkerHelper {

	public function __construct() {
		parent::__construct();
		$this->addFunction('process_run', array($this, 'processRun'));
	}

	public function processRun(GearmanJob $job) {
		try {
			$run = json_decode($job->workload(), true);
			if (empty($run['name'])) {
				$ex = new Exception("Missing parameters for job: " . $job->workload());
				$job->sendException($ex->getMessage() . PHP_EOL . $ex->getTraceAsString());
				throw $ex;
			}

			//$this->logFile = get_log_file("cron/cron-run-{$run['name']}.log");

			$r = new Run(DB::getInstance(), $run['name']);
			if (!$r->valid) {
				throw new Exception("Invalid Run {$run['name']}");
			}

			$this->dbg("Processing run >>> %s", $run['name']);
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

			$this->dbg("%s sessions in the run '%s' were queued for processing", $i, $run['name']);
		} catch (Exception $e) {
			$this->dbg("Error: " . $e->getMessage() . PHP_EOL . $e->getTraceAsString());
			$job->sendException($e->getMessage());
			$this->setJobReturn($job, GEARMAN_WORK_EXCEPTION);
			return;
		}

		$this->setJobReturn($job, GEARMAN_SUCCESS);
	}
}

class RunSessionWorkerHelper extends GearmanWorkerHelper {

	public function __construct() {
		parent::__construct();
		$this->addFunction('process_run_session', array($this, 'processRunSession'));
	}

	public function processRunSession(GearmanJob $job) {
		
		try {
			$session = json_decode($job->workload(), true);

			if (empty($session['session'])) {
				throw new Exception("Missing parameters for job: !" . $job->workload());
			}

			//$this->logFile = get_log_file("cron/cron-run-{$session['run_name']}.log");

			$r = new Run(DB::getInstance(), $session['run_name']);
			if (!$r->valid) {
				throw new Exception("Invalid Run {$session['run_name']}");
			}
			$owner = $r->getOwner();
			$this->dbg("Processing run session >>> %s > %s", $session['run_name'], $session['session']);

			$run_session = new RunSession(DB::getInstance(), $r->id, 'cron', $session['session'], $r);
			$types = true;//$run_session->getUnit(); // start looping thru their units.
			if (!$types) {
				$error = "This session '{$session['session']}' caused problems";
				alert($error, 'alert-danger');
				throw new Exception($error);
			}
		} catch (Exception $e) {
			$this->dbg("Error: " . $e->getMessage() . PHP_EOL . $e->getTraceAsString());
			$job->sendException($e->getMessage());
			$this->setJobReturn($job, GEARMAN_WORK_EXCEPTION);
			return;
		}
		// @todo. Echo types
		$this->setJobReturn($job, GEARMAN_SUCCESS);
	}
}
