<?
require_once 'define_root.php';

require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . 'Model/Run.php'; # Study , nothing is echoed yet
require_once INCLUDE_ROOT . "Model/UnitSession.php";

$run = new Run($fdb, $_GET['run_name']);

if(!$run->valid):
	alert('<strong>Error.</strong> This run does not exist.','alert-error');
	redirect_to('index');
endif;

if($user->loggedIn() AND isset($_SESSION['UnitSession']) AND $user->user_code !== unserialize($_SESSION['UnitSession'])->session):
	alert('<strong>Error.</strong> You seem to have switched sessions.','alert-error');
	redirect_to('index');
endif;

$unit = $run->getUnit($user->user_code);

$session = new UnitSession($fdb, $user->user_code, $unit['unit_id']);
if(!$session->session)
	$session->create($user->user_code);
$_SESSION['session'] = serialize($session);

debug($unit);

$type = $unit['type'];
if(!in_array($type, array('Survey','Break','Email','External','Page','Branch','End'))) die('imp type');

require_once INCLUDE_ROOT . "Model/$type.php";
$unit = new $type($session,$unit);
$output = $unit->exec();


if($output):
	require_once INCLUDE_ROOT . 'view_header.php';
	echo $site->renderAlerts();

	echo $output;
	require_once INCLUDE_ROOT . 'view_footer.php';
endif;