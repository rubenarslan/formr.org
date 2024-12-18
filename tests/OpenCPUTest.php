<?php

/*
 * Test Config class
 */

class OpenCPUTest extends PHPUnit\Framework\TestCase {

    public function configProvider() {
        $settings = array();
        $settings['opencpu_instance'] = array(
            'base_url' => 'http://opencpu.psych.bio.uni-goettingen.de',
            'r_lib_path' => '/usr/local/lib/R/site-library'
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
        $session = $ocpu->snippet($this->getCodeSnippet());
        $this->assertInstanceOf('OpenCPU_Session', $session);

        $this->write("Session");
        $this->write($session->getResponseHeaders()['X-Ocpu-Session']);
        $this->write("Files");
        $this->write($session->getFiles());
        $this->write("Console");
        $this->write($session->getConsole());
        $this->write("Stdout");
        $this->write($session->getStdout());
        $this->write("Object");
        $this->write($session->getObject('json'));
    }

    private function write($object) {
        echo "\n";
        print_r($object);
    }

    private function getCodeSnippet($snippet = 0) {
        $snippets = array();
        $snippets[] = "library(formr) \n .formr\$last_action_time = as.POSIXct('2019-05-09 17:00:35 CEST') \n ! time_passed(minutes = 10)";
        $snippets[] = "rnorm(5)";
        return $snippets[$snippet];
    }

}
