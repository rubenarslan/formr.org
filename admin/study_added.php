<?php
require_once '../define_root.php';
unset($_SESSION['study_id']);
unset($_GET['study_id']);
require_once INCLUDE_ROOT . "config/config.php";
require_once INCLUDE_ROOT . "Model/StudyX.php";


$ok = false;
$errors = $messages = array();

if(!userIsAdmin()) 
{
	header("Location: ../index.php");
	exit;
}
elseif(empty($_POST))
{
	header("Location: ../acp/add_study.php");
	exit;
}

require_once INCLUDE_ROOT.'view_header.php';
?>
<ul class="nav nav-tabs">
	<li><a href="<?=WEBROOT?>acp/acp.php">Zum Admin-Überblick</a></li>
</ul>

<?php


if (empty($errors) AND !isset($_FILES['uploaded'])) {
	$errors[] = 'No file';
}
elseif(empty($errors))
{
	umask(0002);
	ini_set('memory_limit', '256M');
	$target = "upload/"; // todo: simply use temp name instead of moving to a folder so that permissions need to be set?
	$target = $target . basename( $_FILES['uploaded']['name']) ;
	if (file_exists($target)) 
	{
	  rename($target,$target . "-overwritten-" . date('Y-m-d-H:m'));
	  $messages[] = "Eine Datei mit gleichem Namen existierte schon und wurde unter " . $target . "-overwritten-" . date('Y-m-d-H:m') . " gesichert.<br />";
	}
	if(!move_uploaded_file($_FILES['uploaded']['tmp_name'], $target)) 
	{
		$errors[] = "Sorry, es gab ein Problem bei dem Upload.<br />";
		var_dump($_FILES);
	} else {
		$messages[] = "Datei $target wurde hochgeladen<br />";
		$ok = true;
	}
}



// Leere / erstelle items
if ($ok):	
	require_once INCLUDE_ROOT.'Model/SpreadsheetReader.php';
	
	$SPR = new SpreadsheetReader();
	$data = $SPR->readItemTableFile($target);
	$errors = $errors + $SPR->errors;
	$messages = $messages + $SPR->messages;
	
endif;

if(empty($errors))
{
	
	if(!isset($_POST['study_id'])) // a new study is being created
	{
		$study = new StudyX(null, array(
			'name' => $_POST['name'],
			'user_id' => $currentUser->id
		));
	}
	elseif(isset($_POST['study_id'])) // an existing study is being modified
	{
		$messages[] = 'Existing study is being modified.';
		$study = new StudyX($_POST['study_name']);
	}

	if(!$study->valid)
	{
		$errors[] = 'Study is broken.';
	}
	
    if (empty($study->errors) AND $study->insertItems($data) AND $study->createResultsTable($data)) 
	{
		echo "
			<div class='alert alert-success'><strong>Erfolg!</strong> Studie wurde erstellt!</div>
		<div><a class='btn btn-large btn-success' href='".WEBROOT."admin/{$study->name}/index'>"._('Zur Studie').'</a></div>';
    }
    else
	{
		$errors = $errors + $study->errors;
		$messages = $messages + $study->messages;
	}
}

if(!empty($errors))
{
	echo "<h1 style='color:red'>Fehler:</h1>
	<ul><li>";
	echo implode("</li><li>",$errors);
	echo "</li></ul>";
}
if(isset($messages)):
	echo "<h3>Meldungen:</h3>
	<ul><li>";
	echo implode("</li><li>",$messages);
	echo "</li></ul>";
endif;

if(isset($data)):?>
	<pre style="overflow:scroll;height:100px;">
	<?php
	var_dump($data);
	?>
	</pre>
	<?php
else:
	echo "<h2>Nothing imported</h2>";
endif;
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require_once INCLUDE_ROOT.'view_footer.php';
