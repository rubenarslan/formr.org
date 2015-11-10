<?php Template::load('header_nav'); ?>
<?php
	$no_email_settings = array(
		'1' => 'Subscribe to E-mails',
		'+1 week' => 'Unsubscribe for one week',
		'+2 weeks' => 'Unsubscribe for two weeks',
		'+1 month' => 'Unsubscribe for one month',
		'0' => 'Never receive emails'
	);
?>
<div class="row">
	<div class="col-xs-12">
		<h2>Survey Settings for "<?php echo $run->name; ?>"</h2>
		<form class="" id="login" name="login" method="post" action="">
			<div class="form-group">
				<table class="table table-striped table-responsive">
					<thead>
						<tr>
							<th>Setting</th>
							<th>&nbsp;</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><b>Email subscription</b></td>
							<td>
								<select name="no_email">
									<?php 
									foreach ($no_email_settings as $key => $value): 
										$selected = (array_val($settings, 'no_email') === $key) ? 'selected="selected"' : '';
										echo '<option value="'.$key.'" '.$selected.'>'.$value.'</option>';
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
					<tfoot>
						<tr>
							<td colspan="2">
								<button class="btn btn-default">Save</button>
							</td>
						</tr>
					</tfoot>
				</table>
			</div>
		</form>
	</div>
</div>
<?php
Template::load('footer');
