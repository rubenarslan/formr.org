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
    protected $logFile = 'queue.log';
    protected $name = 'Formr-Queue';

    /**
     * Configuration passed to queue
     *
     * @var array
     */
    protected $config = array();
    protected $debug = false;
    protected $num_processes = 1;
    protected $process_num = 1;
    protected $offset = 0;
    protected $limit;
    protected $items_per_process;

    public function __construct(DB $db, array $config) {
        $this->db = $db;
        $this->config = $config;
        $this->loopInterval = array_val($this->config, 'queue_loop_interval', 5);
        $this->debug = array_val($this->config, 'debug', false);
        $this->num_processes = array_val($this->config, 'total_processes', 1);
        $this->process_num = array_val($this->config, 'process_number', 1);
        $this->items_per_process = array_val($this->config, 'items_per_process', 100);

        $this->setOffsetLimit();
        // Register signal handlers that should be able to kill the cron in case some other weird shit happens 
        // apart from cron exiting cleanly
        // declare signal handlers
        if (extension_loaded('pcntl')) {
            declare(ticks = 1);

            pcntl_signal(SIGINT, array(&$this, 'interrupt'));
            pcntl_signal(SIGTERM, array(&$this, 'interrupt'));
            pcntl_signal(SIGUSR1, array(&$this, 'interrupt'));
        } else {
            $this->debug = true;
            $this->dbg('pcntl extension is not loaded');
        }
    }

    public function run() {
        return true;
    }

    protected function dbg($str) {
        $args = func_get_args();
        if (count($args) > 1) {
            $str = vsprintf(array_shift($args), $args);
        }

        $str = date('Y-m-d H:i:s') . ' ' . $this->name . ': ' . $str . PHP_EOL;
        if (DEBUG) {
            echo $str;
            return;
        }
        return error_log($str, 3, get_log_file($this->logFile));
    }

    protected function rested() {
        static $last_access;
        if (!is_null($last_access) && $this->loopInterval > ($usleep = (microtime(true) - $last_access))) {
            usleep(1000000 * ($this->loopInterval - $usleep));
        }

        $last_access = microtime(true);
        return true;
    }

    protected function hasMultipleProcesses() {
        return $this->num_processes > 1;
    }

    protected function setOffsetLimit() {
        $this->offset = ($this->process_num - 1) * $this->items_per_process;
        $this->limit = $this->offset + $this->items_per_process;
        if ($this->process_num == $this->num_processes) {
            //for the last process, set an everlasting limit
            $this->limit = PHP_INT_MAX;
        }

        $this->dbg(
            "Setting queue process(%d of %d / %d items) with OFFSET %s and LIMIT %s", 
            $this->process_num, $this->num_processes, $this->items_per_process, $this->offset, $this->limit
       );
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
                $this->dbg("%s Received termination signal", getmypid());
                break;

            // switch the debug mode on/off
            // @example: $ kill -s SIGUSR1 <pid>
            case SIGUSR1:
                if (($this->debug = !$this->debug)) {
                    $this->dbg("\nEntering debug mode...\n");
                } else {
                    $this->dbg("\nLeaving debug mode...\n");
                }
                break;
        }
    }

}
