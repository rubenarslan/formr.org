<?php

/*
 * Vanilla Template
 */

class Template {

    protected static $cacheVars = array();

    /**
     * Load and display a template
     *
     * @param string $template
     * @param array $vars As associative array representing variables to be passed to templates
     */
    public static function load($template, $vars = array(), $is_child = false) {
        if (strstr($template, '.') === false) {
            $template .= '.php';
        }
        $file = APPLICATION_ROOT . 'templates/' . $template;

        if (file_exists($file)) {
            if ($is_child === false) {
                self::$cacheVars = $vars;
            } else {
                $vars = array_merge(self::$cacheVars, $vars);
            }

            extract($vars);
            include $file;
        } else {
            throw new Exception("Template file '{$template}' does not exist");
        }
    }

    public static function loadChild($template, $vars = array()) {
        self::load($template, $vars, true);
    }

    public static function get($template, $vars = array()) {
        ob_start();
        Template::load($template, $vars);
        return ob_get_clean();
    }

    public static function get_replace($template, $params = array(), $vars = array()) {
        $text = self::get($template, $vars);
        return self::replace($text, $params);
    }

    public static function replace($template, $params, $rnl = false) {
        if (empty($template) || empty($params)) {
            return $template;
        }

        if ($rnl === true) {
            $template = str_replace("\n", '', $template);
        }

        $res = preg_match_all('/%{[^}]+}/', $template, $matches, PREG_OFFSET_CAPTURE);
        if (!$res) {
            return $template;
        }

        $offset = 0;
        $res = '';
        foreach ($matches[0] as $match) {
            $res .= substr($template, $offset, $match[1] - $offset);
            $key = substr($match[0], 2, -1);
            $value = isset($params[$key]) ? $params[$key] : '';
            $res .= $value;
            $offset = $match[1] + strlen($match[0]);
        }
        $res .= substr($template, $offset);
        return $res;
    }

}
