<?php

/**
 * Primitive class loader based on composer's class loader
 * Implements only class map feature
 * 
 */
class Autoload {

    private static $loader = null;

    /**
     * Register this class instance as an autoloader
     * @throws RuntimeException
     */
    public function register() {
        if (!spl_autoload_register(array($this, 'loadClass'), true)) {
            throw new RuntimeException(__CLASS__ . '::register() failed.');
        }
    }

    /**
     * unregister class instance as an autoloader
     */
    public function unregister() {
        spl_autoload_unregister(array($this, 'loadClass'));
    }

    /**
     * Loads the given class
     *
     * @param string $class The name of the class
     * @return bool Returns TRUE if class is loaded
     * @throws RuntimeException
     */
    public function loadClass($class) {
        if ($file = $this->findFile($class)) {
            if (class_exists($class, false)) {
                return true;
            }

            include $file;
            if (!class_exists($class)) {
                $message = sprintf("Autloader expected class %s to be defined in file %s. The file was found but the class was not in it", $class, $file);
                throw new RuntimeException($message);
            }
            return true;
        }
        return false;
    }

    /**
     * Finds the path associated to the class name
     *
     * @param string $class
     * @return string|null
     */
    private function findFile($class) {
        if (!defined('APPLICATION_ROOT')) {
            define('APPLICATION_ROOT', realpath(dirname(__FILE__) . '../../') . '/');
        }

        if (!defined('APPLICATION_PATH')) {
            define('APPLICATION_PATH', APPLICATION_ROOT . 'application/');
        }

        $class = $this->classNameToPath($class);
        $paths = array(
            APPLICATION_PATH . "{$class}.php",
            APPLICATION_PATH . "Controller/{$class}.php",
            APPLICATION_PATH . "Model/RunUnit/{$class}.php", 
            APPLICATION_PATH . "Model/Item/{$class}.php",
            APPLICATION_PATH . "Model/{$class}.php",
            APPLICATION_PATH . "View/{$class}.php",
            APPLICATION_PATH . "Helper/{$class}.php",
            APPLICATION_PATH . "Queue/{$class}.php",
            APPLICATION_PATH . "Services/{$class}.php",
            APPLICATION_PATH . "Spreadsheet/{$class}.php",
            APPLICATION_PATH . "Middleware/{$class}.php",
        );

        foreach ($paths as $path) {
            if (file_exists($path) && is_readable($path)) {
                $file = $path;
                break;
            }
        }

        if (!empty($file)) {
            return $file;
        }
        return false;
    }

    protected function classNameToPath($class) {
        if (strstr($class, '_') !== false) {
            $pieces = array_reverse(explode('_', $class));
            $class = implode('/', $pieces);
        }
        if (substr($class, -7) === 'Factory') {
            $class = str_replace('Factory', '', $class);
        }
        return $class;
    }

    public static function getLoader() {
        if (self::$loader === null) {
            /* @var $loader Autoload */
            $loader = new self();
            $loader->register();
            self::$loader = $loader;
        }
        return self::$loader;
    }

}

return Autoload::getLoader();
