<?php

/**
 * Define assets to be loaded in dev or production mode
 *
 */

/** <global> @var $settings  */

$settings['assets'] = array(
    'frontend' => [
        'js' => ['build/js/frontend.bundle.js'],
        'css' => [],
    ],
    'material' => [
        'js' => ['build/js/material.bundle.js'],
        'css' => [],
    ],
    'admin' => [
        'js' => ['build/js/ace/ace.js', 'build/js/admin.bundle.js'],
        'css' => [],
    ],
);
