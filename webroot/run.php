<?
require_once '../define_root.php';
require_once INCLUDE_ROOT . "Model/Site.php";

if(isset($_GET['run_name']) AND isset($_GET['code']) AND strlen($_GET['code'])==64):
	$test_code = $_GET['code'];
	$user->user_code = $test_code;
	
	$_SESSION['session'] = $test_code;
elseif(!isset($_GET['run_name']) OR !isset($user->user_code)):
	alert("<strong>Sorry.</strong> Something went wrong when you tried to access.",'alert-danger');
	redirect_to("index");
endif;

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
			You're finished with testing this survey.</p><a href='".WEBROOT."admin/survey/".$_SESSION['test_survey_name']."/index'>Back to the admin control panel.</a>";
	endif;
	
else:

	if(isset($_GET['run_name'])):
		require_once INCLUDE_ROOT . "Model/Run.php";
		$run = new Run($fdb, $_GET['run_name']);
	
		if(!$run->valid):
			alert("<strong>Error:</strong> Run broken.",'alert-danger');
			redirect_to("/index");
		else:
			if($user->loggedIn() AND isset($_SESSION['UnitSession']) AND $user->user_code !== unserialize($_SESSION['UnitSession'])->session):
				alert('<strong>Error.</strong> You seem to have switched sessions.','alert-danger');
				redirect_to('index');
			endif;
			
			require_once INCLUDE_ROOT . 'Model/RunSession.php';
			
			/* ways to get here
			1. test run link in admin area
				- check permission (ie did user create study)
			2. public run link
				- check whether run is public
			3. private run link (e.g. email reminder but run not publicly accessible)
				- check whether session exists
			
			turning this downside up
			1. has session
				- gets access
			2. elseif has created study but no session
				- gets access, create token
			3. elseif has clicked public link but no session
				- gets access, create token
			4. else run not public, no session, no admin
				- no access
			*/
			$run_session = new RunSession($fdb, $run->id, $user->id, $user->user_code); // does this user have a session?
			
			if(
				$run_session->id // would be NULL if no session
				OR // only if user has no session do other stuff
				(
					($user->created($run) // if the user created the study, give access
						OR
					 $run->public)		    // or if the run is public
				AND
				$run_session->create($user->user_code) // give access. phrased as condition, but should always return true
				) 
			):
				$output = $run_session->getUnit();
			else:
				alert("<strong>Error:</strong> You don't have access to this run.",'alert-danger');
				redirect_to("/index");
			endif;
		endif;
	endif;
endif;




if($output):
	if(isset($output['title'])):
		$title = $output['title'];
	elseif(isset($run)): 
		$title = $run->name;
	endif;
	
	$survey_view = true;
	require_once INCLUDE_ROOT . 'View/header.php';

	$alerts = $site->renderAlerts();
	if(!empty($alerts)):
		echo '
			<div class="row">
				<div class="col-md-6 col-sm-6 all-alerts">';
					echo $alerts;
			echo '</div>
			</div>';
	endif;
	?>
<div class="row">
	<div class="col-lg-12">
<?php
	echo $output['body'];
?>
	</div>
</div>
<?php
	require_once INCLUDE_ROOT . 'View/footer.php';
endif;