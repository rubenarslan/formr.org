<?php
Template::load('header');
Template::load('acp_nav');

$users = $run->getNumberOfSessionsInRun();
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

<?php Template::load('footer');
