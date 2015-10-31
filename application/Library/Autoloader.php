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

		if (!defined('APPLICATION_PATH')) {
			define('APPLICATION_PATH', INCLUDE_ROOT . 'application/');
		}

		return array(
			'AdminAjaxController' => APPLICATION_PATH . 'Controller/AdminAjaxController.php',
			'AdminController' => APPLICATION_PATH . 'Controller/AdminController.php',
			'AdminMailController' => APPLICATION_PATH . 'Controller/AdminMailController.php',
			'AdminRunController' => APPLICATION_PATH . 'Controller/AdminRunController.php',
			'AdminSurveyController' => APPLICATION_PATH . 'Controller/AdminSurveyController.php',
			'AnimalName' => APPLICATION_PATH . 'Library/AnimalName.php',
			'ApiController' => APPLICATION_PATH . 'Controller/ApiController.php',
			'ApiDAO' => APPLICATION_PATH . 'Dao/ApiDAO.php',
			'Branch' => APPLICATION_PATH . 'Model/Branch.php',
			'Cache' => APPLICATION_PATH . 'Library/Cache.php',
			'Config' => APPLICATION_PATH . 'Library/Config.php',
			'Controller' => APPLICATION_PATH . 'Controller/Controller.php',
			'CURL' => APPLICATION_PATH . 'Library/CURL.php',
			'DB' => APPLICATION_PATH . 'Library/DB.php',
			'DB_Select' => APPLICATION_PATH . 'Model/DB.php',
			'Email' => APPLICATION_PATH . 'Model/Email.php',
			'EmailAccount' => APPLICATION_PATH . 'Model/EmailAccount.php',
			'External' => APPLICATION_PATH . 'Model/External.php',
			'HTML_element' => APPLICATION_PATH . 'Model/Item.php',
			'Item' => APPLICATION_PATH . 'Model/Item.php',
			'ItemFactory' => APPLICATION_PATH . 'Model/Item.php',
			'Item_blank' => APPLICATION_PATH . 'Model/Item.php',
			'Item_calculate' => APPLICATION_PATH . 'Model/Item.php',
			'Item_cc' => APPLICATION_PATH . 'Model/Item.php',
			'Item_check' => APPLICATION_PATH . 'Model/Item.php',
			'Item_check_button' => APPLICATION_PATH . 'Model/Item.php',
			'Item_choose_two_weekdays' => APPLICATION_PATH . 'Model/Item.php',
			'Item_color' => APPLICATION_PATH . 'Model/Item.php',
			'Item_date' => APPLICATION_PATH . 'Model/Item.php',
			'Item_datetime' => APPLICATION_PATH . 'Model/Item.php',
			'Item_datetime_local' => APPLICATION_PATH . 'Model/Item.php',
			'Item_email' => APPLICATION_PATH . 'Model/Item.php',
			'Item_file' => APPLICATION_PATH . 'Model/Item.php',
			'Item_geopoint' => APPLICATION_PATH . 'Model/Item.php',
			'Item_get' => APPLICATION_PATH . 'Model/Item.php',
			'Item_image' => APPLICATION_PATH . 'Model/Item.php',
			'Item_ip' => APPLICATION_PATH . 'Model/Item.php',
			'Item_letters' => APPLICATION_PATH . 'Model/Item.php',
			'Item_mc' => APPLICATION_PATH . 'Model/Item.php',
			'Item_mc_button' => APPLICATION_PATH . 'Model/Item.php',
			'Item_mc_heading' => APPLICATION_PATH . 'Model/Item.php',
			'Item_mc_multiple' => APPLICATION_PATH . 'Model/Item.php',
			'Item_mc_multiple_button' => APPLICATION_PATH . 'Model/Item.php',
			'Item_month' => APPLICATION_PATH . 'Model/Item.php',
			'Item_note' => APPLICATION_PATH . 'Model/Item.php',
			'Item_number' => APPLICATION_PATH . 'Model/Item.php',
			'Item_random' => APPLICATION_PATH . 'Model/Item.php',
			'Item_range' => APPLICATION_PATH . 'Model/Item.php',
			'Item_range_ticks' => APPLICATION_PATH . 'Model/Item.php',
			'Item_rating_button' => APPLICATION_PATH . 'Model/Item.php',
			'Item_referrer' => APPLICATION_PATH . 'Model/Item.php',
			'Item_select_multiple' => APPLICATION_PATH . 'Model/Item.php',
			'Item_select_one' => APPLICATION_PATH . 'Model/Item.php',
			'Item_select_or_add_multiple' => APPLICATION_PATH . 'Model/Item.php',
			'Item_select_or_add_one' => APPLICATION_PATH . 'Model/Item.php',
			'Item_server' => APPLICATION_PATH . 'Model/Item.php',
			'Item_sex' => APPLICATION_PATH . 'Model/Item.php',
			'Item_submit' => APPLICATION_PATH . 'Model/Item.php',
			'Item_tel' => APPLICATION_PATH . 'Model/Item.php',
			'Item_text' => APPLICATION_PATH . 'Model/Item.php',
			'Item_textarea' => APPLICATION_PATH . 'Model/Item.php',
			'Item_time' => APPLICATION_PATH . 'Model/Item.php',
			'Item_timezone' => APPLICATION_PATH . 'Model/Item.php',
			'Item_url' => APPLICATION_PATH . 'Model/Item.php',
			'Item_week' => APPLICATION_PATH . 'Model/Item.php',
			'Item_year' => APPLICATION_PATH . 'Model/Item.php',
			'Item_yearmonth' => APPLICATION_PATH . 'Model/Item.php',
			'OAuthDAO' => APPLICATION_PATH . 'Dao/OAuthDAO.php',
			'OpenCPU' => APPLICATION_PATH . 'Library/OpenCPU.php',
			'Page' => APPLICATION_PATH . 'Model/Page.php',
			'Pagination' => APPLICATION_PATH . 'Model/Pagination.php',
			'Pause' => APPLICATION_PATH . 'Model/Pause.php',
			'PublicController' => APPLICATION_PATH . 'Controller/PublicController.php',
			'Request' => APPLICATION_PATH . 'Library/Request.php',
			'Response' => APPLICATION_PATH . 'Library/Response.php',
			'Router' => APPLICATION_PATH . 'Library/Router.php',
			'Run' => APPLICATION_PATH . 'Model/Run.php',
			'RunDAO' => APPLICATION_PATH . 'Dao/RunDAO.php',
			'RunSession' => APPLICATION_PATH . 'Model/RunSession.php',
			'RunUnit' => APPLICATION_PATH . 'Model/RunUnit.php',
			'RunUnitFactory' => APPLICATION_PATH . 'Model/RunUnit.php',
			'Session' => APPLICATION_PATH . 'Library/Session.php',
			'Shuffle' => APPLICATION_PATH . 'Model/Shuffle.php',
			'Site' => APPLICATION_PATH . 'Model/Site.php',
			'SkipBackward' => APPLICATION_PATH . 'Model/SkipBackward.php',
			'SkipForward' => APPLICATION_PATH . 'Model/SkipForward.php',
			'SpreadsheetReader' => APPLICATION_PATH . 'Model/SpreadsheetReader.php',
			'SuperadminController' => APPLICATION_PATH . 'Controller/SuperadminController.php',
			'Survey' => APPLICATION_PATH . 'Model/Survey.php',
			'Template' => APPLICATION_PATH . 'Library/Template.php',
			'UnitSession' => APPLICATION_PATH . 'Model/UnitSession.php',
			'User' => APPLICATION_PATH . 'Model/User.php',
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
