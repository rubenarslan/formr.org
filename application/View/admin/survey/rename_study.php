<?php
Template::load('header');
Template::load('acp_nav');
?>
<div class="row">
	<div class="col-lg-6 col-md-6 col-sm-8 ">
		
		<div class="transparent_well col-md-12" style="padding-bottom: 20px;">

			<h2>Rename study</h2>
			<?php
			if(isset($msg)) echo '<div class="alert '.$alertclass.' span6">'.$msg.'</div>';
			?>
			<form method="post" action="<?=WEBROOT?>admin/survey/<?=$study->name?>/rename_study">
				<div class="form-group">
					<label class="control-label" for="new_name">Choose a new name for your study</label>
					<div class="controls">
						<div class="input-group">
						  <span class="input-group-addon"><i class="fa fa-unlock"></i></span>
				  			<input class="form-control" required name="new_name" id="new_name" type="text" autocomplete="off" value="<?=$study_name?>"></label>
						</div>
					</div>
				</div>
	
				<div class="form-group small-left">
					<div class="controls">
						<button name="rename" class="btn btn-default btn-danger hastooltip" type="submit"><i class="fa fa-unlock fa-fw"></i> Rename this study</button>
					</div>
				</div>
	
	
			</form>

		</div>
	</div>
</div>

<?php
Template::load('footer');
