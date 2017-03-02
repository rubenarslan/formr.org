<?php
/**
 * Define assets to be loaded in dev or production mode
 */

/** <global> @var $settings  */

$settings['default_assets'] = array();
$settings['default_assets']['dev'] = array(
	// site theme
	'site' => array(
		'css' => array(
			'https://fonts.googleapis.com/css?family=Roboto:400,100,300,600,400italic,700',
			'bower_components/material/material.css',
			'bower_components/bootstrap/dist/css/bootstrap.css',
			'bower_components/font-awesome/css/font-awesome.css',
			'bower_components/select2/select2.css',
			'common/js/highlight/styles/vs.css',
			'site/css/style.css',
		),
		'js' => array(
			'bower_components/jquery/jquery.js',
			'bower_components/bootstrap/dist/js/bootstrap.js',
			'bower_components/select2/select2.js',
			'bower_components/material/material.js',
			'bower_components/webshim/js-webshim/dev/polyfiller.js',
			'common/js/webshim.js',
			'common/js/highlight/highlight.pack.js',
			'common/js/main.js',
			'common/js/run.js',
			'common/js/run_users.js',
			'site/js/main.js',
		)
	),
	// admin theme
	'admin' => array(
		'css' => array(
			'bower_components/bootstrap/dist/css/bootstrap.css',
			'bower_components/font-awesome/css/font-awesome.css',
			'bower_components/select2/select2.css',
			'admin/css/AdminLTE.css',
			'admin/css/skin-black.css',
			'admin/css/custom.css',
			'admin/css/AdminLTE-select2.css'
		),
		'js' => array(
			'bower_components/jquery/jquery.js',
			'bower_components/bootstrap/dist/js/bootstrap.js',
			'bower_components/webshim/js-webshim/dev/polyfiller.js',
			'bower_components/select2/select2.js',
			'bower_components/ace-builds/src-noconflict/ace.js',
			'common/js/webshim.js',
			'common/js/highlight/highlight.pack.js',
			'common/js/main.js',
			'admin/js/main.js',
		)
	),
);
$settings['default_assets']['prod'] = array(
	'site' => array(
		'css' => array(
			'https://fonts.googleapis.com/css?family=Roboto:400,100,300,600,400italic,700',
			'build/css/formr.min.css'
		),
		'js' => array(
			'build/js/formr.min.js'
		)
	),
	'admin' => array(
		'css' => array(
			'build/css/formr-admin.min.css'
		),
		'js' => array(
			'build/js/formr-admin.min.js'
		)
	),
);
