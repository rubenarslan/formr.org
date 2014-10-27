<?php
session_over($site, $user);

$results = $study->getItemDisplayResults();
if(!count($results))
{
	die( "Nothing to export");
}

$SPR = new SpreadsheetReader();

if(!isset($_GET['format']) OR !in_array($_GET['format'], $SPR->exportFormats)):
	alert("Invalid format requested.","alert-danger");
	bad_request();
endif;
$format = $_GET['format'];

if($format == 'xlsx')
	$SPR->exportXLSX($results,$study->name."_itemdisplay");
elseif($format == 'xls')
	$SPR->exportXLS($results,$study->name."_itemdisplay");
elseif($format == 'csv_german')
	$SPR->exportCSV_german($results,$study->name."_itemdisplay");
elseif($format == 'tsv')
	$SPR->exportTSV($results,$study->name."_itemdisplay");
elseif($format == 'json')
	$SPR->exportJSON($results,$study->name."_itemdisplay");
else
	$SPR->exportCSV($results,$study->name."_itemdisplay");


