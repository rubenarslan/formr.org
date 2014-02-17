<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "Model/Run.php";
$service_message_id = $run->getServiceMessageId();

$js = '<script src="'.WEBROOT.'assets/run.js"></script>';

require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";
?>
<div class="row">

	
	<div class="col-lg-8 col-md-10 col-sm-11 col-lg-offset-1 single_unit_display">
		<form class="form-horizontal" enctype="multipart/form-data"  id="edit_run" name="edit_run" method="post" action="<?=WEBROOT?>admin/run/<?=$run->name ;?>" data-units='<?php
			echo json_encode(array(array("unit_id" => $service_message_id) ) );
			?>'>
	<h2><i class="fa fa-eject"></i> Edit service message</h2>
	<ul class="fa-ul fa-ul-more-padding">
		<li><i class="fa-li fa fa-cog fa-lg fa-spin"></i> If you are making changes to your run, while it's live, you may want to keep your users from using it at the time. <br>Use this message to let them know that the run will be working again soon.</li>
		<li><i class="fa-li fa fa-lg fa-stop"></i> You can also use this message to end a study, so that no new users will be admitted and old users who are not finished cannot go on.</li>
	</ul>
	<div id="run_dialog_choices">
	</div>
</form>

	</div>
	
</div>

<?php
require_once INCLUDE_ROOT . "View/footer.php";