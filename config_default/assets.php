<?php
/**
 * Define assets to be loaded in dev or production mode
 */

/** <global> @var $settings  */

$settings['default_assets'] = $assets = array();

// List assets according to library, separating css and js
$assets['font-google'] = array(
	'css' => array(
		'//fonts.googleapis.com/css?family=Roboto:400,100,300,600,400italic,700',
		'//fonts.googleapis.com/icon?family=Material+Icons'
	)
);

$assets['jquery'] = array(
	'js' => 'bower_components/jquery/jquery.js',
);

$assets['bootstrap'] = array(
	'js' => 'bower_components/bootstrap/dist/js/bootstrap.js',
	'css' => 'bower_components/bootstrap/dist/css/bootstrap.css',
);

$assets['font-awesome'] = array(
	'css' => 'bower_components/font-awesome/css/font-awesome.css',
);

$assets['bootstrap-material-design'] = array(
	'js' => array(
		'bower_components/bootstrap-material-design/dist/js/material.js',
		'bower_components/bootstrap-material-design/dist/js/ripples.js'
	),
	'css' => array(
		'bower_components/bootstrap-material-design/dist/css/bootstrap-material-design.css',
		'bower_components/bootstrap-material-design/dist/css/ripples.css',
	)
);

$assets['webshim'] = array(
	'js' => array(
		'bower_components/webshim/js-webshim/dev/polyfiller.js',
		'common/js/webshim.js'
	)
);

$assets['select2'] = array(
	'js' => 'bower_components/select2/select2.js',
	'css' => 'bower_components/select2/select2.css',
);

$assets['ace'] = array(
	'js' => 'bower_components/ace-builds/src-noconflict/ace.js',
);

$assets['highlight'] = array(
	'js' => 'common/js/highlight/highlight.pack.js',
	'css' => 'common/js/highlight/styles/vs.css',
);

$assets['site'] = array(
	'js' => 'site/js/main.js',
	'css' => 'site/css/style.css',
);

$assets['site:run'] = array(
	'js' => 'site/js/main.js',
	'css' => 'site/css/run.css',
);

$assets['site:custom'] = array(
	'css' => 'common/css/custom_item_classes.css',
);

$assets['admin'] = array(
	'js' => 'admin/js/main.js',
	'css' => array('admin/css/AdminLTE.css', 'admin/css/style.css'),
);

$assets['run'] = array(
	'js' => 'common/js/run.js'
);

$assets['run_settings'] = array(
	'js' => 'common/js/run_settings.js'
);

$assets['run_users'] = array(
	'js' => 'common/js/run_users.js'
);

$assets['survey'] = array(
	'js' => 'common/js/survey.js'
);

$assets['main:js'] = array(
	'js' => 'common/js/main.js',
);

/**
 * @TODO:
 * - Define $settings['default_assets']['prod']
 */


// Assets that are used in both admin and frontend, in the order in which they will be loaded
$assets_common = array('font-google', 'jquery', 'bootstrap', 'font-awesome', 'webshim', 'select2', 'highlight');

$settings['default_assets']['dev'] = array(
	// site theme
	'site' => array_merge ($assets_common, array('main:js', 'run_users', 'run', 'survey', 'site', 'site:custom')),
	'admin' => array_merge($assets_common, array('ace', 'main:js', 'run_users', 'run_settings', 'run', 'admin')),
	'assets' => array_merge($assets, array(
		// use this array to override any asset defined above using its KEY
	)),
);
$settings['default_assets']['prod'] = array(
	'site' => array(),
	'admin' => array(),
	'assets' => array_merge($assets, array(
		// use this array to override any asset defined above using its KEY
		// For example 'bootstrap-material-design' is overriden here when site goes to production
		'bootstrap-material-design' => array(
			'js' => array(
				'build/bs-material/material.js',
				'build/bs-material/ripples.js'
			),
			'css' => array(
				'build/bs-material/bootstrap-material-design.css',
				'build/bs-material/css/ripples.css',
			)
		),
		
	)), 
);
