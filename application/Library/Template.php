<?php

/*
 * Vanilla Template
 */

class Template {

	/**
	 * Load and display a template
	 *
	 * @param string $template
	 * @param array $vars As associative array representing variables to be passed to templates
	 */
	public static function load($template, $vars = array()) {
		global $site, $user, $fdb, $study, $run, $css, $js;
		$file = APPLICATION_PATH . 'View/' . $template . '.php';

		if (file_exists($file)) {
			extract($vars);
			include $file;
		}
	}

	public static function get($template, $vars = array()) {
		ob_start();
		Template::load($template, $vars);
		return ob_get_clean();
	}

}
