<?php

class Cron {

    /**
     * @var DB
     */
    protected $db;

    /**
     * @var Site
     */
    protected $site;

    /**
     * @var User
     */
    protected $user;
    protected $debug = false;
    protected $name = 'Formr.Cron';
    protected $config = array();
    protected $params = array();
    protected $lockfile;
    protected $logfile;
    protected $process_run;
    protected $start_datastamp;

    public function __construct(DB $db, Site $site, User $user, $config, $params) {
        $this->db = $db;
        $this->site = $site;
        $this->user = $user;
        $this->config = $config;
        $this->lockfile = $params['lockfile'];
        $this->logfile = $params['logfile'];
        $this->process_run = $params['process_run'];

        $this->start_datastamp = date('r');

        $this->setUp();
    }

    protected function setUp() {
        // Check if lock exists and exit if it does to avoid running similar process
        if ($this->lockExists()) {
            exit(1);
        }

        set_time_limit($this->config['ttl_cron']);
        register_shutdown_function(array($this, 'cleanup'));

        // Register signal handlers that should be able to kill the cron in case some other weird stuff happens 
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

    public function execute() {
        if (!$this->lock()) {
            $this->dbg('Unable to LOCK cron.. exiting');
            exit(1);
        }
        
        /** Do the Work */
        $this->dbg(".... Cron started .... {$this->start_datastamp}");
        $start_time = microtime(true);

        if ($this->process_run) {
            $this->executeRun($this->process_run);
        } else {
            $this->executeList();
        }

        $minutes = round((microtime(true) - $start_time) / 60, 3);
        $end_date = date('r');
        $this->dbg(".... Cron ended .... {$end_date}. Took ~{$minutes} minutes");

        $this->unLock();
        $this->cleanup();
    }

    protected function executeList() {
        try {
            // Get all runs
            $runs = $this->db->select('name')->from('survey_runs')->where('cron_active = 1')->order('cron_fork', 'DESC')->fetchAll();

            foreach ($runs as $run_data) {
                $run = new Run($this->db, $run_data['name']);
                if (!$run->valid) {
                    alert("This run '{$run_data['name']}' caused problems", 'alert-danger');
                    continue;
                }

                // If run is locked, do not process it
                $lockfile = APPLICATION_ROOT . "tmp/cron-{$run->name}.lock";
                if ($this->lockExists($lockfile)) {
                    // log in cron run file
                    continue;
                }

                $script = APPLICATION_ROOT . 'bin/cron.php';
                $stdout = get_log_file("cron/cron-run-{$run->name}.log");
                $command = PHP_BINARY . " $script -n {$run->name} >> {$stdout} 2>&1 &";
                $this->dbg("Execute Command Run: '{$command}'");
                exec($command, $output, $status);
                if ($status != 0) {
                    $this->dbg("Command '{$command}' exited with status {$status}. Output: " . print_r($output, 1));
                }
            }
        } catch (Exception $e) {
            error_log('Cron [Exception]: ' . $e->getMessage());
            error_log('Cron [Exception]: ' . $e->getTraceAsString());
        }
    }

    protected function executeRun(Run $run) {
        $this->dbg('----------');
        $this->dbg("cron-run call start for {$run->name}");

        // get all session codes that have Branch, Pause, or Email lined up (not ended)
        $sessions = $this->db->select('session')->from('survey_run_sessions')
                ->where(array('run_id' => $run->id))
                ->order('RAND')
                ->statement();

        $done = array();
        $i = 0;
        // Foreach session, execute all units
        $run->getOwner();
        while ($row = $sessions->fetch(PDO::FETCH_ASSOC)) {
            $session = $row['session'];
            $runSession = new RunSession(DB::getInstance(), $run->id, 'cron', $session, $run);
            $types = $runSession->getUnit(); // start looping thru their units.
            $i++;

            if ($types === false) {
                alert("This session '$session' caused problems", 'alert-danger');
                continue;
            }

            foreach ($types as $type => $nr) {
                if (!isset($done[$type])) {
                    $done[$type] = 0;
                }
                $done[$type] += $nr;
            }
        }

        $executed_types = $this->parseExecutedUnitTypes($done);

        $msg = "$i sessions in the run " . $run->name . " were processed. {$executed_types}";
        $this->dbg($msg);
        if (Site::getInstance()->alerts) {
            $this->dbg("\n<alerts>\n" . Site::getInstance()->renderAlerts() . "\n</alerts>");
        }

        // log execution time
        $this->dbg("cron-run call end for {$run->name}");
        return true;
    }

    public function cleanup() {
        $this->unLock();
    }

    protected function parseExecutedUnitTypes($types) {
        $str = '';
        foreach ($types as $key => $value) {
            $str .= " {$value} {$key}s,";
        }
        return $str;
    }

    protected function lock() {
        return file_put_contents($this->lockfile, $this->start_datastamp);
    }

    protected function unLock() {
        if (file_exists($this->lockfile)) {
            unlink($this->lockfile);
            $this->dbg(".... Cronfile cleanup complete");
        }
    }

    protected function lockExists($lockfile = null) {
        if ($lockfile === null) {
            $lockfile = $this->lockfile;
        }

        if (file_exists($lockfile)) {
            $started = file_get_contents($lockfile);
            $this->dbg("Cron overlapped. Started: $started, Overlapped: {$this->start_datastamp}");

            // hack to delete $lockfile if cron hangs for more that 30 mins
            if ((strtotime($started) + ((int) $this->config['ttl_lockfile'] * 60)) < time()) {
                $this->dbg("Forced delete of {$lockfile}");
                unlink($lockfile);
                return false;
            }
            return true;
        } else {
            return false;
        }
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
                $this->dbg("%s Received termination signal", getmypid());
                break;

            case SIGUSR1:
                break;
        }
    }

    protected function dbg($message) {
        $message = date('Y-m-d H:i:s') . ' ' . $message . "\n";
        if ($this->logfile) {
            return error_log($message, 3, $this->logfile);
        }
        // else echo to STDOUT instead
        echo $message;
    }

}
