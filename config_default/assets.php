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
			'https://fonts.googleapis.com/icon?family=Material+Icons',
			'bower_components/bootstrap/dist/css/bootstrap.css',
			//'bower_components/bootstrap-material-design/dist/css/bootstrap-material-design.css',
			//'bower_components/bootstrap-material-design/dist/css/ripples.min.css',
			'bower_components/font-awesome/css/font-awesome.css',
			'bower_components/select2/select2.css',
			'common/js/highlight/styles/vs.css',
			'site/css/style.css',
		),
		'js' => array(
			'bower_components/jquery/jquery.js',
			'bower_components/bootstrap/dist/js/bootstrap.js',
			'bower_components/select2/select2.js',
			//'bower_components/bootstrap-material-design/dist/js/material.js',
			//'bower_components/bootstrap-material-design/dist/js/ripples.js',
			'bower_components/webshim/js-webshim/dev/polyfiller.js',
			'common/js/webshim.js',
			'common/js/highlight/highlight.pack.js',
			'common/js/main.js',
			'common/js/survey.js',
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
			'admin/css/style.css',
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
