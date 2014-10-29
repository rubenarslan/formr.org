<?php
/* 
 * ENV Specific config for DEV_ENV = default
 */

// Ovewrites vars in /define_root
$settings['define_root'] = array(
    'protocol' => 'http://',
    'doc_root' => 'localhost/formr.org/',
    'server_root' => FORMRORG_ROOT . '/',
    'online' => false,
    'testing' => true
);