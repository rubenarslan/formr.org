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
		global $site, $user, $fdb, $study, $run;
        //$vars['site'] = $site;
        $file = INCLUDE_ROOT . 'View/' . $template . '.php';
        if (file_exists($file)) {
            extract($vars);
            include $file;
        }
    }

}

