<?php 
	Template::load('public/header', array(
		'headerClass' => 'fmr-small-header',
	)); 
?>

<section id="fmr-hero" class="js-fullheight full">
	<div class="fmr-overlay"></div>
	<div class="container">
		<div class="fmr-intro js-fullheight">
			<div class="row">
				<div class="fmr-intro-text">
					<div class="col-md-12">
						<div class="login-form mdl-card mdl-shadow--2dp">
							<h2>Settings for '<?php echo $run->name; ?>'</h2>
							<?php Template::load('public/alerts'); ?>

							<form action="" method="post">
								<table class="table table-striped table-responsive">
									<thead>
										<tr>
											<th class="lead">Setting</th>
											<th class="lead">&nbsp;</th>
										</tr>
									</thead>
									<tbody>
										<tr>
											<td><b>Email subscription</b></td>
											<td>
												<select name="no_email">
													<?php
													foreach ($email_subscriptions as $key => $value):
														$selected = ((string) array_val($settings, 'no_email') === (string) $key) ? 'selected="selected"' : '';
														echo '<option value="' . $key . '" ' . $selected . '>' . $value . '</option>';
													endforeach;
													?>
												</select>
											</td>
										</tr>
										<tr>
											<td>
												<b>Delete Survey Session</b> <br />
												<i>Your session cookie will be deleted, so your session will no longer be accessible from this computer, but your data will still be saved.<br>
													To re-activate your session you can use the login link, if you have one.</i>
											</td>
											<td><input type="checkbox" name="delete_cookie" value="1" <?php if (array_val($settings, 'delete_cookie')) echo "checked='checked'"; ?>> </td>
										</tr>
									</tbody>
								</table>
								<input name="_sess" value="<?= htmlentities($settings['code']); ?>" type="hidden" />
								<button class="btn-primary mdl-button mdl-js-button mdl-button--raised mdl-js-ripple-effect"><i class="fa fa-save"></i> Save</button>
							</form>
						</div>
					</div>
				</div>
				<div class="clearfix"></div>
				<p>&nbsp;</p>
			</div>
		</div>
	</div>
	<div class="clear"></div>
</section>

<?php Template::load('footer'); ?>
