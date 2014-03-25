<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT.'View/admin_header.php';

$results = $study->getResults();

require_once INCLUDE_ROOT.'Model/SpreadsheetReader.php';

$SPR = new SpreadsheetReader();

if(!isset($_GET['format']) OR !in_array($_GET['format'], $SPR->exportFormats)):
	alert("Invalid format requested.","alert-danger");
	bad_request();
endif;
$format = $_GET['format'];

if($format == 'xlsx')
	$SPR->exportXLSX($results,$study->name);
elseif($format == 'xls')
	$SPR->exportXLS($results,$study->name);
elseif($format == 'csv_german')
	$SPR->exportCSV_german($results,$study->name);
elseif($format == 'tsv')
	$SPR->exportTSV($results,$study->name);
elseif($format == 'json')
	$SPR->exportJSON($results,$study->name);
else
	$SPR->exportCSV($results,$study->name);


