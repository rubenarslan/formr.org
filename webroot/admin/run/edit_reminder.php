<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "Model/Run.php";
$reminder_email_id = $run->getReminderId();

$js = '<script src="'.WEBROOT.'assets/run.js"></script>';

require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";
?>
<div class="row">

	
	<div class="col-lg-8 col-md-10 col-sm-11 col-lg-offset-1 single_unit_display">
		<form class="form-horizontal" enctype="multipart/form-data"  id="edit_run" name="edit_run" method="post" action="<?=WEBROOT?>admin/run/<?=$run->name ;?>" data-units='<?php
			echo json_encode(array(array("run_unit_id" => $reminder_email_id) ) );
			?>'>
	<h2><i class="fa fa-bullhorn"></i> Edit email reminder</h2>
	<p class="lead">
		Modify the text of a reminder, which you can then send to any user using the <i class="fa fa-bullhorn"></i> reminder button in the <a href="<?=WEBROOT?>admin/run/<?=$run->name ;?>/user_overview">user overview</a>.
	</p>
	<div id="run_dialog_choices">
	</div>
</form>

	</div>
	
</div>

<?php
require_once INCLUDE_ROOT . "View/footer.php";