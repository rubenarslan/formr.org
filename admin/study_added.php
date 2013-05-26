<?php
unset($_SESSION['study_id']);
unset($_GET['study_name']);
require_once '../define_root.php';
require_once INCLUDE_ROOT . "admin/admin_header.php";

$errors = $messages = array();

if(empty($_POST))
{
	alert('<strong>Info:</strong> Please choose your item table file here.','alert-info');
	redirect_to("acp/add_study.php");
}
elseif (!isset($_FILES['uploaded']) OR !isset($_POST['study_name'])) 
{
	alert('<strong>Error:</strong> You have to select an item table file here.','alert-error');
	redirect_to("acp/add_study.php");
}

if(isset($_POST['study_id']))
{
	$messages[] = 'Existing study is being modified.';
	$study = new Study($fdb,null,array('unit_id' => $_POST['study_id']));
	if(!$user->created($study))
		$errors[] = "You don't own this study.";
}
else  // a new study is being created
{
	$study = new Study($fdb, null, array(
		'name' => $_POST['study_name'],
		'user_id' => $user->id
	));
	$study->create();
}

if(!$study->valid)
{
	$errors = $errors + $study->errors;
}

if (empty($errors)):	
	umask(0002);
	ini_set('memory_limit', '256M');
	$target = $_FILES['uploaded']['tmp_name'];
#	$target = "upload/";
#	$target = $target . basename( $_FILES['uploaded']['name']);

#	if (file_exists($target)) 
#	{
#	  rename($target,$target . "-overwritten-" . date('Y-m-d-H:m'));
#	  $messages[] = "Eine Datei mit gleichem Namen existierte schon und wurde unter " . $target . "-overwritten-" . date('Y-m-d-H:m') . " gesichert.";
#	}

#	if(!move_uploaded_file($_FILES['uploaded']['tmp_name'], $target)) 
#	{
#		$errors[] = "Sorry, es gab ein Problem bei dem Upload.";
#		var_dump($_FILES);
#	} else 
#	{
		$messages[] = "Datei $target wurde hochgeladen";
#	}
endif;


// Leere / erstelle items
if (empty($errors)):
	require_once INCLUDE_ROOT.'Model/SpreadsheetReader.php';
	
	$SPR = new SpreadsheetReader();
	$data = $SPR->readItemTableFile($target);
	$errors = $errors + $SPR->errors;
	$messages = $messages + $SPR->messages;
	
endif;

if (empty($errors)):

    if (empty($study->errors) AND $study->insertItems($data) AND $study->createResultsTable($data)) 
	{
		alert('<strong>Success!</strong> Study created!','alert-success');
		$study_link = "<div><a class='btn btn-large btn-success' href='".WEBROOT."admin/{$study->name}/show_item_table'>"._('Check item table').'</a></div>';
		$messages = $messages + $study->messages;
    }
    else
	{
		$errors = $errors + $study->errors;
		$messages = $messages + $study->messages;
	}
endif;

require_once INCLUDE_ROOT.'view_header.php';

if(!empty($messages))
	alert('<ul><li>' . implode("</li><li>",$messages).'</li></ul>','alert-info');

if(!empty($errors)):
	alert('<ul><li>' . implode("</li><li>",$errors).'</li></ul>','alert-error');
	require_once INCLUDE_ROOT.'acp/acp_nav.php';
	echo '<div class="span8">';
else:
	require_once INCLUDE_ROOT.'admin/admin_nav.php';
	echo '<div class="span8">';
	echo $study_link;
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
echo '</div>';
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require_once INCLUDE_ROOT.'view_footer.php';
