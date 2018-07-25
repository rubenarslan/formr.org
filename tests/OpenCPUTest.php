<?php

require_once '../application/Library/Config.php';
require_once '../application/Library/CURL.php';
require_once '../application/Library/OpenCPU.php';

/* 
 * Test Config class
 */
class OpenCPUTest extends PHPUnit\Framework\TestCase {

	public function configProvider() {
		$settings = array(
			'opencpu_instance' => 'http://opencpu.psych.bio.uni-goettingen.de',
		);
		return array(array($settings));
	}

	/**
	 *
	 * @dataProvider configProvider
	 */
	public function testConnection($settings) {
		Config::initialize($settings);
		$ocpu = OpenCPU::getInstance('opencpu_instance');
		$session = $ocpu->snippet('rnorm(5)');
		$this->assertInstanceOf('OpenCPU_Session', $session);

		$this->write("Files");
		$this->write($session->getFiles());
		$this->write("Console");
		$this->write($session->getConsole());
		$this->write("Stdout");
		$this->write($session->getStdout());
		$this->write("Object");
		$this->write($session->getObject());
	}

	private function write($object) {
		echo "\n";
		print_r($object);
	}
}
