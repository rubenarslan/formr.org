<?
require_once 'define_root.php';
require_once INCLUDE_ROOT . "Model/Site.php";

if($_GET['run_name'] == 'fake_test_run' AND $user->isAdmin()): // for testing purposes
	require_once INCLUDE_ROOT . "Model/Survey.php";

	$find_test = $fdb->prepare("SELECT id AS session_id, unit_id, run_session_id FROM `survey_unit_sessions` WHERE id = :id LIMIT 1");
	$find_test->bindParam(':id',$_SESSION['survey_test_id']);
	$find_test->execute();
	$to_test = $find_test->fetch(PDO::FETCH_ASSOC);
	$to_test['run_name'] = 'fake_test_run';
	
	$unit = new Survey($fdb, $_SESSION['session'], $to_test);
	$output = $unit->exec();

	if(!$output):
		$output['title'] = 'Finish';
		$output['body'] = "
			<h1>Finish</h1>
			<p>
			You're finished with testing this survey.</p><a href='".WEBROOT."admin/".$_SESSION['test_survey_name']."/index'>Back to the admin control panel.</a>";
	endif;
	
else:

	if(isset($_GET['run_name'])):
		require_once INCLUDE_ROOT . "Model/Run.php";
		$run = new Run($fdb, $_GET['run_name']);
	
		if(!$run->valid):
			alert("<strong>Error:</strong> Run broken.",'alert-error');
			redirect_to("/index");
		else:
			if($user->loggedIn() AND isset($_SESSION['UnitSession']) AND $user->user_code !== unserialize($_SESSION['UnitSession'])->session):
				alert('<strong>Error.</strong> You seem to have switched sessions.','alert-error');
				redirect_to('index');
			endif;
			
			require_once INCLUDE_ROOT . 'Model/RunSession.php';
			
			$run_session = new RunSession($fdb, $run->id, $user->id, $user->user_code);
#			pr($user->user_code);
#			pr($run_session->id);

			if($run_session->id OR 							// if this session exists or
				( $run->public AND $run_session->create() ) // if the run is public, we create a new session on-the-fly
			):
				$user->user_code = $run_session->session;
				$output = $run_session->getUnit();
			else:
				alert("<strong>Error:</strong> You don't have access to this run.",'alert-error');
				redirect_to("/index");
			endif;
		endif;
	endif;
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