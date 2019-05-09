<?php

require_once('../setup.php');

class FirstTest extends PHPUnit\Framework\TestCase {

    private function checkPageForPHPErrors($address) {
        $bla = file_get_contents($address);
        $this->assertEquals(FALSE, strpos($bla, "Fatal error") OR strpos($bla, "Parse error") OR strpos($bla, "<b>Warning</b>:") OR strpos($bla, "<b>Notice</b>:"));
    }

    public function testAllPages() {
        $this->checkPageForPHPErrors(WEBROOT);
        $this->checkPageForPHPErrors(WEBROOT . "/public/login");
        $this->checkPageForPHPErrors(WEBROOT . "/public/documentation");
        $this->checkPageForPHPErrors(WEBROOT . "/public/studies");
        $this->checkPageForPHPErrors(WEBROOT . "/public/about");
        $this->checkPageForPHPErrors(WEBROOT . "/public/register");
        $this->checkPageForPHPErrors(WEBROOT . "/public/logout");

        $this->checkPageForPHPErrors(WEBROOT . "/admin/mail/");
        $this->checkPageForPHPErrors(WEBROOT . "/admin/mail/edit");

        $this->checkPageForPHPErrors(WEBROOT . "/admin/run/");

        $this->checkPageForPHPErrors(WEBROOT . "/admin/survey/");
    }

}
