<?php

/**
 * Define assets to be loaded in dev or production mode
 *
 */

/** <global> @var $settings  */

// dev-build/ is webpack watch output (gitignored, `npm run webpack:watch`).
// Prefer it on dev (display_errors) ONLY when it actually exists: a deleted
// or never-produced watch dir must degrade to the committed build/, not 404
// every admin/site bundle (2026-08-17: exactly that silently disabled every
// admin page — dead buttons, missing globals — with zero server-side errors).
// form_index.php applies the same existence-checked preference for the form
// bundle.
$buildDir = (!empty($settings['display_errors'])
    && is_file(__DIR__ . '/../webroot/assets/dev-build/js/admin.bundle.js'))
    ? 'dev-build' : 'build';

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
