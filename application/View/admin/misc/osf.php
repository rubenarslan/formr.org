<?php
Template::load('header');
Template::load('acp_nav');
?>
<div class="row">
	<h2>OSF-API UI</h2>
	<div class="col-md-6">
		
		<div class="panel panel-default" id="panel1">
			<div class="panel-heading">
				<h4 class="panel-title">
					<a data-toggle="collapse" data-target="#collapseOne"  href="#collapseOne"> Test uploading Run file to OSF </a>
				</h4>

			</div>
			<div id="collapseOne" class="panel-collapse collapse in">
				<div class="panel-body">
					<form method="post" action="<?php echo admin_url('osf'); ?>">
						<pre>
Access Token: <?= $token['access_token'] ?><br />
Expires: <?= date('r', $token['expires']) ?>
						</pre>
						<div class="form-group small-left">
							<label class="control-label sr-only" for="password">Select run to upload</label>
							<div class="controls">
								<label class="form-label">Select run to upload to OSF</label>
								<select name="run" class="form-control">
									<?php foreach ($runs as $run): ?>
										<option><?= $run['name'] ?> </option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="controls">
								<label class="form-label">Select OSF project to upload to</label>
								<select name="osf_project" class="form-control">
									<?php foreach ($osf_projects as $project): ?>
										<option><?= $project ?> </option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="controls">
								<hr />
								<button type="submit" class="btn btn-primary btn-large">Upload</button>
							</div>
						</div>
					</form>
				</div>	
			</div>
		</div>
		<br />
		
	</div>
</div>

<?php
Template::load('footer');


