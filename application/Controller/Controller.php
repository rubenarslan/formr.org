<?php

abstract class Controller {

	/**
	 *
	 * @var Site
	 */
	protected $site;

	/**
	 *
	 * @var User 
	 */
	protected $user;
	
	/**
	 *
	 * @var Run
	 */
	public $run;

	/**
	 *
	 * @var DB
	 */
	protected $fdb;
	
	/**
	 *
	 * @var Survey
	 */
	public $study;

	/**
	 *
	 * @var Request
	 */
	protected $request;

	protected $css = array();

	protected $js = array();

	public function __construct(Site &$site) {
		/** @todo do these with dependency injection */
		global $user, $run, $study, $css, $js;
		$this->site = $site;
		$this->user = &$user;
		$this->study = $study;
		$this->run = $run;
		if (is_array($css)) {
			$this->css = $css;
		}
		if (is_array($js)) {
			$this->js = $js;
		}

		$this->fdb = DB::getInstance();
		$this->request = $site->request;
	}

	protected function renderView($template, $vars = array()) {
		Template::load($template, array_merge($vars, array(
			'site' => $this->site,
			'user' => $this->user,
			'fdb' => $this->fdb,
			'js' => $this->js,
			'css' => $this->css,
			'run' => $this->run,
			'study' => $this->study,
		)));
	}

	protected function getPrivateAction($name, $separator = '_', $protected = false) {
		$parts = array_filter(explode($separator, $name));
		$action = array_shift($parts);
		foreach ($parts as $part) {
			$action .= ucwords(strtolower($part));
		}

		if ($protected) {
			return $action;
		}

		$method = $action . 'Action';
		if (!method_exists($this, $method)) {
			throw new Exception("Action '$name' is not found.");
		}
		return $method;
	}

	/**
	 * @return Site
	 */
	public function getSite() {
		return $this->site;
	}

	public function getDB() {
		return $this->fdb;
	}

	protected function registerCSS($files, $name) {
		$this->addFiles($files, $name, 'css');
	}

	protected function registerJS($files, $name) {
		$this->addFiles($files, $name, 'js');
	}

	private function addFiles($files, $name, $type) {
		if (!$files || !in_array($type, array('js', 'css'))) {
			return;
		}
		if (!is_array($files)) {
			$files = array($files);
		}
		foreach (array_filter($files) as $file) {
			if (!isset($this->{$type}[$name])) {
				$this->{$type}[$name] = array();
			}
			$this->{$type}[$name][] = $file;
		}
	}

	protected function registerAssets($which) {
		$assets = get_assets();
		if (!is_array($which)) {
			$which = array($which);
		}
		foreach ($which as $asset) {
			$this->registerCSS(array_val($assets[$asset], 'css'), $asset);
			$this->registerJS(array_val($assets[$asset], 'js'), $asset);
		}
	}
	
	protected function unregisterAssets($which) {
		if (is_array($which)) {
			foreach ($which as $a) {
				$this->unregisterAssets($a);
			}
		}
		if (isset($this->css[$which])) {
			unset($this->css[$which]);
		}
		if (isset($this->js[$which])) {
			unset($this->js[$which]);
		}
	}

	protected function replaceAssets($old, $new, $module = 'site') {
		$assets = get_default_assets($module);
		foreach ($assets as $i => $asset) {
			if ($asset === $old) {
				$assets[$i] = $new;
			}
		}
		$this->css = $this->js = array();
		$this->registerAssets($assets);
	}

	public function errorAction($code = null, $text = null) {
		formr_error($code, $text);
	}

}
