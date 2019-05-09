<?php

require_once('../setup.php');
require_once('../application/Library/DB.php');

/**
 * Test Autoloder class
 */
class ClassLoaderTest extends PHPUnit\Framework\TestCase {

    public function testClasses() {
        $email = new Email(null);
        $this->assertTrue(class_exists('DB') && class_exists('Email') && class_exists('RunUnit'), "Some class in test not autoloaded");
    }

    public function testNonExistent() {
        $this->assertFalse(class_exists('User', false));
    }

}
