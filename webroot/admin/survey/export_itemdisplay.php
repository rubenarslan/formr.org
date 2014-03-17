<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT.'View/admin_header.php';

$results = $study->getItemDisplayResults();

require_once INCLUDE_ROOT.'Model/SpreadsheetReader.php';

$SPR = new SpreadsheetReader();

if(isset($_GET['format']) AND !in_array($_GET['format'], $SPR->exportFormats)) die("invalid format");
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


