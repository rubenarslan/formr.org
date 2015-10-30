<div class="monkey_bar">
	<form class="form-inline form-ajax" action="<?php echo moneybar_url($run->name, 'ajax_send_to_position'); ?>" method="post">
		<span class="input-group">
			<span class="input-group-btn">
				<a class="btn hastooltip" href="<?php echo site_url($run->name . '/?code=' . urlencode($user->user_code)); ?>" title="Link to this session (copy & share to debug)"><i class="fa <?= $icon ?>"></i><small> <?php echo $short_code; ?></small></a>
				<button class="btn monkey hastooltip" disabled type="button" title="Monkey mode: fill out all form fields with nonsense values"><i class="fa fa-check-square-o"></i></button>
				<a class="btn hastooltip link-ajax <?= $disable_class ?>" href="<?php echo moneybar_url($run->name, "ajax_remind?run_session_id={$run_session->id}&amp;session=" . urlencode($user->user_code)); ?>" title="Send yourself a reminder (if you already gave an email address)"><i class="fa fa-bullhorn"></i></a>
				<a href="<?php echo moneybar_url($run->name, "ajax_next_in_run?run_session_id={$run_session->id}&amp;session=" . urlencode($user->user_code)); ?>" class="btn hastooltip <?= $disable_class ?> link-ajax refresh_on_success unpause_now" title="Go to next step in run (unpause/skip)"><i class="fa fa-play"></i></a>
				<button type="submit" class="btn hastooltip refresh_on_success <?= $disable_class ?>" title="Send this user to that position"><i class="fa fa-hand-o-right"></i></button>
			</span>

			<input type="hidden" name="session" value="<?php echo $user->user_code;?>">
			<input type="number" name="new_position" value="<?php echo $run_session->position; ?>" class="form-control position_monkey">
			<span class="input-group-btn link-ajax-modal">
				<a class="btn hastooltip refresh_on_success <?= $disable_class; ?>" data-toggle="modal" data-target="#confirm-delete" href="javascript:void(0);" data-href="<?php echo moneybar_url($run->name, "ajax_delete_user?run_session_id={$run_session->id}&amp;session=" . urlencode($user->user_code)); ?>" data-location="<?= site_url() ?>" title="Delete this user and all their data (you will have to confirm)"><i class="fa fa-trash-o"></i></a>
			</span>
		</span>
	</form>
</div>

<div class="modal fade refresh_on_success" id="confirm-delete" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">Reset your test user?</div>
			<div class="modal-body">
				Are you sure you want to reset your current session and all of your data in this run?
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default cancel" data-dismiss="modal">Cancel</button>
				<a href="javascript:void(0);" class="btn btn-danger danger" data-dismiss="modal">Reset</a>
			</div>
		</div>
	</div>
</div>