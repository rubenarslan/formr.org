<?php

/*
 * Test Config class
 */

class ConfigTest extends PHPUnit\Framework\TestCase {

    public function configProvider() {
        $settings = array(
            'username' => 'cyril',
            'names' => array(
                'first_name' => 'Cyril',
                'last_name' => 'Tata',
            ),
            'deep' => array('deeper' => array('deepest' => 'inner_value'))
        );

        return array(array($settings));
    }

    /**
     *
     * @dataProvider configProvider
     */
    public function testGet($settings) {
        Config::initialize($settings);
        $this->assertEquals('cyril', Config::get('username'));
        $this->assertEquals('Tata', Config::get('names.last_name'));
        $this->assertEquals('inner_value', Config::get('deep.deeper.deepest'));
    }

    /**
     *
     * @dataProvider configProvider
     */
    public function testDefault($settings) {
        Config::initialize($settings);
        $this->assertEquals('none', Config::get('middlename', 'none'));
        $this->assertNull(Config::get('non_existent.whatever'));
    }

}
