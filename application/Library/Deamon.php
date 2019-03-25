<?php

/**
 * Deamon class that processes runs and run-sessions in background
 *
 */
class Deamon {

    /**
     * Database handle
     *
     * @var DB
     */
    protected $db;

    /**
     *
     * @var string
     */
    protected $lockFile;

    /**
     * Number of seconds to expire before run is fetched from DB for processing
     *
     * @var int
     */
    protected $runExpireTime;

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
     *
     * @var GearmanClient
     */
    protected $gearmanClient = null;
    public static $dbg = false;

    public function __construct(DB $db) {
        $this->db = $db;
        $this->lockFile = APPLICATION_ROOT . 'tmp/deamon.lock';
        $this->runExpireTime = Config::get('deamon.run_expire_time', 10 * 60);
        $this->loopInterval = Config::get('deamon.loop_interval', 60);

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

    public function run() {
        $runExpireTime = $time = $id = 0;
        $fetchStmt = $this->db->prepare('select id, name, last_deamon_access from survey_runs where last_deamon_access < :run_expiration_time and cron_active = 1 order by rand()');
        $fetchStmt->bindParam(':run_expiration_time', $runExpireTime, PDO::PARAM_INT);

        $updateStmt = $this->db->prepare('update survey_runs set last_deamon_access = :time where id = :id');
        $updateStmt->bindParam(':time', $time);
        $updateStmt->bindParam(':id', $id);

        // loop forever until terminated by SIGINT
        while (!$this->out) {
            try {
                file_put_contents($this->lockFile, date('r'));
                // loop until terminated but with taking some nap
                while (!$this->out && $this->rested()) {

                    $runExpireTime = time() - $this->runExpireTime;
                    $fetchStmt->execute();
                    $runs = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);
                    $gearmanClient = $this->getGearmanClient();

                    if (!$runs) {
                        self::dbg('No runs to be processed at this time.. waiting to retry');
                        sleep(10);
                        continue;
                    }

                    foreach ($runs as $run) {
                        $time = time();
                        $id = $run['id'];
                        self::dbg("Process run '%s'. Last access: %s", $run['name'], date('r', $run['last_deamon_access']));
                        $updateStmt->execute();
                        $gearmanClient->doHighBackground('process_run', json_encode($run), $run['name']);
                    }
                }
            } catch (Exception $e) {
                // if connection disappeared - try to restore it
                $error_code = $e->getCode();
                if ($error_code != 1053 && $error_code != 2006 && $error_code != 2013 && $error_code != 2003) {
                    throw $e;
                }

                self::dbg($e->getMessage() . "[" . $error_code . "]");

                self::dbg("Unable to connect. waiting 5 seconds before reconnect.");
                sleep(5);
            }
        }

        echo getmypid(), " Terminating...\n";
    }

    public function cronize() {
        $time = $id = null;
        $fetchStmt = $this->db->prepare('select id, name, last_deamon_access from survey_runs where cron_active = 1 order by rand()');
        $updateStmt = $this->db->prepare('update survey_runs set last_deamon_access = :time where id = :id');
        $updateStmt->bindParam(':time', $time);
        $updateStmt->bindParam(':id', $id);

        $fetchStmt->execute();
        $runs = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$runs) {
            self::dbg('No runs to be processed at this time.. waiting to retry');
            return;
        }

        $gearmanClient = $this->getGearmanClient();
        foreach ($runs as $run) {
            $time = time();
            $id = $run['id'];
            self::dbg("Process run [cr.d] '%s'. Last access: %s", $run['name'], date('r', $run['last_deamon_access']));
            $updateStmt->execute();
            $gearmanClient->doHighBackground('process_run', json_encode($run), $run['name']);
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
                $this->out = true;
                self::dbg("%s Received termination signal", getmypid());
                $this->cleanup('SIGINT|SIGTERM');
                break;

            // switch the debug mode on/off
            // @example: $ kill -s SIGUSR1 <pid>
            case SIGUSR1:
                if ((self::$dbg = !self::$dbg)) {
                    self::dbg("\nEntering debug mode...\n");
                } else {
                    self::dbg("\nLeaving debug mode...\n");
                }
                $this->cleanup('SIGUSR1');
                break;
        }
    }

    protected function cleanup($interrupt = null) {
        if (file_exists($this->lockFile)) {
            unlink($this->lockFile);
            self::dbg("Lockfile cleanup complete");
        }
    }

    private function rested() {
        static $last_access;
        if (!is_null($last_access) && $this->loopInterval > ($usleep = (microtime(true) - $last_access))) {
            usleep(1000000 * ($this->loopInterval - $usleep));
        }

        $last_access = microtime(true);
        return true;
    }

    /**
     * 
     * @param boolean $refresh
     * @return \MHlavac\Gearman\Client
     */
    private function getGearmanClient($refresh = false) {
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
    private static function dbg($str) {
        $args = func_get_args();
        if (count($args) > 1) {
            $str = vsprintf(array_shift($args), $args);
        }

        $str = date('Y-m-d H:i:s') . ' ' . $str . PHP_EOL;
        return error_log($str, 3, get_log_file('deamon.log'));
    }

}
