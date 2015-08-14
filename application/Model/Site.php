<?php

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

        $logo = '<i class="fa fa-' . $class_logo . '"></i>';
        $this->alerts[] = "<div class='alert $class'>" . $logo . '<button type="button" class="close" data-dismiss="alert">&times;</button>' . "$msg</div>";
    }

    public function inSuperAdminArea() {
        return strpos($this->path, 'superadmin/') !== FALSE;
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
        global $settings;
        $mail = new PHPMailer();
        $mail->SetLanguage("de", "/");

        $mail->IsSMTP();  // telling the class to use SMTP
        $mail->Mailer = "smtp";
        $mail->Host = $settings['email']['host'];
        $mail->Port = $settings['email']['port'];
        if ($settings['email']['tls']) {
            $mail->SMTPSecure = 'tls';
		} else {
            $mail->SMTPSecure = 'ssl';
		}
        $mail->SMTPAuth = true; // turn on SMTP authentication
        $mail->Username = $settings['email']['username']; // SMTP username
        $mail->Password = $settings['email']['password']; // SMTP password

        $mail->From = $settings['email']['from'];
        $mail->FromName = $settings['email']['from_name'];
        $mail->AddReplyTo($settings['email']['from'], $settings['email']['from_name']);
        $mail->CharSet = "utf-8";
        $mail->WordWrap = 65;                                 // set word wrap to 50 characters

        return $mail;
    }

    public function expire_session($expiry) {
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $expiry)) {
            // last request was more than 30 minutes ago
            alert("You were logged out automatically, because you were last active " . timetostr($_SESSION['last_activity']) . '.', 'alert-info');
            session_unset();     // unset $_SESSION variable for the run-time 
            session_destroy();   // destroy session data in storage
            $this->start_session();
            return true;
        }
		return false;
    }

    public function start_session() {
        global $settings;
        session_name("formr_session");
        session_set_cookie_params($settings['session_cookie_lifetime'], "/", null, SSL, true);
        session_start();
    }

    public function loginUser($user) {
        if (isset($_GET['run_name']) AND isset($_GET['code']) AND strlen($_GET['code']) == 64): // came here with a login link
            $login_code = $_GET['code'];

            if ($user->user_code !== $login_code): // this user came here with a session code that he wasn't using before. this will always be true if the user is (a) new (auto-assigned code by site) (b) already logged in with a different account

                if ($user->loggedIn()): // if the user is new and has an auto-assigned code, there's no need to talk about the behind-the-scenes change
                    // but if he's logged in we should alert them
                    alert("You switched sessions, because you came here with a login link and were already logged in as someone else.", 'alert-info');
                endif;

				global $fdb;
                $user = new User($fdb, null, $login_code);

            // a special case are admins. if they are not already logged in, verified through password, they should not be able to obtain access so easily. but because we only create a mock user account, this is no problem. the admin flags are only set/privileges are only given if they legitimately log in
            endif;

        elseif (isset($_GET['run_name']) AND isset($user->user_code)):
        // all good
        else:
            alert("<strong>Sorry.</strong> Something went wrong when you tried to access.", 'alert-danger');
            redirect_to("index");
        endif;

        return $user;
    }

    public function makeTitle() {
        global $title;
        if (trim($title)) {
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

	/**
	 * @return DB
	 */
	public static function getDb() {
		return DB::getInstance();
	}

	/**
	 * @return \OAuth2\Server
	 */
	public static function getOauthServer() {
		static $server;
		if ($server != null) {
			return $server;
		}

		// Setup DB connection for oauth
		$db_config = (array)Config::get('database');
		$options = array(
			'host' => $db_config['host'],
			'dbname' => $db_config['database'],
			'charset' => 'utf8',
		);
		if (!empty($db_config['port'])) {
			$options['port'] = $db_config['port'];
		}

		$dsn = 'mysql:' . http_build_query($options, null, ';');
		$username = $db_config['login'];
		$password = $db_config['password'];

		OAuth2\Autoloader::register();

		// $dsn is the Data Source Name for your database, for exmaple "mysql:dbname=my_oauth2_db;host=localhost"
		$storage = new OAuth2\Storage\Pdo(array('dsn' => $dsn, 'username' => $username, 'password' => $password));

		// Pass a storage object or array of storage objects to the OAuth2 server class
		$server = new OAuth2\Server($storage);

		// Add the "Client Credentials" grant type (it is the simplest of the grant types)
		$server->addGrantType(new OAuth2\GrantType\ClientCredentials($storage));

		// Add the "Authorization Code" grant type (this is where the oauth magic happens)
		$server->addGrantType(new OAuth2\GrantType\AuthorizationCode($storage));
		return $server;
	}

}

