<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT.'View/admin_header.php';

$users = $run->getNumberOfSessionsInRun();
require_once INCLUDE_ROOT.'View/header.php';
require_once INCLUDE_ROOT.'View/acp_nav.php';
$overview_script = $run->getOverviewScript();

session_over($site, $user);
$report =  $overview_script->parseBodySpecial();
?>
<div class="row">
	<div class="col-lg-12">

		<h1><i class="fa fa-eye"></i> Run overview <small><?=$overview_script->title?></small></h2>
		
		<?php
		echo $report;
		?>
		
	</div>
</div>

<?php
require_once INCLUDE_ROOT.'View/footer.php';
