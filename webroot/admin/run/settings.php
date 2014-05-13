<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "Model/Run.php";

$js = '<script src="'.WEBROOT.'assets/run.js"></script>';

require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";
?>
<div class="row">
	<form class="form-horizontal" enctype="multipart/form-data"  id="run_settings" method="post" action="<?=WEBROOT?>admin/run/<?=$run->name ;?>/settings">
	
	<div class="col-md-7 run_dialog">
	
		<ul class="nav nav-tabs">
		  <li class="active"><a href="#settings" data-toggle="tab">Settings</a></li>
		  <li><a href="#css" data-toggle="tab">CSS</a></li>
		  <li><a href="#js" data-toggle="tab">JS</a></li>
		</ul>
	
		<div class="tab-content">
			<div class="tab-pane fade in active" id="settings">
				
				<label class="col-lg-12"> <span title="Will be shown on every page of the run">Title</span>:
				<input type="text" maxlength="1000" placeholder="Title" name="Title" class="form-control">
				</label>
				
				<label class="col-lg-12"> <span title="Link to your header image, shown on every run page">Header image</span>:
				<input type="text" maxlength="255" placeholder="Title" name="header_image_path" class="form-control">
				</label>

				<label class="col-lg-12"> <span title="Will be shown on every page of the run">Description</span>:
				<textarea data-editor="markdown" placeholder="Description" name="description" rows="10" cols="80" class="big_ace_editor form-control"></textarea>
				</label>

				<label class="col-lg-12"> <span title="Will be shown on every page of the run, good for contact info">Footer text</span>:
				<textarea data-editor="markdown" placeholder="Footer text" name="footer_text" rows="10" cols="80" class="big_ace_editor form-control"></textarea>
				</label>
				
				<label class="col-lg-12"> <span title="This will be the description of your study shown on the public page">Public blurb</span>:
				<textarea data-editor="markdown" placeholder="Blurb" name="public_blurb" rows="10" cols="80" class="big_ace_editor form-control"></textarea>
				</label>
				
			</div>
			<div class="tab-pane fade" id="css">
				<textarea data-editor="css" placeholder="Enter your custom CSS here" name="custom_css" rows="40" cols="80" class="big_ace_editor form-control"></textarea>
			</div>
			<div class="tab-pane fade" id="js">
				<textarea data-editor="javascript" placeholder="Enter your custom JS here" name="custom_js" rows="40" cols="80" class="big_ace_editor form-control"></textarea>
			</div>
		</div>
	</div>


	<div class="col-md-5 pull-right well transparent_well">
<?php
require INCLUDE_ROOT.'View/run_settings_help.php';	
?>
	</div>


	<div class="clearfix"></div>
	</form>
</div>
<?php
  require_once INCLUDE_ROOT . "View/footer.php";
