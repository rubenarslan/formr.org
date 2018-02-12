<?php
/**
 * Vanilla Router for formr.org
 * Heavily relies on defined .htaccess
 */

class Router {

    protected static $instance = null;

    protected $action;

    protected $controller;

    protected $webroot;

    protected $params;

    /**
     * @var Site
     */
    protected $site;

	protected $routes;

	protected $usingSubDomain;

	protected $serverName;

	public function __construct(&$site) {
        $this->site = $site;
		$this->routes = Config::get('routes');
		$this->usingSubDomain = Config::get('use_study_subdomains');
		$this->serverName = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : null;
		if (!$this->serverName) {
			throw new Exception('Server name not explicitly defined');
		}
    }

    /**
     * @return Router
     */
    public function route() {
		$route = $this->site->request->str('route');

		// First try to get controller path from the route which is one of those configured routes
		foreach ($this->routes as $r) {
			if (strpos($route, $r) === 0) {
				$controllerPath = $r;
			}
		}
		// If none is found in configured routes then default to public
		if (empty($controllerPath)) {
			$controllerPath = 'public';
		}

		// get action
		$params = (array)array_filter(explode('/', preg_replace('/\b' . preg_quote($controllerPath, '/') . '\b/', '', $route)));
		$action = array_shift($params);
		if (!$action) {
			$action = 'index';
		}

		// Check if action exists in controller and if it doesn't assume we are looking at a run
		// @todo validate runs not to have controller action names (especially PublicController)
		$controllerName = $this->getControllerName($controllerPath);
		$actionName = $this->getActionName($action);
		if (!class_exists($controllerName, true)) {
			throw new Exception ("Controller $controllerName does not exist");
		}

		// Sub-domains for now are used only for accessing studies
		if ($this->usingSubDomain && FMRSD_CONTEXT) {
			$controllerName = 'PublicRunController';
			$actionName = 'indexAction';
			$runName = $this->getRunFromSubDomain();
			if ($action !== 'index') {
				array_unshift($params, $action);
			}
			array_unshift($params, $runName);
		}

		if (!method_exists($controllerName, $actionName)) {
			// Assume at this point user is trying to access a private action via the indexAction
			list($controllerName, $actionName) = $this->shiftAction($controllerName);
			// push back the $action as an action parameter
			array_unshift($params, $action);
		}

		$this->controller = $controllerName;
		$this->action = $actionName;
		$this->params = $params;
		$this->site->setPath($route);
		return $this;
    }

	private function getControllerName($controllerPath) {
		$parts = array_filter(explode('/', $controllerPath));
		$parts = array_map('ucwords', array_map('strtolower', $parts));
		return implode('', $parts) . 'Controller';
	}

	private function getActionName($action) {
		if (strpos($action, '-') !== false) {
			$parts = array_filter(explode('-', $action));
		} else {
			$parts = array_filter(explode('_', $action));
		}
		$action = array_shift($parts);
		foreach ($parts as $part) {
			$action .= ucwords(strtolower($part));
		}
		return $action . 'Action';
	}

	/**
	 * Some hack method to shift blame when we can't find action in controller
	 *
	 * @param string $controller
	 * @return string
	 */
	private function shiftAction($controller) {
		if ($controller === 'PublicController') {
			return array('PublicRunController', 'indexAction');
		}
		return array($controller, 'indexAction');
	}

	/**
	 * Some hack method to shift blame when we can't find action in controller
	 *
	 * @return array
	 */
	private function getStudyRoute() {
		//$actionName = $this->getActionName($action ? $action : 'index');
		return array('PublicRunController', 'indexAction');
	}

	/**
	 * Get Run name from sub-domain
	 *
	 * @return string
	 */
	private function getRunFromSubDomain() {
		$host = explode('.', $this->serverName);
		$subdomains = array_slice($host, 0, count($host) - 2);
		return $subdomains[0];
	}

	public function execute() {
		$controller_ = $this->controller;
		$action = $this->action;

		$controller = new $controller_($this->site);
		if (!method_exists($controller, $action)) {
			throw new Exception("Action $action not found in $controller_");
		}

		return call_user_func_array(array($controller, $action), $this->params);
	}

    /**
     * @return Router
     */
    public static function getInstance() {
        if (self::$instance === null) {
            global $site;
            self::$instance = new self($site);
        }
        return self::$instance;
    }

    public static function getWebRoot() {
        return Config::get('web_dir');
    }

    public static function isWebRootDir($name) {
        return is_dir(self::getWebRoot() . '/' . $name);
    }
}

