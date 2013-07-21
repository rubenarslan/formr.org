<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT.'admin/admin_header.php';

$results = $study->getResults();

require_once INCLUDE_ROOT.'Model/SpreadsheetReader.php';

$SPR = new SpreadsheetReader();
$SPR->exportTSV($results,$study->name);