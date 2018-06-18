<?php

/**
 * Queue Base Class
 *
 */
class Queue {

	/**
	 * 
	 * @var DB
	 */
	protected $db;

	/**
	 * Interval in seconds for which loop should be rested
	 *
	 * @var int
	 */
	protected $loopInterval;

	/**
	 * Flag used to exit or stay in run loop
	 *
	 * @var boolean
	 */
	protected $out = false;

	/**
	 * Number of times mailer is allowed to sleep before exiting
	 *
	 * @var int
	 */
	protected $allowedSleeps = 120;

	/**
	 * Number of seconds mailer should sleep before checking if there is something in queue
	 *
	 * @var int
	 */
	protected $sleep = 15;

	protected static $dbg;

	protected static $name = 'Formr-Queue';

	public function __construct(DB $db, array $config) {
		$this->db = $db;
		$this->loopInterval = array_val($config, 'queue_loop_interval', 5);

		// Register signal handlers that should be able to kill the cron in case some other weird shit happens 
		// apart from cron exiting cleanly
		// declare signal handlers
		if (extension_loaded('pcntl')) {
			declare(ticks = 1);

			pcntl_signal(SIGINT, array(&$this, 'interrupt'));
			pcntl_signal(SIGTERM, array(&$this, 'interrupt'));
			pcntl_signal(SIGUSR1, array(&$this, 'interrupt'));
		} else {
			self::$dbg = true;
			self::dbg('pcntl extension is not loaded');
		}
	}
	
	protected static function dbg($str) {
		$args = func_get_args();
		if (count($args) > 1) {
			$str = vsprintf(array_shift($args), $args);
		}

		$str = date('Y-m-d H:i:s') . ' '. self::$name .': ' . $str . PHP_EOL;
		if (DEBUG) {
			echo $str;
			return;
		}
		return error_log($str, 3, get_log_file('email-queue.log'));
	}

	protected function rested() {
		static $last_access;
		if (!is_null($last_access) && $this->loopInterval > ($usleep = (microtime(true) - $last_access))) {
			usleep(1000000 * ($this->loopInterval - $usleep));
		}

		$last_access = microtime(true);
		return true;
	}

	/**
	 * Signal handler
	 *
	 * @param integer $signo
	 */
	public function interrupt($signo) {
		switch ($signo) {
			// Set terminated flag to be able to terminate program securely
			// to prevent from terminating in the middle of the process
			// Use Ctrl+C to send interruption signal to a running program
			case SIGINT:
			case SIGTERM:
				$this->out = true;
				self::dbg("%s Received termination signal", getmypid());
				break;

			// switch the debug mode on/off
			// @example: $ kill -s SIGUSR1 <pid>
			case SIGUSR1:
				if ((self::$dbg = !self::$dbg)) {
					self::dbg("\nEntering debug mode...\n");
				} else {
					self::dbg("\nLeaving debug mode...\n");
				}
				break;
		}
	}
}
