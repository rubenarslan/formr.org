<?php
/**
 * Define assets to be loaded in dev or production mode
 * @TODO:
 * - Define $settings['default_assets']['prod']
 *
 */

/** <global> @var $settings  */

$settings['default_assets'] = $assets = array();
$assets_file = realpath(INCLUDE_ROOT . 'webroot/assets/assets.json');
if (!is_file($assets_file)) {
	throw new RuntimeException('Unable to read assets.json');
}

$assets = (array) json_decode(file_get_contents($assets_file), true);

// Assets that are used in both admin and frontend, in the order in which they will be loaded
$assets_common = array('font-google', 'jquery', 'bootstrap', 'font-awesome', 'webshim', 'select2', 'hammer', 'highlight');

$settings['default_assets']['dev'] = array(
	// site theme
	'site' => array_merge ($assets_common, array('main:js', 'run_users', 'run', 'survey', 'site', 'site:custom')),
	'admin' => array_merge($assets_common, array('ace', 'main:js', 'run_users', 'run_settings', 'run', 'admin')),
	'assets' => array_merge($assets, array(
		// use this array to override any asset defined above using its KEY
	)),
);
$settings['default_assets']['prod'] = array(
	'site' => array('site'),
	'admin' => array('admin'),
	'assets' => array_merge($assets, array(
		// use this array to override any asset defined above using its KEY
		// For example 'bootstrap-material-design' is overriden here when site goes to production
		'bootstrap-material-design' => array(
			'js' => array(
				'build/js/bootstrap-material-design.min.js', //merge of material.js and ripple.js
			),
			'css' => array(
				'build/css/bootstrap-material-design.min.css', // merged ripple.css into this
			)
		),
		'ace' => array(
			'js' => 'build/js/ace/ace.js',
		),
		'site' => array(
			'css' => 'build/css/formr.min.css',
			'js' => 'build/formr.min.js'
		),
		'site:material' => array(
			'css' => 'build/css/formr-material.min.css',
			'js' => 'build/js/formr-material.min.js'
		),
		'admin' => array(
			'css' => 'build/css/formr-admin.min.css',
			'js' => 'build/js/formr-admin.min.js'
		),
		
	)), 
);
