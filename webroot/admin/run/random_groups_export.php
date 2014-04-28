<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT.'View/admin_header.php';
session_over($site, $user);

$g_users = $run->getRandomGroups();

$users = array();
while($userx = $g_users->fetch(PDO::FETCH_ASSOC))
{
	unset($userx['run_name']);
	unset($userx['unit_type']);
	unset($userx['ended']);
	unset($userx['position']);
#	$user['body'] = "<small title=\"{$user['body']}\">". substr($user['body'],0,50). "…</small>";
	
	$users[] = $userx;
}
require_once INCLUDE_ROOT.'Model/SpreadsheetReader.php';

$SPR = new SpreadsheetReader();

if(!isset($_GET['format']) OR !in_array($_GET['format'], $SPR->exportFormats)):
	alert("Invalid format requested.","alert-danger");
	bad_request();
endif;
$format = $_GET['format'];

if($format == 'xlsx')
	$SPR->exportXLSX($users,"Shuffle_Run_".$run->name);
elseif($format == 'xls')
	$SPR->exportXLS($users,"Shuffle_Run_".$run->name);
elseif($format == 'csv_german')
	$SPR->exportCSV_german($users,"Shuffle_Run_".$run->name);
elseif($format == 'tsv')
	$SPR->exportTSV($users,"Shuffle_Run_".$run->name);
elseif($format == 'json')
	$SPR->exportJSON($users,"Shuffle_Run_".$run->name);
else
	$SPR->exportCSV($users,"Shuffle_Run_".$run->name);


