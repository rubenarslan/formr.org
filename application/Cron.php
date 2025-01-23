<?php

abstract class Cron {
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
    protected $name = 'Formr.Cron';
    protected $config = array();
    protected $params = array();
    protected $logfile;
    protected $lockfile;
    protected $start_datastamp;

    public function __construct(DB $db, Site $site, User $user, $config, $params) {
        $this->db = $db;
        $this->site = $site;
        $this->user = $user;
        $this->config = $config;
        $this->logfile = $params['logfile'] ?? null;
        $this->lockfile = $params['lockfile'];
        $this->start_datastamp = date('r');

        // Check if lock exists and exit if it does to avoid running similar process
        if ($this->lockExists()) {
            exit(1);
        }

        set_time_limit($this->config['ttl_cron'] ?? 3600);
        register_shutdown_function(array($this, 'cleanup'));

        // Register signal handlers that should be able to kill the cron in case some other weird stuff happens
        if (extension_loaded('pcntl')) {
            declare(ticks = 1);

            pcntl_signal(SIGINT, array(&$this, 'interrupt'));
            pcntl_signal(SIGTERM, array(&$this, 'interrupt'));
            pcntl_signal(SIGUSR1, array(&$this, 'interrupt'));
        } else {
            formr_log('pcntl extension is not loaded', 'CRON_ERROR');
        }
    }

    public function execute() {
        if (!$this->lock()) {
            formr_log('Unable to LOCK cron.. exiting', 'CRON_ERROR');
            exit(1);
        }

        /** Start */
        formr_log(".... {$this->name} started .... {$this->start_datastamp}", 'CRON_INFO');
        $start_time = microtime(true);

        /** Work */
        try {
            $this->process();
        } catch (Exception $e) {
            formr_log("Fatal error in {$this->name}: " . $e->getMessage(), 'CRON_ERROR');
        }

        /** End */
        $minutes = round((microtime(true) - $start_time) / 60, 3);
        $end_date = date('r');
        formr_log(".... Cron ended .... {$end_date}. Took ~{$minutes} minutes", 'CRON_INFO');

        $this->unLock();
        $this->cleanup();
    }

    /**
     * The main processing logic for the cron job
     */
    abstract protected function process(): void;

    public function cleanup() {
        $this->unLock();
    }

    protected function lock() {
        return file_put_contents($this->lockfile, $this->start_datastamp);
    }

    protected function unLock() {
        if (file_exists($this->lockfile)) {
            unlink($this->lockfile);
            formr_log(".... Cronfile cleanup complete", 'CRON_INFO');
        }
    }

    protected function lockExists($lockfile = null) {
        if ($lockfile === null) {
            $lockfile = $this->lockfile;
        }

        if (file_exists($lockfile)) {
            $started = file_get_contents($lockfile);
            formr_log("Cron overlapped. Started: $started, Overlapped: {$this->start_datastamp}", 'CRON_ERROR');

            // hack to delete $lockfile if cron hangs for more that 30 mins
            if ((strtotime($started) + ((int) ($this->config['ttl_lockfile'] ?? 1800) * 60)) < time()) {
                formr_log("Forced delete of {$lockfile}", 'CRON_INFO');
                unlink($lockfile);
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * Signal handler
     *
     * @param integer $signo
     */
    public function interrupt($signo) {
        switch ($signo) {
            case SIGINT:
            case SIGTERM:
                formr_log(sprintf("%s Received termination signal", getmypid()), 'CRON_ERROR');
                break;

            case SIGUSR1:
                break;
        }
    }
}
