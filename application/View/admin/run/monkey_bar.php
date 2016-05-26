<div class="monkey_bar">
	<div class="text-center">
				<a class="hastooltip label label-default" href="<?php echo site_url($run->name . '/?code=' . urlencode($user->user_code)); ?>" title="Link to this session (copy &amp; share to debug)"><i class="fa <?= $icon ?>"></i> <?php echo $short_code; ?> (<?=$run_session->position; ?>)</a>
	</div>
	<form class="form-inline form-ajax" action="<?php echo monkeybar_url($run->name, 'ajax_send_to_position'); ?>" method="post">
		<span class="input-group">
			<span class="input-group-btn">
				<button class="btn monkey hastooltip" disabled type="button" title="Monkey mode: fill out all form fields with nonsense values"><i class="fa fa-check-square-o"></i></button>
				<button class="btn hastooltip show_hidden_items" disabled type="button" title="Show hidden items (will disappear again once you click items)"><i class="fa fa-lightbulb-o"></i></button>
				<button class="btn hastooltip show_hidden_debugging_messages" disabled type="button" title="Show hidden debugging messages"><i class="fa fa-search"></i></button>
				<a href="<?php echo monkeybar_url($run->name, "ajax_next_in_run?run_session_id={$run_session->id}&amp;session=" . urlencode($user->user_code)); ?>" class="btn hastooltip <?= $disable_class ?> link-ajax refresh_on_success unpause_now" title="Go to next step in run (unpause/skip)"><i class="fa fa-play"></i></a>
				<button type="submit" class="btn hastooltip refresh_on_success <?= $disable_class ?>" title="Send this user to that position"><i class="fa fa-hand-o-right"></i></button>
			</span>

			<input type="hidden" name="session" value="<?php echo $user->user_code;?>">
			<select name="new_position" class="form-control position_monkey " <?= $disable_class ?>>
			<?php foreach($run->getAllUnitTypes() AS $run_unit): ?>
				<option value="<?=$run_unit['position']?>" <?=($run_unit['position'] === $run_session->position) ? 'selected' : '' ?>>
					<?=$run_unit['position'] . " - (" . $run_unit['type'] . ") " . $run_unit['description']?>
				</option>
			<?php endforeach; ?>
			</select>
			<span class="input-group-btn link-ajax-modal">
				<a class="btn hastooltip refresh_on_success <?= $disable_class; ?>" data-toggle="modal" data-target="#confirm-snip" href="javascript:void(0);" data-href="<?php echo monkeybar_url($run->name, "ajax_snip_unit_session?run_session_id={$run_session->id}&amp;session=" . urlencode($user->user_code)); ?>" data-location="<?= site_url() ?>" title="Remove the current data at this position, start over."><i class="fa fa-scissors"></i></a>
				<a class="btn hastooltip refresh_on_success <?= $disable_class; ?>" data-toggle="modal" data-target="#confirm-delete" href="javascript:void(0);" data-href="<?php echo monkeybar_url($run->name, "ajax_delete_user?run_session_id={$run_session->id}&amp;session=" . urlencode($user->user_code)); ?>" data-location="<?= site_url() ?>" title="Delete this user and all their data (you will have to confirm)"><i class="fa fa-trash-o"></i></a>
			</span>
		</span>
	</form>
</div>

<div class="modal fade refresh_on_success removal_modal" id="confirm-delete" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">Delete your test user?</div>
			<div class="modal-body">
				Are you sure you want to delete your current session and all of your data in this run? You will start fresh.
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default cancel" data-dismiss="modal">Cancel</button>
				<a href="javascript:void(0);" class="btn btn-danger danger" data-dismiss="modal">Delete everything</a>
			</div>
		</div>
	</div>
</div>


<div class="modal fade refresh_on_success removal_modal" id="confirm-snip" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">Snip some data</div>
			<div class="modal-body">
				The data that this user entered at the current position will be deleted!
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default cancel" data-dismiss="modal">Cancel</button>
				<a href="javascript:void(0);" class="btn btn-danger danger" data-dismiss="modal">Snip</a>
			</div>
		</div>
	</div>
</div>