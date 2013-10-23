<?php
require_once '../../define_root.php';

require_once INCLUDE_ROOT.'View/admin_header.php';


require_once INCLUDE_ROOT . "Model/OpenCPU.php";
$openCPU = new OpenCPU();

require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";

echo "<h2>OpenCPU test</h2>";

echo $openCPU->selftest();

$alerts = $site->renderAlerts();
if(!empty($alerts)):
	echo '<div class="row-fluid"><div class="span8 all-alerts">';
	echo $alerts;
	echo '</div></div>';
endif;
