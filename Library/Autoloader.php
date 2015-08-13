<?php

/**
 * Primitive class loader based on composer's class loader
 * Implements only class map feature
 * 
 */
class Autoload {

	private $classMap = array();
	private static $loader = null;

	public static function classMap() {
		if (!defined('INCLUDE_ROOT')) {
			define('INCLUDE_ROOT', realpath(dirname(__FILE__) . '../../') . '/');
		}

		return array(
			'AdminAjaxController' => INCLUDE_ROOT . 'Controller/AdminAjaxController.php',
			'AdminController' => INCLUDE_ROOT . 'Controller/AdminController.php',
			'AdminMailController' => INCLUDE_ROOT . 'Controller/AdminMailController.php',
			'AdminRunController' => INCLUDE_ROOT . 'Controller/AdminRunController.php',
			'AdminSurveyController' => INCLUDE_ROOT . 'Controller/AdminSurveyController.php',
			'ApiController' => INCLUDE_ROOT . 'Controller/ApiController.php',
			'Branch' => INCLUDE_ROOT . 'Model/Branch.php',
			'Cache' => INCLUDE_ROOT . 'Library/Cache.php',
			'Config' => INCLUDE_ROOT . 'Library/Config.php',
			'Controller' => INCLUDE_ROOT . 'Controller/Controller.php',
			'CURL' => INCLUDE_ROOT . 'Library/CURL.php',
			'DB' => INCLUDE_ROOT . 'Library/DB.php',
			'DB_Select' => INCLUDE_ROOT . 'Model/DB.php',
			'Email' => INCLUDE_ROOT . 'Model/Email.php',
			'EmailAccount' => INCLUDE_ROOT . 'Model/EmailAccount.php',
			'External' => INCLUDE_ROOT . 'Model/External.php',
			'HTML_element' => INCLUDE_ROOT . 'Model/Item.php',
			'Item' => INCLUDE_ROOT . 'Model/Item.php',
			'ItemFactory' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_blank' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_calculate' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_cc' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_check' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_check_button' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_choose_two_weekdays' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_color' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_date' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_datetime' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_datetime_local' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_email' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_file' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_geopoint' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_get' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_image' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_ip' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_letters' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_mc' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_mc_button' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_mc_heading' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_mc_multiple' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_mc_multiple_button' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_month' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_note' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_number' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_random' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_range' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_range_ticks' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_rating_button' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_referrer' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_select_multiple' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_select_one' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_select_or_add_multiple' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_select_or_add_one' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_server' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_sex' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_submit' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_tel' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_text' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_textarea' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_time' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_timezone' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_url' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_week' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_year' => INCLUDE_ROOT . 'Model/Item.php',
			'Item_yearmonth' => INCLUDE_ROOT . 'Model/Item.php',
			'OpenCPU' => INCLUDE_ROOT . 'Library/OpenCPU.php',
			'Page' => INCLUDE_ROOT . 'Model/Page.php',
			'Pagination' => INCLUDE_ROOT . 'Model/Pagination.php',
			'Pause' => INCLUDE_ROOT . 'Model/Pause.php',
			'PublicController' => INCLUDE_ROOT . 'Controller/PublicController.php',
			'Request' => INCLUDE_ROOT . 'Library/Request.php',
			'Response' => INCLUDE_ROOT . 'Library/Response.php',
			'Router' => INCLUDE_ROOT . 'Library/Router.php',
			'Run' => INCLUDE_ROOT . 'Model/Run.php',
			'RunSession' => INCLUDE_ROOT . 'Model/RunSession.php',
			'RunUnit' => INCLUDE_ROOT . 'Model/RunUnit.php',
			'RunUnitFactory' => INCLUDE_ROOT . 'Model/RunUnit.php',
			'Shuffle' => INCLUDE_ROOT . 'Model/Shuffle.php',
			'Site' => INCLUDE_ROOT . 'Model/Site.php',
			'SkipBackward' => INCLUDE_ROOT . 'Model/SkipBackward.php',
			'SkipForward' => INCLUDE_ROOT . 'Model/SkipForward.php',
			'SpreadsheetReader' => INCLUDE_ROOT . 'Model/SpreadsheetReader.php',
			'SuperadminController' => INCLUDE_ROOT . 'Controller/SuperadminController.php',
			'Survey' => INCLUDE_ROOT . 'Model/Survey.php',
			'Template' => INCLUDE_ROOT . 'Library/Template.php',
			'UnitSession' => INCLUDE_ROOT . 'Model/UnitSession.php',
			'User' => INCLUDE_ROOT . 'Model/User.php',
		);
	}

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
				$message = sprintf("Autloader expected class %s to be defined in file %s. The class was found but the file was not in it", $class, $file);
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
		if (isset($this->classMap[$class])) {
			return $this->classMap[$class];
		}
		return false;
	}

	/**
	 * Set class map for autoloader
	 *
	 * @param array $map
	 */
	public function setClassMap(array $map) {
		$this->classMap = array_merge($this->classMap, $map);
	}

	/**
	 * Get autoloader class map
	 * @return array
	 */
	public function getClassMap() {
		return $this->classMap;
	}

	/**
	 * Add/override a class path
	 *
	 * @param string $class
	 * @param string $path
	 */
	public function addClass($class, $path) {
		$this->classMap[$class] = $path;
	}

	public static function getLoader() {
		if (self::$loader === null) {
			/* @var $loader Autoload */
			$loader = new self();
			$loader->setClassMap(self::classMap());
			$loader->register();
			self::$loader = $loader;
		}
		return self::$loader;
	}

}

return Autoload::getLoader();
