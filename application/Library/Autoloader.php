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

		if (strpos($class, 'Controller') !== false) {
			$file = APPLICATION_PATH . "Controller/{$class}.php";
		} elseif (strpos($class, 'Helper') !== false) {
			$file = APPLICATION_PATH . "Helper/{$class}.php";
		} else {
			$class = str_replace('Factory', '', $class);
			$libraryPath = APPLICATION_PATH . "Library/{$class}.php";
			$modelPath = APPLICATION_PATH . "Model/{$class}.php";
			if (strpos($class, 'Item_') === 0 || strpos($class, 'HTML_') === 0) {
				$file = APPLICATION_PATH . 'Model/Item.php';
			} elseif (file_exists($modelPath)) {
				$file = $modelPath;
			} elseif (file_exists($libraryPath)) {
				$file = $libraryPath;
			}
		}

		if (!empty($file)) {
			return $file;
		}
		return false;
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
