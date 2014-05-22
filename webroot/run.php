<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "Model/Site.php";

if(isset($_GET['run_name']) AND isset($_GET['code']) AND strlen($_GET['code'])==64): // came here with a login link
	$login_code = $_GET['code'];

	if($user->user_code !== $login_code): // this user came here with a session code that he wasn't using before. this will always be true if the user is (a) new (auto-assigned code by site) (b) already logged in with a different account

		if($user->loggedIn()): // if the user is new and has an auto-assigned code, there's no need to talk about the behind-the-scenes change
			// but if he's logged in we should alert them
			alert("You switched sessions, because you came here with a login link and were already logged in as someone else.", 'alert-info');
		endif;

		$user->logout();
		$user = new User($fdb, null, $login_code);

		// a special case are admins. if they are not already logged in, verified through password, they should not be able to obtain access so easily. but because we only create a mock user account, this is no problem. the admin flags are only set/privileges are only given if they legitimately log in
	endif;
	
elseif(isset($_GET['run_name']) AND isset($user->user_code)):
else:
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
			alert(__("<strong>Error:</strong> Run %s is broken.",$_GET['run_name']),'alert-danger');
			redirect_to("/index");
		elseif($run->being_serviced AND !$user->created($run)):
			$output = $run->getServiceMessage()->exec();
			$run_session = (object) "dummy";
			$run_session->position = "service_message";
			$run_session->current_unit_type = "Page";
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
	<div class="col-lg-12 run_position_<?=$run_session->position?> run_unit_type_<?=$run_session->current_unit_type?> run_content">
		<header class="run_content_header"></header>
<?php
	echo $output['body'];
?>
<?php
	require_once INCLUDE_ROOT . 'View/footer.php';
endif;