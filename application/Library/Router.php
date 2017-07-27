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

    public function __construct(&$site) {
        $this->site = $site;
		$this->routes = Config::get('routes');
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
		if (!method_exists($controllerName, $actionName)) {
			$actionName = $this->shiftAction($controllerName);
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
	 * @param type $controller
	 * @return string
	 */
	private function shiftAction($controller) {
		if ($controller === 'PublicController') {
			return 'runAction';
		}
		return 'indexAction';
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

