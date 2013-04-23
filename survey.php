<?
require_once 'Model/Study.php'; # Study , nothing is echoed yet
require_once 'Model/Survey.php'; # Survey class, nothing is echoed yet

$survey = new Survey($vpncode,$study,@$run,array('timestarted'=>@$timestarted));

if($survey->progress===1) 
{
	$goto = "study_done.php?study_id={$study->id}";
	if(isset($run))
		$goto .= "&run_id=".$run->id;
	redirect_to($goto);
}
// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein
require_once 'includes/view_header.php';

if(isset($_POST['vpncode'])) 
{
	$survey->post($_POST);
}

echo $survey->render();

/* close database connection, include ga if enabled */
require('includes/view_footer.php');