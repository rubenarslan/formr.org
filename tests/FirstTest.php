<?php
# phpunit --bootstrap define_root.php tests
class FirstTest extends PHPUnit_Framework_TestCase
{
	private function checkPageForPHPErrors($address)
	{
		$bla = file_get_contents($address);
		$this->assertEquals(FALSE, strpos($bla, "Fatal error") OR strpos($bla, "Parse error") OR strpos($bla, "<b>Warning</b>:") OR strpos($bla, "<b>Notice</b>:") );
	}
	public function testAllPages()
	{
		$this->checkPageForPHPErrors(WEBROOT);
		$this->checkPageForPHPErrors(WEBROOT."/public/login");
		$this->checkPageForPHPErrors(WEBROOT."/public/documentation");
		$this->checkPageForPHPErrors(WEBROOT."/public/studies");
		$this->checkPageForPHPErrors(WEBROOT."/public/team");
		$this->checkPageForPHPErrors(WEBROOT."/public/register");
		$this->checkPageForPHPErrors(WEBROOT."/public/logout");
		
		$this->checkPageForPHPErrors(WEBROOT."/admin/mail/");
		$this->checkPageForPHPErrors(WEBROOT."/admin/mail/edit");
		
		$this->checkPageForPHPErrors(WEBROOT."/admin/run/");
		
		$this->checkPageForPHPErrors(WEBROOT."/admin/survey/");
		
//		$this->checkPageForPHPErrors(WEBROOT."/admin/cron_log/");
	}
# so basically because I rely on a global singleton-ish thing testing is difficult
#	public function testUploadOfAllWidgetsTable()
#	{
#		
#		$study = new Survey($fdb, null, array(
#			'name' => 'all_widgets',
#			'user_id' => 1
#		));
#		$this->assertEquals(TRUE, $study->createIndependently() );
#		$works = $study->uploadItemTable(APPLICATION_ROOT."/webroot/assets/example_surveys/all_widgets.xlsx");
#		$this->assertEquals(TRUE, $works);
#	}
}