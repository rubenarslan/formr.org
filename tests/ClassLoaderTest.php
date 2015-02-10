<?php

/** 
 * Test Autoloder class
 */

#phpunit --bootstrap ../Library/Autoloader ClassLoaderTest

class ClassLoaderTest extends PHPUnit_Framework_TestCase {

    public function testClasses() {
       $zero = DB::NULL_NATURAL;
       $email = new Email(null);
       $this->assertTrue(class_exists('DB') && class_exists('Email') && class_exists('RunUnit'), "Some class in test not autoloaded");
       $this->assertEquals(0, $zero);
    }

    public function testNonExistent() {
        $this->assertFalse(class_exists('User', false));
    }

}

