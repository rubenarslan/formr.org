<?php
$js = '<script src="' . asset_url('assets/' . (DEBUG ? 'js' : 'minified') . '/run_users.js') . '"></script>';
Template::load('header', array('js' => $js));
Template::load('acp_nav');
?>

<div class="row">
	<div class="col-md-12">
		<h1 class="drop_shadow">user overview <small><?= $pagination->maximum ?> users</small></h1>
		<p class="lead">
			Here you can see users' progress (on which station they currently are).
			If you're not happy with their progress, you can send manual reminders, <a href="<?= admin_run_url($run->name, 'settings#reminder') ?>">customisable here</a>. <br>You can also shove them to a different position in a run if they veer off-track.
		</p>
		<p>
			Participants who have been stuck at the same survey, external link or email for 2 days or more are highlighted in yellow at the top. Being stuck at an email module usually means that the user somehow ended up there without a valid email address, so that the email cannot be sent. Being stuck at a survey or external link usually means that the user interrupted the survey/external part before completion, you probably want to remind them manually (if you have the means to do so).
		</p>
		<p>
			You can manually create <a href="<?=admin_run_url($run->name, 'create_new_test_code'); ?>">new test users</a> or <a href="<?=admin_run_url($run->name, 'create_new_named_session'); ?>">real users</a>. Test users are useful to test your run. They are like normal users, but have animal names to make them easy to re-identify and you get a bunch of tools to help you fill out faster and skip over pauses. You may want to create real users if a) you want to send users a link containing an identifier to link them up with other data sources b) you are manually enrolling participants, i.e. participants cannot enrol automatically. The identifier you choose will be displayed in the table below, making it easier to administrate users with a specific cipher/code.
		</p>
		<div class="row col-md-12">
			<form action="<?php echo admin_run_url($run->name, 'user_overview'); ?>" method="get" accept-charset="utf-8">

				<div class="row">
					<div class="col-lg-3">
						<div class="input-group">
							<span class="input-group-addon"><i class="fa fa-user"></i></span>
							<input type="search" placeholder="Session key" name="session" class="form-control" value="<?= isset($_GET['session']) ? h($_GET['session']) : ''; ?>">

						</div><!-- /input-group -->
					</div><!-- /.col-lg-6 -->
					<div class="col-lg-3">
						<div class="input-group">
							<span class="input-group-addon"><i class="fa fa-flag-checkered"></i></span>
							<input type="number" placeholder="Position" name="position" class="form-control round_right" value="<?= isset($_GET['position']) ? h($_GET['position']) : ''; ?>">

						</div><!-- /input-group -->
					</div><!-- /.col-lg-6 -->

					<div style="width:65px; float:left">
						<select class="form-control" name="position_lt">
							<option value="=" <?= ($position_lt == '=') ? 'selected' : ''; ?>>=</option>
							<option value="&lt;" <?= ($position_lt == '<') ? 'selected' : ''; ?>>&lt;</option>
							<option value="&gt;" <?= ($position_lt == '>') ? 'selected' : ''; ?>>&gt;</option>
						</select>

					</div>


					<div class="col-lg-1">
						<div class="input-group">
							<input type="submit" value="Search" class="btn">

						</div><!-- /input-group -->
					</div><!-- /.col-lg-6 -->
				</div><!-- /.row -->

			</form>
		</div>
		
		<table class="table table-striped">
			<thead>
				<tr>
					<th>Run position</th>
					<th>Session</th>
					<th>Created</th>
					<th>Last Access</th>
					<th>Action</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($users as $user): ?>
				<tr>
					<td>
						<span class="hastooltip" title="Current position in run"><?php echo $user['position']; ?></span> – <small><?php echo $user['unit_type']; ?></small>
					</td>
					<td>
						<?php if ($currentUser->user_code == $user['session']): ?>
							<i class="fa fa-user-md" class="hastooltip" title="This is you"></i>
						<?php endif; 
						
						$animal_end = strpos($user['session'], "XXX");
						if ($animal_end === false) {
							$animal_end = 10;
						}
						$short_session = substr($user['session'], 0, $animal_end); 
						?>
						<small><abbr class="abbreviated_session" title="Click to show the full session" data-full-session="<?php echo $user['session']; ?>"><?php echo $short_session ?>…</abbr></small>
					</td>
					<td>
						<small><?php echo $user['created']; ?></small>
					</td>
					<td>
						<small class="hastooltip" title="<?php echo $user['last_access']; ?>"> <?php echo timetostr(strtotime($user['last_access'])); ?></small>
					</td>
					<td>
						<form class='form-inline form-ajax' action="<?php echo admin_run_url($user['run_name'], 'ajax_send_to_position'); ?>" method='post'>
							<span class='input-group'>
								<span class='input-group-btn'>
									<a class="btn hastooltip" href="<?php echo site_url($user['run_name'] . "?code=" . urlencode($user['session'])); ?>" title="<?=($user['testing']?'Test using this identity (open in incognito window to have a separate session)':'Pretend you are this user (you will really manipulate this data)');?>"><i class="fa fa-user-secret"></i></a>
									
									<a class='btn hastooltip link-ajax' href='<?= site_url("admin/run/{$user['run_name']}/ajax_toggle_testing?toggle_on=".($user['testing']?0:1)."&amp;run_session_id={$user['run_session_id']}&amp;session=".urlencode($user['session']))?>' 
									title='Toggle testing status'><i class='fa <?=($user['testing']?'fa-stethoscope':'fa-heartbeat')?>'></i></a>
									
									<a class='btn hastooltip link-ajax' href="<?php echo admin_run_url($user['run_name'], "ajax_remind?run_session_id={$user['run_session_id']}&amp;session=" . urlencode($user['session'])); ?>" title="Remind this user"><i class="fa fa-bullhorn"></i></a>
									<button type="submit" class="btn hastooltip" title="Send this user to that position"><i class="fa fa-hand-o-right"></i></button>
								</span>
								<input type="hidden" name="session" value="<?php echo $user['session']; ?>" />
								<input type="number" name="new_position" value="<?php echo $user['position']; ?>" class="form-control position_monkey"/>
								<span class='input-group-btn link-ajax-modal'>
									<a class="btn hastooltip" data-toggle="modal" data-target="#confirm-delete" href="javascript:void(0)" data-href="<?php echo admin_run_url($user['run_name'], "ajax_delete_user?run_session_id={$user['run_session_id']}&amp;session=" . urlencode($user['session'])); ?>" title="Delete this user and all their data (you'll have to confirm)"><i class='fa fa-trash-o'></i></a>
								</span>
							</span>
						</form>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		
		<?php
			$append = !empty($querystring) ? '?' . http_build_query($querystring) . '&' : '';
			$pagination->render(admin_run_url($run->name, 'user_overview' . $append));
		?>
	</div>
</div>

<div class="modal fade" id="confirm-delete" tabindex="-1" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                Delete this user
            </div>
            <div class="modal-body">
                Are you sure you want to delete this user and all of their data in this run?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default cancel" data-dismiss="modal">Cancel</button>
                <a href="#" class="btn btn-danger danger" data-dismiss="modal">Delete</a>
            </div>
        </div>
    </div>
</div>

<?php
Template::load('footer');
