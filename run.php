<?
require_once 'define_root.php';

require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . 'Model/Run.php'; # Study , nothing is echoed yet
require_once INCLUDE_ROOT . "Model/UnitSession.php";

if($_GET['run_name'] == 'fake_test_run' AND $user->isAdmin()): // for testing purposes
	require_once INCLUDE_ROOT . "Model/Survey.php";

	$find_test = $fdb->prepare("SELECT * FROM `survey_unit_sessions` WHERE session = :session LIMIT 1");
	$find_test->bindParam(':session',$_SESSION['session']);
	$find_test->execute();
	$to_test = $find_test->fetch(PDO::FETCH_ASSOC);
#	pr($to_test);
	$unit = new Survey($fdb, $_SESSION['session'], 
		array(
			'unit_id' => $to_test['unit_id'],
			'run_name' => 'fake_test_run',
			'session_id' => $to_test['id']
		));

	$output = $unit->exec();
	
else:

	$run = new Run($fdb, $_GET['run_name']);

	if(!$run->valid):
		alert("<strong>Error.</strong> This run {$_GET['run_name']} does not exist.",'alert-error');
		redirect_to('index');
	endif;

	if($user->loggedIn() AND isset($_SESSION['UnitSession']) AND $user->user_code !== unserialize($_SESSION['UnitSession'])->session):
		alert('<strong>Error.</strong> You seem to have switched sessions.','alert-error');
		redirect_to('index');
	endif;

	$output = $run->getUnit($user->user_code);
	
endif;

if($output):
	if(isset($output['title']))
		$title = $output['title'];
	else $title = $run->name;
	
	require_once INCLUDE_ROOT . 'view_header.php';
	echo $site->renderAlerts();

	echo $output['body'];
	require_once INCLUDE_ROOT . 'view_footer.php';
endif;