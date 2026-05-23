<?php

use PHPMailer\PHPMailer\PHPMailer;

class Site {

    public $alerts = array();
    public $alert_types = array("alert-warning" => 0, "alert-success" => 0, "alert-info" => 0, "alert-danger" => 0);
    public $last_outside_referrer;

    /**
     * @var Request
     */
    public $request;

    /**
     * @var Site
     */
    protected static $instance = null;

    /**
     * @var string
     */
    protected $path;

    /**
     * @var RunSession[]
     */
    protected $runSessions = array();

    /**
     * Session key for the admin-context current run name. See setRun().
     */
    const ADMIN_RUN_NAME_KEY = 'current_admin_run_name';
    
    /**
     * 
     * @var array
     */
    protected static $settings = array();

    protected function __construct() {
        $this->updateRequestObject();
    }

    /**
     * @return Site
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function refresh() {
        $this->lastOutsideReferrer();
    }

    public function renderAlerts() {
        $now_handled = $this->alerts;
        $this->alerts = array();
        $this->alert_types = array("alert-warning" => 0, "alert-success" => 0, "alert-info" => 0, "alert-danger" => 0);
        return implode($now_handled);
    }

    public function updateRequestObject($path = null) {
        $this->request = new Request();
        $this->path = $path;
    }

    public function setPath($path) {
        $this->path = $path;
    }

    public function getPath() {
        return $this->path;
    }

    public function alert($msg, $class = 'alert-warning', $dismissable = true) {
        if (isset($this->alert_types[$class])): // count types of alerts
            $this->alert_types[$class] ++;
        else:
            $this->alert_types[$class] = 1;
        endif;
        if (is_array($msg)) {
            $msg = $msg['body'];
        }

        if ($class == 'alert-warning') {
            $class_logo = 'exclamation-triangle';
        } elseif ($class == 'alert-danger') {
            $class_logo = 'bolt';
        } elseif ($class == 'alert-info') {
            $class_logo = 'info-circle';
        } else { // if($class == 'alert-success')
            $class_logo = 'thumbs-up';
        }

        $msg = str_replace(APPLICATION_ROOT, '', $msg);
        $logo = '<i class="fa fa-' . $class_logo . '"></i> &nbsp;';
        $this->alerts[] = "<div class='alert $class'>" . $logo . '<button type="button" class="close" data-dismiss="alert">&times;</button>' . "$msg</div>";
    }

    public function inSuperAdminArea() {
        return strpos($this->path, 'admin/advanced') !== FALSE;
    }

    public function inAdminArea() {
        return strpos($this->path, 'admin/') !== FALSE;
    }

    public function inAdminRunArea() {
        return strpos($this->path, 'admin/run') !== FALSE;
    }

    public function inAdminSurveyArea() {
        return strpos($this->path, 'admin/survey') !== FALSE;
    }

    public function isFrontEndStudyArea() {
        return strpos($this->path, basename(RUNROOT) . '/') !== FALSE;
    }

    public function lastOutsideReferrer() {
        $ref = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        if (mb_strpos($ref, WEBROOT) !== 0) {
            $this->last_outside_referrer = $ref;
        }
    }

    public function makeAdminMailer() {
        $settings = Config::get('email');
        $mail = new PHPMailer();
        $mail->SetLanguage("de", "/");

        $mail->IsSMTP();  // telling the class to use SMTP
        $mail->Mailer = "smtp";
        $mail->Host = $settings['host'];
        $mail->Port = $settings['port'];
        if ($settings['tls']) {
            $mail->SMTPSecure = 'tls';
        } else {
            $mail->SMTPSecure = 'ssl';
        }
        if (isset($settings['username'])) {
            $mail->SMTPAuth = true; // turn on SMTP authentication
            $mail->Username = $settings['username']; // SMTP username
            $mail->Password = $settings['password']; // SMTP password
        } else {
            $mail->SMTPAuth = false;
            $mail->SMTPSecure = false;
        }
        $mail->From = $settings['from'];
        $mail->FromName = $settings['from_name'];
        $mail->AddReplyTo($settings['from'], $settings['from_name']);
        $mail->CharSet = "utf-8";
        $mail->WordWrap = 65; // set word wrap to 65 characters
        if (is_array($settings['smtp_options'])) {
            $mail->SMTPOptions = array_merge($mail->SMTPOptions, $settings['smtp_options']);
        }

        return $mail;
    }

    public function expire_session($expiry) {
        if (Session::isExpired($expiry)) {
            // last request was more than 30 minutes ago
            alert("You were logged out automatically, because you were last active " . timetostr(Session::get('last_activity')) . '.', 'alert-info');
            Session::destroy();
            return true;
        }
        return false;
    }

    public function makeTitle() {
        global $title;
        if ($title && trim($title)) {
            return $title;
        }

        $path = '';
        if (isset($_SERVER['REDIRECT_URL'])) {
            $path = $_SERVER['REDIRECT_URL'];
        } else if (isset($_SERVER['SCRIPT_NAME'])) {
            $path = $_SERVER['SCRIPT_NAME'];
        }

        $path = preg_replace(array(
            "@var/www/@",
            "@formr/@",
            "@webroot/@",
            "@\.php$@",
            "@index$@",
            "@^/@",
            "@/$@",
                ), "", $path);

        if ($path != ''):
            $title = "formr /" . $path;
            $title = str_replace(array('_', '/'), array(' ', ' / '), $title);
        endif;
        return isset($title) ? $title : 'formr survey framework';
    }

    public function getCurrentRoute() {
        return $this->request->getParam('route');
    }

    public function setRunSession(RunSession $runSession) {
        $session = $runSession->session;
        if ($session) {
            Session::set('current_run_session_code', $session);
            $id = md5($session);
            $this->runSessions[$id] = $runSession;
        }
    }

    public function getRunSession($session = null) {
        if ($session === null) {
            // if $id is null, get from current session
            $session = Session::get('current_run_session_code');
        }

        if (!$session) {
            return null;
        }

        $id = md5($session);
        return isset($this->runSessions[$id]) ? $this->runSessions[$id] : null;
    }

    /**
     * Mark a run as the admin's currently-active run. Stored in $_SESSION
     * (not on $this) because index.php and Site::getInstance() return
     * different Site instances within the same request — anything set on
     * the controller-threaded Site is invisible to globals-driven callers
     * like opencpu_prepare_api_access that go through Site::getInstance().
     * Mirrors how setRunSession persists `current_run_session_code`.
     */
    public function setRun(Run $run) {
        Session::set(self::ADMIN_RUN_NAME_KEY, $run->name);
    }

    /**
     * @return Run|null
     */
    public function getRun() {
        $name = Session::get(self::ADMIN_RUN_NAME_KEY);
        if (!$name) {
            return null;
        }
        $run = new Run($name);
        return $run->valid ? $run : null;
    }

    /**
     * @return DB
     */
    public static function getDb() {
        return DB::getInstance();
    }

    /**
     *
     * @global User $user
     * @return User
     */
    public static function getCurrentUser() {
        global $user;
        return $user;
    }

    /**
     * Get Site user from current Session
     *
     * @return User|null
     */
    public function getSessionUser() {
        $expiry = Config::get('expire_unregistered_session');
        $db = self::getDb();
        $user = null;

        if (($usr = Session::get('user'))) {
            $user = unserialize($usr, ['allowed_classes' => [User::class]]);
            // This segment basically checks whether the user-specific expiry time was met
            // If user session is expired, user is logged out and redirected
            if (!empty($user->id)) { // logged in user
                // refresh user object if not expired
                $expiry = Config::get('expire_registered_session');
                $user = new User($user->id, $user->user_code);
                // admins have a different expiry, can only be lower
                if ($user->isAdmin()) {
                    $expiry = Config::get('expire_admin_session');
                }
            } elseif (!empty($user->user_code)) { // visitor
                // refresh user object
                $user = new User(null, $user->user_code);
            }
        }

        if ($this->expire_session($expiry)) {
            $user = null;
        }

        if (empty($user->user_code)) {
            $user = new User(null, null);
        }

        return $user;
    }

    /**
     * @return \OAuth2\Server
     */
    public static function getOauthServer() {
        static $server;
        if ($server != null) {
            return $server;
        }

        OAuth2\Autoloader::register();

        // Share the app's PDO with the OAuth storage so a DB::beginTransaction()
        // on Site::getDb() actually covers the storage writes (setClientDetails,
        // setAccessToken, etc.). Constructing a second PDO from the same DSN —
        // the previous shape — produced an isolated connection, so the credential-
        // creation path couldn't be made atomic across the bshaffer storage
        // call and the surrounding $db->insert/$db->delete in OAuthHelper.
        // bshaffer's storage constructor accepts a PDO instance directly
        // (vendor/bshaffer/oauth2-server-php/src/OAuth2/Storage/Pdo.php:49)
        // and ATTR_ERRMODE / ATTR_EMULATE_PREPARES are already set to the
        // same values both layers expect, so the shared connection is safe.
        // HashedTokenOAuth2StoragePdo stores SHA-256 hashes of access/refresh tokens
        // and authorization codes so a DB read does not yield replayable bearer credentials.
        $storage = new HashedTokenOAuth2StoragePdo(self::getDb()->pdo());

        // Pass a storage object or array of storage objects to the OAuth2 server class.
        // access_lifetime is set explicitly so the external API contract
        // (R package, third-party clients via client_credentials) doesn't
        // silently drift if bshaffer changes its built-in default.
        // Internal short-lived tokens for the OpenCPU round-trip are
        // minted via OAuthHelper::createAccessTokenForUser with an
        // explicit 180s lifetime, matching the OpenCPU `timelimit.post`
        // ceiling — see opencpu_prepare_api_access.
        $server = new OAuth2\Server($storage, array(
            'access_lifetime' => 3600,
        ));

        // Add the "Client Credentials" grant type (it is the simplest of the grant types)
        $server->addGrantType(new OAuth2\GrantType\ClientCredentials($storage));

        // Add the "Authorization Code" grant type (this is where the oauth magic happens)
        $server->addGrantType(new OAuth2\GrantType\AuthorizationCode($storage));
        return $server;
    }
    
    public static function getSettings($setting = null, $default = null) {
        if (self::$settings) {
            return $setting !== null ? array_val(self::$settings, $setting, $default) : self::$settings;
        }

        $db = DB::getInstance();
        if ($setting !== null) {
            $value = trim($db->findValue('survey_settings', array('setting' => $setting), 'value'));
            return $value ? $value : $default;
        }

        $settings = array();
        $rows = $db->select('setting, value')
                ->from('survey_settings')
                ->fetchAll();
        foreach ($rows as $row) {
            $settings[$row['setting']] = $row['value'];
        }
        
        self::$settings = $settings;
        
        return $settings;
    }
    
    public static function runningInConsole() {
        return php_sapi_name() === "cli";
    }

}
