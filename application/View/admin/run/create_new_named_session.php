<?php
Template::load('header');
Template::load('acp_nav');
?>
<div class="row">
	<div class="col-lg-5 col-md-6 col-sm-8">
		<div class="col-lg-12 transparent_well">

		<h2><i class="fa fa-ticket"></i> Add user</h2>
		<form method="post" action="<?= admin_run_url($run->name, 'create_new_named_session') ?>">
			<div class="form-group">
				<label class="control-label" for="code_name">Choose an identifier/cipher/code name for the user you want to add (if you leave this empty, an entirely random code name will be created).<br>
					You can only use a-Z, 0-9, _ and -.</label>
				<div class="controls">
					<div class="input-group">
					  <span class="input-group-addon"><i class="fa fa-tag"></i></span>
			  			<input class="form-control" name="code_name" id="code_name" type="text" autocomplete="off" placeholder="code name"></label>
					</div>
				</div>
			</div>
	
			<div class="form-group small-left">
				<div class="controls">
					<button name="add_user" class="btn btn-default btn-danger hastooltip" type="submit"><i class="fa fa-ticket fa-fw"></i> Add user</button>
				</div>
			</div>
	
	
		</form>

		</div>
	</div>
</div>

<?php Template::load('footer');
