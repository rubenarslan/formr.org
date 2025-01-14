<?php

class RunExpiresOnCron {

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
    protected $logfile;
    protected $lockfile;
    protected $start_datastamp;

    public function __construct(DB $db, Site $site, User $user, $config, $params) {
        $this->db = $db;
        $this->site = $site;
        $this->user = $user;
        $this->config = $config;
        $this->logfile = $params['logfile'];
        $this->lockfile = $params['lockfile'];
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

        /** Start */
        $this->dbg(".... RunExpiresOnCron started .... {$this->start_datastamp}");
        $start_time = microtime(true);

        /** Work */
        $namesOfExpiredRuns = $this->getExpiredRuns();
        $this->deleteRuns($namesOfExpiredRuns);
        $expiredInOneWeek = $this -> getRunsExpiredInOneWeek();
        foreach ($expiredInOneWeek as $row){
            $run = new Run($row['name']);
            $email = $run->getOwner()->getEmail();
            $this->sendReminder($run, $email);
        }

        /** End */
        $minutes = round((microtime(true) - $start_time) / 60, 3);
        $end_date = date('r');
        $this->dbg(".... Cron ended .... {$end_date}. Took ~{$minutes} minutes");

        $this->unLock();
        $this->cleanup();
    }

    private function getExpiredRuns(){
        return $this->db->select('name')
            ->from('survey_runs')
            ->where('expiresOn < NOW()')
            ->fetchAll();
    }
    private function sendDeleteNotification(Run $run, string $email){
        $mail = $this->site->makeAdminMailer();
        $mail->AddAddress($email);
        $mail->Subject = "formr: Run {$run->name} automatically deleted";
        $mail->Body = Template::get_replace('email/auto-delete-notification.ftpl', array(
            'user' => $run->getOwner()->user_code,
            'title' => $run->title,
            'expiryDate' => $run->expiresOn,
        ));
        if (!$mail->Send()) {
            $this->dbg("Error: ". $mail->ErrorInfo);
        }else{
            $this->dbg("The Delete-Notification for the Run {$run->name} was send to {$email}");
        }
    }
    private function deleteRuns($namesOfRuns): void
    {
        foreach ($namesOfRuns as $runName){
            $run = new Run($runName['name']);
            $email = $run->getOwner()->getEmail();
            $run->emptySelf();
            $this->dbg("Deleted All Data in Run {$run->name} due to expiration");
            $this->sendDeleteNotification($run,$email);
        }
    }
    private function getRunsExpiredInOneWeek(){
        return $this->db->select('name')
            ->from('survey_runs')
            ->where('expiresOn < NOW() + INTERVAL 1 WEEK')
            ->fetchAll();
    }
    private function sendReminder(Run $run, string $email){
        $owner = $run->getOwner();
        $mail = $this->site->makeAdminMailer();
        $mail->AddAddress($email);
        $mail->Subject = "formr: Reminder! Run {$run->name} will be deleted!";
        $mail->Body = Template::get_replace('email/auto-delete-reminder.ftpl', array(
            'user' => $owner->first_name . " " . $owner->last_name,
            'title' => $run->title,
            'expiryDate' => $run->expiresOn,
        ));
        if (!$mail->Send()) {
            $this->dbg("Error: ". $mail->ErrorInfo);
        }else{
            $this->dbg("A Reminder for the Run {$run->name} was send to {$email}");
        }
    }
    public function cleanup() {
        $this->unLock();
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