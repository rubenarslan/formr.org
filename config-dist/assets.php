<?php

/**
 * Define assets to be loaded in dev or production mode
 *
 */

/** <global> @var $settings  */

$buildDir = !empty($settings['display_errors']) ? 'dev-build' : 'build';

$settings['assets'] = array(
    'frontend' => [
        'js' => ["{$buildDir}/js/frontend.bundle.js"],
        'css' => [],
    ],
    'material' => [
        'js' => ["{$buildDir}/js/material.bundle.js"],
        'css' => [],
    ],
    'admin' => [
        'js' => ["{$buildDir}/js/ace/ace.js", "{$buildDir}/js/admin.bundle.js"],
        'css' => [],
    ],
);
