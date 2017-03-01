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

	protected function registerCSS($files) {
		if (!is_array($files)) {
			$files = array($files);
		}
		$this->css = array_merge($this->css, array_filter($files));
	}

	protected function registerJS($files) {
		if (!is_array($files)) {
			$files = array($files);
		}
		$this->js = array_merge($this->js, array_filter($files));
	}

}

