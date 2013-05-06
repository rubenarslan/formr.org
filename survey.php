<?
require_once "includes/define_root.php";

require_once INCLUDE_ROOT . "includes/Site.php";
require_once INCLUDE_ROOT . "Model/Session.php";
require_once INCLUDE_ROOT . 'Model/StudyX.php'; # Study , nothing is echoed yet
require_once INCLUDE_ROOT . 'Model/Survey.php'; # Survey class, nothing is echoed yet

session_start();

$study = new StudyX($_GET['study_name']);

$session = new Session($_SESSION['session'],$study);
if($session->session === null)
{
	die("You don't have access at the moment.");
}

$survey = new Survey($session,$study,@$run,array('timestarted'=>@$timestarted));

if(isset($_POST['session_id'])) 
{
	$survey->post($_POST);
}

if($survey->progress===1) 
{
	$goto = "{$study->name}/study_done";
	if(isset($run))
		$goto .= "&run_id=".$run->id;
	redirect_to($goto);
}
require_once INCLUDE_ROOT . 'view_header.php';

?>
<div class="row-fluid">
    <div id="span12">
        <? echo "<h1>{$study->settings['description']}</h1>";
        echo $study->settings['description'];
        ?>
    </div>
</div>
<div class="row-fluid">
	<div class="span12">

<?php

echo $survey->render();

?>
		</div> <!-- end of span10 div -->
	</div> <!-- end of row-fluid div -->
<?php
if(isset($study->settings['problem_email'])):
?>
	<div class="row-fluid">
		<div class="span12">
			Bei Problemen wenden Sie sich bitte an <strong><a href="mailto:<?=$study->settings['problem_email'];?>"><?=$study->settings['problem_email']; ?></a>.</strong>
		</div>
	</div>
<?php endif; ?>
</div>

<?php

require_once INCLUDE_ROOT . 'view_footer.php';